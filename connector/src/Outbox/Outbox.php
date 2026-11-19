<?php
/**
 * Repositorio del outbox local: `{prefix}igr_outbox`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Outbox;

use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Json;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tabla propia del plugin; las consultas son preparadas.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Los valores de IN (...) se pasan como arreglo; el sniff no cuenta arreglos.

/**
 * Nada se pierde: cada evento se guarda con un solo INSERT preparado y sale después por lotes.
 */
final class Outbox {

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_DEAD    = 'dead';

	/** Segundos que una fila puede quedar en `sending` antes de recuperarse. */
	public const LEASE_SECONDS = 300;

	/**
	 * Nombre de la tabla.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'igr_outbox';
	}

	/**
	 * SQL de creación (dbDelta).
	 */
	public static function schema(): string {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  event varchar(64) NOT NULL,
  payload longtext NOT NULL,
  status enum('pending','sending','sent','dead') NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  created_at datetime NOT NULL,
  sent_at datetime NULL DEFAULT NULL,
  last_error varchar(255) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY status_next (status,next_attempt_at)
) {$charset};";
	}

	/**
	 * Guarda un evento (sobre completo). Un `event_id` repetido se ignora (idempotencia local).
	 *
	 * @param array<string, mixed> $envelope Sobre del evento.
	 * @return bool true si se insertó.
	 */
	public static function insert( array $envelope ): bool {
		global $wpdb;
		$now    = Dates::mysql_utc();
		$sql    = $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (event_id, event, payload, status, attempts, next_attempt_at, created_at) VALUES (%s, %s, %s, %s, 0, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			(string) $envelope['event_id'],
			(string) $envelope['event'],
			Json::encode( $envelope ),
			self::STATUS_PENDING,
			$now,
			$now
		);
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return 1 === $result;
	}

	/**
	 * Toma eventos pendientes y los marca `sending` con un plazo (lease).
	 *
	 * @param int $limit     Máximo de eventos.
	 * @param int $max_bytes Máximo de bytes sin comprimir.
	 * @return list<array{id:int, event_id:string, event:string, payload:string, attempts:int}>
	 */
	public static function claim( int $limit, int $max_bytes ): array {
		global $wpdb;
		$table = self::table();
		$now   = Dates::mysql_utc();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, event, payload, attempts FROM {$table} WHERE status = %s AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				$now,
				$limit
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array();
		}
		$picked = array();
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$size = strlen( (string) $row['payload'] );
			if ( array() !== $picked && $bytes + $size > $max_bytes ) {
				break;
			}
			$bytes   += $size;
			$picked[] = array(
				'id'       => (int) $row['id'],
				'event_id' => (string) $row['event_id'],
				'event'    => (string) $row['event'],
				'payload'  => (string) $row['payload'],
				'attempts' => (int) $row['attempts'],
			);
		}
		$ids          = array_column( $picked, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, next_attempt_at = %s WHERE status = %s AND id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::STATUS_SENDING, Dates::mysql_utc( time() + self::LEASE_SECONDS ), self::STATUS_PENDING ), $ids )
			)
		);
		return $picked;
	}

	/**
	 * Marca enviados.
	 *
	 * @param list<int> $ids IDs.
	 */
	public static function mark_sent( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, sent_at = %s, last_error = NULL WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::STATUS_SENT, Dates::mysql_utc() ), $ids )
			)
		);
	}

	/**
	 * Marca un evento como muerto con su motivo (rechazo del hub o intentos agotados).
	 *
	 * @param list<int> $ids    IDs.
	 * @param string    $reason Motivo.
	 */
	public static function mark_dead( array $ids, string $reason ): void {
		if ( array() === $ids ) {
			return;
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::STATUS_DEAD, mb_substr( $reason, 0, 255 ) ), $ids )
			)
		);
	}

	/**
	 * Devuelve filas a pendientes con espera exponencial; las que agotan intentos pasan a `dead`.
	 *
	 * @param list<array{id:int, attempts:int}> $rows  Filas tomadas.
	 * @param string                            $error Motivo.
	 */
	public static function mark_retry( array $rows, string $error ): void {
		global $wpdb;
		$table    = self::table();
		$by_count = array();
		foreach ( $rows as $row ) {
			$by_count[ $row['attempts'] + 1 ][] = $row['id'];
		}
		foreach ( $by_count as $attempts => $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$status       = Backoff::exhausted( $attempts ) ? self::STATUS_DEAD : self::STATUS_PENDING;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, attempts = %d, next_attempt_at = %s, last_error = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $status, min( 255, $attempts ), Dates::mysql_utc( time() + Backoff::delay( $attempts ) ), mb_substr( $error, 0, 255 ) ), $ids )
				)
			);
		}
	}

	/**
	 * Devuelve filas a pendientes sin contar un intento (lote partido por tamaño).
	 *
	 * @param list<int> $ids IDs.
	 */
	public static function release( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, next_attempt_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::STATUS_PENDING, Dates::mysql_utc() ), $ids )
			)
		);
	}

	/**
	 * Recupera filas que quedaron en `sending` (proceso interrumpido).
	 */
	public static function recover_stale(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status = %s AND next_attempt_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				self::STATUS_SENDING,
				Dates::mysql_utc()
			)
		);
	}

	/**
	 * Reintento manual de los muertos (Diagnóstico).
	 *
	 * @param list<int>|null $ids IDs o null para todos.
	 */
	public static function retry_dead( ?array $ids = null ): int {
		global $wpdb;
		$table = self::table();
		if ( null === $ids ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::STATUS_PENDING,
					Dates::mysql_utc(),
					self::STATUS_DEAD
				)
			);
		}
		if ( array() === $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s WHERE status = %s AND id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( self::STATUS_PENDING, Dates::mysql_utc(), self::STATUS_DEAD ), $ids )
			)
		);
	}

	/**
	 * Purga: enviados de más de 7 días y muertos de más de 30.
	 */
	public static function purge(): int {
		global $wpdb;
		$table    = self::table();
		$deleted  = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND sent_at < %s LIMIT 10000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_SENT,
				Dates::mysql_utc( time() - 7 * DAY_IN_SECONDS )
			)
		);
		$deleted += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND created_at < %s LIMIT 10000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_DEAD,
				Dates::mysql_utc( time() - 30 * DAY_IN_SECONDS )
			)
		);
		return $deleted;
	}

	/**
	 * Borra los pendientes (al desconectar: llevan el site_id anterior).
	 *
	 * @param bool $all Borrar también enviados y muertos.
	 */
	public static function clear( bool $all = false ): void {
		global $wpdb;
		$table = self::table();
		if ( $all ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN (%s, %s)", self::STATUS_PENDING, self::STATUS_SENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Adelanta los pendientes que esperaban un reintento: quedan listos para enviarse ya (envío forzado desde el admin).
	 *
	 * @return int Eventos adelantados.
	 */
	public static function expedite(): int {
		global $wpdb;
		$table = self::table();
		$now   = Dates::mysql_utc();
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_attempt_at = %s WHERE status = %s AND next_attempt_at > %s", $now, self::STATUS_PENDING, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Conteo por estado.
	 *
	 * @return array{pending:int, sending:int, sent:int, dead:int, oldest_pending_at:?string}
	 */
	public static function stats(): array {
		global $wpdb;
		$table  = self::table();
		$counts = array(
			'pending'           => 0,
			'sending'           => 0,
			'sent'              => 0,
			'dead'              => 0,
			'oldest_pending_at' => null,
		);
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n, MIN(created_at) AS oldest FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
			if ( self::STATUS_PENDING === $row['status'] && null !== $row['oldest'] ) {
				$counts['oldest_pending_at'] = gmdate( 'c', (int) strtotime( $row['oldest'] . ' UTC' ) );
			}
		}
		return $counts;
	}

	/**
	 * Eventos de las últimas 24 h por tipo.
	 *
	 * @return array<string, int>
	 */
	public static function last_24h_by_event(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY event ORDER BY n DESC", Dates::mysql_utc( time() - DAY_IN_SECONDS ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['event'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Últimos eventos muertos (sin el payload: puede tener datos personales).
	 *
	 * @param int $limit Máximo.
	 * @return list<array<string, mixed>>
	 */
	public static function dead( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, event_id, event, attempts, last_error, created_at FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", self::STATUS_DEAD, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_values( (array) $rows );
	}
}
