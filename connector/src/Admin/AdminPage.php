<?php
/**
 * Página de administración de la Fase 0 (renderizada en el servidor).
 *
 * La interfaz React de `admin-app/` (connector/CLAUDE.md §12) la reemplaza más adelante: toda la
 * lógica vive en clases de dominio (ConnectionManager, Runner, Outbox, Diagnostics) y aquí solo
 * se pinta y se despachan acciones, así que la UI nueva solo tendrá que llamar a esas clases
 * por REST.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Admin;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\ConnectionManager;
use ImaginaGrowth\Connector\Diagnostics;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Scheduler;

/**
 * Menú «Imagina Growth» con las pestañas Estado, Diagnóstico y Avanzado.
 */
final class AdminPage {

	public const SLUG       = 'imagina-growth';
	public const CAPABILITY = 'manage_options';

	/**
	 * Registra el menú y las acciones.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'maybe_handle_return' ) );
		foreach ( array( 'connect', 'manual_code', 'disconnect', 'backfill', 'retry_dead', 'flush' ) as $action ) {
			add_action( 'admin_post_igr_' . $action, array( self::class, 'action_' . $action ) );
		}
	}

	/**
	 * Menú.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Imagina Growth', 'imagina-growth' ),
			__( 'Imagina Growth', 'imagina-growth' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-chart-line',
			56
		);
	}

	/**
	 * Regreso del hub (`igr_code` + `igr_nonce`).
	 */
	public static function maybe_handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- El nonce propio del handshake se verifica en ConnectionManager.
		if ( ! isset( $_GET['page'], $_GET['igr_connect'], $_GET['igr_code'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$code  = sanitize_text_field( wp_unslash( (string) $_GET['igr_code'] ) );
		$nonce = isset( $_GET['igr_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['igr_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$result = ConnectionManager::handle_return( $code, $nonce );
		self::redirect( is_wp_error( $result ) ? array( 'igr_error' => rawurlencode( $result->get_error_message() ) ) : array( 'igr_notice' => 'connected' ) );
	}

	/**
	 * Botón «Conectar»: redirige al hub.
	 */
	public static function action_connect(): void {
		self::guard( 'igr_connect' );
		wp_redirect( ConnectionManager::connect_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirección al hub (dominio externo configurado).
		exit;
	}

	/**
	 * Conexión manual con código pegado.
	 */
	public static function action_manual_code(): void {
		self::guard( 'igr_manual_code' );
		$code   = isset( $_POST['igr_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['igr_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifica el nonce.
		$result = ConnectionManager::exchange( $code );
		self::redirect( is_wp_error( $result ) ? array( 'igr_error' => rawurlencode( $result->get_error_message() ) ) : array( 'igr_notice' => 'connected' ) );
	}

	/**
	 * Desconectar.
	 */
	public static function action_disconnect(): void {
		self::guard( 'igr_disconnect' );
		ConnectionManager::disconnect( ! empty( $_POST['igr_wipe'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verifica el nonce.
		self::redirect( array( 'igr_notice' => 'disconnected' ) );
	}

	/**
	 * Iniciar o reiniciar la importación histórica.
	 */
	public static function action_backfill(): void {
		self::guard( 'igr_backfill' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verifica el nonce.
		$from = isset( $_POST['igr_from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['igr_from'] ) ) : '';
		$mode = isset( $_POST['igr_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['igr_mode'] ) ) : 'start';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'pause' === $mode || 'resume' === $mode ) {
			Runner::pause( 'pause' === $mode );
		} else {
			Runner::start( '' !== $from ? $from : null, true );
		}
		self::redirect( array( 'igr_notice' => 'backfill' ) );
	}

	/**
	 * Reintentar los eventos fallidos.
	 */
	public static function action_retry_dead(): void {
		self::guard( 'igr_retry_dead' );
		Outbox::retry_dead();
		Scheduler::async( Scheduler::FLUSH_NOW );
		self::redirect( array( 'igr_notice' => 'retry' ) );
	}

	/**
	 * Enviar ahora (encola el envío: nunca HTTP en la petición del admin).
	 */
	public static function action_flush(): void {
		self::guard( 'igr_flush' );
		Scheduler::async( Scheduler::FLUSH_NOW );
		self::redirect( array( 'igr_notice' => 'flush' ) );
	}

	/**
	 * Pinta la página.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo lectura de la pestaña y avisos.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'status';
		$notice = isset( $_GET['igr_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['igr_notice'] ) ) : '';
		$error  = isset( $_GET['igr_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['igr_error'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'status'      => __( 'Estado', 'imagina-growth' ),
			'diagnostics' => __( 'Diagnóstico', 'imagina-growth' ),
			'advanced'    => __( 'Avanzado', 'imagina-growth' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'status';
		}
		echo '<div class="wrap igr-admin"><h1>' . esc_html__( 'Imagina Growth', 'imagina-growth' ) . '</h1>';
		self::notices( $notice, $error );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div style="max-width:960px;margin-top:16px">';
		if ( 'diagnostics' === $tab ) {
			self::render_diagnostics();
		} elseif ( 'advanced' === $tab ) {
			self::render_advanced();
		} else {
			self::render_status();
		}
		echo '</div></div>';
	}

	/**
	 * Pestaña Estado.
	 */
	private static function render_status(): void {
		if ( ! Connection::is_connected() ) {
			echo '<div class="card" style="max-width:none">';
			echo '<h2>' . esc_html__( 'Conecta tu sitio', 'imagina-growth' ) . '</h2>';
			echo '<p>' . esc_html__( 'Inicia sesión en Imagina Growth, elige el espacio de trabajo y vuelve aquí. Tarda menos de 2 minutos y no necesitas tocar código.', 'imagina-growth' ) . '</p>';
			self::form( 'igr_connect', '<button class="button button-primary button-hero">' . esc_html__( 'Conectar con Imagina Growth', 'imagina-growth' ) . '</button>' );
			echo '<hr><h3>' . esc_html__( '¿Tienes un código de conexión?', 'imagina-growth' ) . '</h3>';
			self::form(
				'igr_manual_code',
				'<input type="text" name="igr_code" placeholder="IGR-XXXX-XXXX-XXXX" class="regular-text" autocomplete="off" required> <button class="button">' . esc_html__( 'Conectar con el código', 'imagina-growth' ) . '</button>'
			);
			echo '</div>';
			return;
		}
		$data      = Connection::data();
		$workspace = is_array( $data['workspace'] ?? null ) ? $data['workspace'] : array();
		$stats     = Outbox::stats();
		$flush     = get_option( Flusher::STATUS_OPTION, array() );
		$flush     = is_array( $flush ) ? $flush : array();
		echo '<table class="widefat striped"><tbody>';
		self::row( __( 'Conexión', 'imagina-growth' ), '<span style="color:#008a20">●</span> ' . esc_html__( 'Conectado', 'imagina-growth' ) );
		self::row( __( 'Espacio de trabajo', 'imagina-growth' ), esc_html( (string) ( $workspace['name'] ?? '—' ) ) );
		self::row( __( 'ID del sitio', 'imagina-growth' ), '<code>' . esc_html( Connection::site_id() ) . '</code>' );
		self::row( __( 'Último envío correcto', 'imagina-growth' ), esc_html( isset( $flush['last_success_at'] ) ? human_time_diff( (int) $flush['last_success_at'] ) . ' ' . __( 'atrás', 'imagina-growth' ) : '—' ) );
		self::row( __( 'Pendientes por enviar', 'imagina-growth' ), esc_html( number_format_i18n( $stats['pending'] + $stats['sending'] ) ) );
		self::row( __( 'Fallidos', 'imagina-growth' ), esc_html( number_format_i18n( $stats['dead'] ) ) );
		if ( ! empty( $flush['last_error'] ) ) {
			self::row( __( 'Último error', 'imagina-growth' ), '<span style="color:#b32d2e">' . esc_html( (string) $flush['last_error'] ) . '</span>' );
		}
		echo '</tbody></table>';

		$events = Outbox::last_24h_by_event();
		if ( array() !== $events ) {
			echo '<h2>' . esc_html__( 'Eventos de las últimas 24 h', 'imagina-growth' ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( $events as $event => $count ) {
				self::row( $event, esc_html( number_format_i18n( $count ) ) );
			}
			echo '</tbody></table>';
		}
		self::render_backfill();
	}

	/**
	 * Progreso de la importación.
	 */
	private static function render_backfill(): void {
		$state = Runner::state();
		echo '<h2>' . esc_html__( 'Importación histórica', 'imagina-growth' ) . '</h2>';
		if ( array() === $state ) {
			echo '<p>' . esc_html__( 'Aún no se ha importado el historial.', 'imagina-growth' ) . '</p>';
			return;
		}
		$labels = array(
			'queued'    => __( 'En cola', 'imagina-growth' ),
			'running'   => __( 'Importando', 'imagina-growth' ),
			'paused'    => __( 'En pausa', 'imagina-growth' ),
			'completed' => __( 'Completada', 'imagina-growth' ),
			'failed'    => __( 'Con error', 'imagina-growth' ),
		);
		$names  = array(
			'products'      => __( 'Productos', 'imagina-growth' ),
			'categories'    => __( 'Categorías', 'imagina-growth' ),
			'customers'     => __( 'Clientes', 'imagina-growth' ),
			'orders'        => __( 'Pedidos', 'imagina-growth' ),
			'refunds'       => __( 'Reembolsos', 'imagina-growth' ),
			'reviews'       => __( 'Reseñas', 'imagina-growth' ),
			'subscriptions' => __( 'Suscripciones', 'imagina-growth' ),
		);
		/* translators: 1: estado, 2: fecha inicial. */
		echo '<p>' . esc_html( sprintf( __( '%1$s · desde %2$s', 'imagina-growth' ), $labels[ $state['status'] ] ?? $state['status'], $state['from'] ?? '—' ) ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( (array) ( $state['entities'] ?? array() ) as $entity => $progress ) {
			$total = (int) ( $progress['total'] ?? 0 );
			$sent  = (int) ( $progress['sent'] ?? 0 );
			$pct   = $total > 0 ? min( 100, (int) floor( $sent * 100 / $total ) ) : 100;
			self::row(
				(string) ( $names[ $entity ] ?? $entity ),
				esc_html( number_format_i18n( $sent ) . ' / ' . number_format_i18n( $total ) ) . ' <progress max="100" value="' . esc_attr( (string) $pct ) . '" style="vertical-align:middle;width:180px"></progress>'
			);
		}
		echo '</tbody></table>';
		if ( ! empty( $state['error'] ) ) {
			echo '<p style="color:#b32d2e">' . esc_html( (string) $state['error'] ) . '</p>';
		}
		if ( ! empty( $state['warning'] ) ) {
			echo '<p style="color:#996800">' . esc_html( (string) $state['warning'] ) . '</p>';
		}
	}

	/**
	 * Pestaña Diagnóstico.
	 */
	private static function render_diagnostics(): void {
		$report = Diagnostics::report();
		$lag    = (int) $report['action_scheduler']['lag_minutes'];
		if ( $lag > 10 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(
				/* translators: %d: minutos de retraso. */
				sprintf( __( 'Las tareas programadas van %d minutos atrasadas.', 'imagina-growth' ), $lag )
			) . '</strong> ' . esc_html__( 'Tu sitio recibe pocas visitas y WP-Cron no corre a tiempo. Pide a tu hosting un cron real cada minuto con uno de estos comandos:', 'imagina-growth' ) . '</p><p><code>wp action-scheduler run --group=imagina-growth</code><br><code>wp cron event run --due-now</code></p></div>';
		}
		echo '<h2>' . esc_html__( 'Outbox', 'imagina-growth' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( array( 'pending', 'sending', 'sent', 'dead' ) as $key ) {
			self::row( $key, esc_html( number_format_i18n( (int) $report['outbox'][ $key ] ) ) );
		}
		self::row( __( 'Pendiente más antiguo', 'imagina-growth' ), esc_html( (string) ( $report['outbox']['oldest_pending_at'] ?? '—' ) ) );
		echo '</tbody></table><p>';
		self::form( 'igr_flush', '<button class="button">' . esc_html__( 'Enviar ahora', 'imagina-growth' ) . '</button>', true );
		if ( (int) $report['outbox']['dead'] > 0 ) {
			self::form( 'igr_retry_dead', '<button class="button">' . esc_html__( 'Reintentar los fallidos', 'imagina-growth' ) . '</button>', true );
		}
		echo '</p>';
		$dead = Outbox::dead( 20 );
		if ( array() !== $dead ) {
			echo '<h3>' . esc_html__( 'Últimos fallidos', 'imagina-growth' ) . '</h3><table class="widefat striped"><thead><tr><th>Evento</th><th>event_id</th><th>Intentos</th><th>Motivo</th></tr></thead><tbody>';
			foreach ( $dead as $row ) {
				printf( '<tr><td>%s</td><td><code>%s</code></td><td>%d</td><td>%s</td></tr>', esc_html( (string) $row['event'] ), esc_html( (string) $row['event_id'] ), (int) $row['attempts'], esc_html( (string) $row['last_error'] ) );
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Informe para soporte', 'imagina-growth' ) . '</h2>';
		echo '<textarea readonly rows="16" style="width:100%;font-family:monospace">' . esc_textarea( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea>';
	}

	/**
	 * Pestaña Avanzado.
	 */
	private static function render_advanced(): void {
		if ( ! Connection::is_connected() ) {
			echo '<p>' . esc_html__( 'Conecta el sitio para ver estas opciones.', 'imagina-growth' ) . '</p>';
			return;
		}
		$state = Runner::state();
		echo '<h2>' . esc_html__( 'Importación histórica', 'imagina-growth' ) . '</h2>';
		self::form(
			'igr_backfill',
			'<label>' . esc_html__( 'Desde', 'imagina-growth' ) . ' <input type="date" name="igr_from" value="' . esc_attr( (string) ( $state['from'] ?? '' ) ) . '"></label> <input type="hidden" name="igr_mode" value="start"><button class="button">' . esc_html__( 'Importar de nuevo', 'imagina-growth' ) . '</button>'
		);
		if ( in_array( $state['status'] ?? '', array( 'queued', 'running', 'paused' ), true ) ) {
			$mode = 'paused' === $state['status'] ? 'resume' : 'pause';
			self::form( 'igr_backfill', '<input type="hidden" name="igr_mode" value="' . esc_attr( $mode ) . '"><button class="button">' . esc_html( 'pause' === $mode ? __( 'Pausar', 'imagina-growth' ) : __( 'Reanudar', 'imagina-growth' ) ) . '</button>' );
		}
		echo '<h2>' . esc_html__( 'Desconectar', 'imagina-growth' ) . '</h2><p>' . esc_html__( 'Se borran las claves del sitio y se detiene el envío de datos.', 'imagina-growth' ) . '</p>';
		self::form(
			'igr_disconnect',
			'<label><input type="checkbox" name="igr_wipe" value="1"> ' . esc_html__( 'Borrar también el historial local del outbox', 'imagina-growth' ) . '</label><br><br><button class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( '¿Desconectar el sitio de Imagina Growth?', 'imagina-growth' ) ) . '\')">' . esc_html__( 'Desconectar', 'imagina-growth' ) . '</button>'
		);
	}

	/**
	 * Avisos.
	 *
	 * @param string $notice Clave del aviso.
	 * @param string $error  Mensaje de error.
	 */
	private static function notices( string $notice, string $error ): void {
		$messages = array(
			'connected'    => __( '¡Listo! El sitio quedó conectado y empezó la importación del historial.', 'imagina-growth' ),
			'disconnected' => __( 'El sitio se desconectó.', 'imagina-growth' ),
			'backfill'     => __( 'La importación histórica quedó en cola.', 'imagina-growth' ),
			'retry'        => __( 'Los eventos fallidos se reintentarán en el próximo envío.', 'imagina-growth' ),
			'flush'        => __( 'El envío quedó en cola.', 'imagina-growth' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/**
	 * Fila de tabla.
	 *
	 * @param string $label Etiqueta.
	 * @param string $html  Valor (ya escapado).
	 */
	private static function row( string $label, string $html ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html llega escapado desde esta clase.
		echo '<tr><th style="width:240px">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>';
	}

	/**
	 * Formulario hacia admin-post.php con nonce.
	 *
	 * @param string $action Acción.
	 * @param string $inner  Contenido (ya escapado).
	 * @param bool   $inline En línea.
	 */
	private static function form( string $action, string $inner, bool $inline = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $inline ? ' style="display:inline-block;margin-right:8px"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Construido con funciones de escape en esta clase.
		echo $inner;
		echo '</form>';
	}

	/**
	 * Verifica permiso y nonce.
	 *
	 * @param string $action Acción.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'imagina-growth' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Vuelve a la página con parámetros.
	 *
	 * @param array<string, string> $args Parámetros.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
