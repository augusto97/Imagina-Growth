<?php
/**
 * Hub simulado para pruebas locales del connector (docs/api-connector.md).
 *
 * Uso:
 *   MOCK_HUB_DATA=/tmp/mockhub php -S 127.0.0.1:8090 tests/fixtures/mock-hub.php
 *
 * Implementa el canje del código, la ingesta firmada `/v1/s` (verifica la firma sobre los bytes
 * comprimidos, valida cada evento contra hub/resources/schemas/events y deduplica por event_id),
 * `/v1/s/config`, `/v1/s/catalog/hashes` y `PUT /v1/s/backfill`. Guarda agregados en
 * `$MOCK_HUB_DATA/state.json` para que las pruebas los inspeccionen (`GET /_state`).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

// phpcs:disable -- Herramienta de pruebas fuera de WordPress.

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use ImaginaGrowth\Connector\Support\Hmac;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

$data_dir = getenv( 'MOCK_HUB_DATA' ) ?: sys_get_temp_dir() . '/igr-mock-hub';
@mkdir( $data_dir, 0777, true );
$state_file = $data_dir . '/state.json';
$base       = 'http://' . ( $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8090' );

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$body   = (string) file_get_contents( 'php://input' );

$lock = fopen( $data_dir . '/lock', 'c' );
flock( $lock, LOCK_EX );
$state = is_readable( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : null;
$state = is_array( $state ) ? $state : array(
	'site'         => null,
	'events'       => array(),
	'seen'         => array(),
	'duplicates'   => 0,
	'orders'       => array(),
	'products'     => array(),
	'categories'   => array(),
	'customers'    => 0,
	'refunds'      => array(),
	'schema_errors' => array(),
	'backfill'     => null,
	'batches'      => 0,
	'bad_signatures' => 0,
);

$respond = static function ( int $status, array $payload ) use ( &$state, $state_file, $lock ): void {
	file_put_contents( $state_file, json_encode( $state ) );
	flock( $lock, LOCK_UN );
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
	exit;
};

$verify = static function () use ( &$state, $body ): bool {
	$site = $_SERVER['HTTP_X_IGR_SITE'] ?? '';
	$sig  = $_SERVER['HTTP_X_IGR_SIGNATURE'] ?? '';
	$ok   = is_array( $state['site'] ) && $site === $state['site']['site_id'] && Hmac::verify( $sig, $body, array( $state['site']['secret'] ) );
	if ( ! $ok ) {
		++$state['bad_signatures'];
	}
	return $ok;
};

if ( 'GET' === $method && '/_state' === $path ) {
	$copy = $state;
	unset( $copy['seen'] );
	$copy['orders_by_status'] = array();
	foreach ( $state['orders'] as $order ) {
		$s = $order['status'];
		$copy['orders_by_status'][ $s ]['count'] = ( $copy['orders_by_status'][ $s ]['count'] ?? 0 ) + 1;
		$cents = (int) round( (float) $order['total'] * 100 );
		$copy['orders_by_status'][ $s ]['cents'] = ( $copy['orders_by_status'][ $s ]['cents'] ?? 0 ) + $cents;
	}
	ksort( $copy['orders_by_status'] );
	$copy['orders']   = count( $state['orders'] );
	$copy['products'] = count( $state['products'] );
	$respond( 200, $copy );
}

if ( 'POST' === $method && '/_reset' === $path ) {
	$state = null;
	@unlink( $state_file );
	flock( $lock, LOCK_UN );
	http_response_code( 204 );
	exit;
}

if ( 'POST' === $method && '/api/connect/exchange' === $path ) {
	$req = json_decode( $body, true );
	if ( ! is_array( $req ) || 1 !== preg_match( '/^IGR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', (string) ( $req['code'] ?? '' ) ) ) {
		$respond( 422, array( 'error' => 'invalid_code' ) );
	}
	$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	$ulid     = '';
	for ( $i = 0; $i < 26; $i++ ) {
		$ulid .= $alphabet[ random_int( 0, 31 ) ];
	}
	$ulid[0]       = '0';
	$state['site'] = array(
		'site_id'    => 'st_' . $ulid,
		'public_key' => 'pk_' . bin2hex( random_bytes( 16 ) ),
		'secret'     => 'sk_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ),
		'request'    => array_diff_key( $req, array( 'code' => 1 ) ),
	);
	$respond(
		200,
		array(
			'site_id'    => $state['site']['site_id'],
			'public_key' => $state['site']['public_key'],
			'secret'     => $state['site']['secret'],
			'workspace'  => array( 'id' => 'ws_01JD3QF0A1B2C3D4E5F6G7H8J9', 'name' => 'Tienda de prueba', 'country' => 'CO', 'timezone' => 'America/Bogota', 'currency' => 'COP' ),
			'endpoints'  => array(
				'ingest_server'  => $base . '/v1/s',
				'ingest_browser' => $base . '/v1/b',
				'api'            => $base . '/',
			),
		)
	);
}

if ( 'POST' === $method && '/v1/s' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$raw = 'gzip' === ( $_SERVER['HTTP_CONTENT_ENCODING'] ?? '' ) ? gzdecode( $body ) : $body;
	$req  = json_decode( (string) $raw, true );
	$wire = json_decode( (string) $raw ); // Como el hub: objetos, así {} no se confunde con [].
	if ( ! is_array( $req ) || ! is_array( $req['events'] ?? null ) || count( $req['events'] ) > 500 ) {
		$respond( 400, array( 'error' => 'bad_batch' ) );
	}
	++$state['batches'];
	$schemas   = dirname( __DIR__, 3 ) . '/hub/resources/schemas/events';
	$validator = new Validator();
	$validator->resolver()->registerPrefix( 'https://growth.imagina.cloud/schemas/events/', $schemas );
	$rejected  = array();
	$accepted  = 0;
	foreach ( $req['events'] as $index => $event ) {
		$event_id = (string) ( $event['event_id'] ?? '' );
		$env      = $validator->validate( $wire->events[ $index ], 'https://growth.imagina.cloud/schemas/events/_envelope.json' );
		if ( ! $env->isValid() ) {
			$rejected[]                = array( 'event_id' => $event_id, 'reason' => 'envelope' );
			$state['schema_errors'][] = array( 'event' => $event['event'] ?? '?', 'where' => 'envelope', 'error' => json_encode( ( new ErrorFormatter() )->format( $env->error(), true ), JSON_UNESCAPED_UNICODE ) );
			continue;
		}
		if ( ( $event['source']['site_id'] ?? '' ) !== $state['site']['site_id'] ) {
			$rejected[] = array( 'event_id' => $event_id, 'reason' => 'site_mismatch' );
			continue;
		}
		$name   = (string) $event['event'];
		$schema = str_starts_with( $name, 'custom.' ) ? 'custom' : $name;
		if ( ! is_readable( $schemas . '/' . $schema . '.json' ) ) {
			$rejected[] = array( 'event_id' => $event_id, 'reason' => 'unknown_event' );
			continue;
		}
		$props = $validator->validate( $wire->events[ $index ]->properties, 'https://growth.imagina.cloud/schemas/events/' . $schema . '.json' );
		if ( ! $props->isValid() ) {
			$state['schema_errors'][] = array( 'event' => $name, 'event_id' => $event_id, 'where' => 'properties', 'error' => json_encode( ( new ErrorFormatter() )->format( $props->error(), true ), JSON_UNESCAPED_UNICODE ) );
		}
		if ( isset( $state['seen'][ $event_id ] ) ) {
			++$state['duplicates'];
			++$accepted;
			continue;
		}
		$state['seen'][ $event_id ]  = 1;
		$state['events'][ $name ]    = ( $state['events'][ $name ] ?? 0 ) + 1;
		$p                           = $event['properties'];
		if ( isset( $p['order'] ) && is_array( $p['order'] ) ) {
			$o    = $p['order'];
			$prev = $state['orders'][ $o['order_id'] ] ?? null;
			if ( null === $prev || (string) ( $o['modified_at'] ?? '' ) >= (string) $prev['modified_at'] ) {
				$state['orders'][ $o['order_id'] ] = array( 'status' => $o['status'], 'total' => $o['totals']['total'], 'modified_at' => (string) ( $o['modified_at'] ?? '' ) );
			}
		}
		if ( 'catalog.product_upserted' === $name ) {
			$state['products'][ $p['product_id'] ] = $p['hash'];
		} elseif ( 'catalog.product_deleted' === $name ) {
			unset( $state['products'][ $p['product_id'] ] );
		} elseif ( 'catalog.category_upserted' === $name ) {
			ksort( $p );
			$state['categories'][ $p['category_id'] ] = substr( hash( 'sha256', json_encode( $p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION ) ), 0, 32 );
		} elseif ( 'customer.registered' === $name ) {
			++$state['customers'];
		} elseif ( 'order.refunded' === $name ) {
			$state['refunds'][ $p['refund_id'] ] = $p['amount'];
		}
		++$accepted;
	}
	$state['schema_errors'] = array_slice( $state['schema_errors'], -200 );
	$respond( 202, array( 'accepted' => $accepted, 'rejected' => $rejected ) );
}

if ( 'GET' === $method && '/v1/s/config' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$respond(
		200,
		array(
			'site_id'            => $state['site']['site_id'],
			'workspace'          => array( 'id' => 'ws_01JD3QF0A1B2C3D4E5F6G7H8J9', 'name' => 'Tienda de prueba', 'country' => 'CO', 'timezone' => 'America/Bogota', 'currency' => 'COP', 'locale' => 'es-CO' ),
			'settings'           => array(
				'paid_statuses'     => array( 'processing', 'completed' ),
				'cod_gateways'      => array( 'cod' ),
				'cod_paid_statuses' => array( 'completed' ),
				'offline_gateways'  => array( 'bacs', 'cheque', 'cod' ),
				'excluded_roles'    => array( 'administrator', 'shop_manager' ),
				'backfill_months'   => 24,
				'early_capture'     => false,
			),
			'consent'            => array( 'mode' => 'strict', 'texts' => array() ),
			'min_plugin_version' => '0.1.0',
		)
	);
}

if ( 'GET' === $method && '/v1/s/catalog/hashes' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$type  = $_GET['type'] ?? 'product';
	$map   = 'category' === $type ? $state['categories'] : $state['products'];
	$items = array();
	foreach ( $map as $id => $hash ) {
		$items[] = array( 'id' => (string) $id, 'hash' => $hash );
	}
	$respond( 200, array( 'items' => $items, 'next_cursor' => null ) );
}

if ( 'PUT' === $method && '/v1/s/backfill' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$state['backfill'] = json_decode( $body, true );
	$respond( 200, array( 'ok' => true ) );
}

$respond( 404, array( 'error' => 'not_found', 'path' => $path ) );
