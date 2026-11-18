<?php
/**
 * Hub simulado para pruebas locales del connector (docs/api-connector.md).
 *
 * Uso:
 *   MOCK_HUB_DATA=/tmp/mockhub php -S 127.0.0.1:8090 tests/fixtures/mock-hub.php
 *
 * Implementa el canje del código, la ingesta firmada `/v1/s` (verifica la firma sobre los bytes
 * comprimidos, valida cada evento contra hub/resources/schemas/events y deduplica por event_id),
 * `/v1/s/config`, `/v1/s/catalog/hashes`, `PUT /v1/s/backfill` y `POST /v1/s/privacy/requests`.
 * Del lado del navegador (Fase 1): `/v1/b` (CORS, clave pública, mismo validador; verifica el token
 * de `identify`), `GET /p/{pk}/widgets.json` y `POST /p/{pk}/surveys/{id}/answers` (token de igrOrder).
 * Guarda agregados en `$MOCK_HUB_DATA/state.json` para que las pruebas los inspeccionen (`GET /_state`).
 * `POST /_config` mezcla ajustes en la respuesta de `/v1/s/config` (p. ej. `measurement`, `consent`)
 * y en los widgets (`widgets`).
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
	'browser'      => array(),
	'browser_batches' => 0,
	'identified'   => array(),
	'bad_tokens'   => 0,
	'survey_answers' => array(),
	'privacy'      => array(),
	'overrides'    => array(),
);
$state += array( 'browser' => array(), 'browser_batches' => 0, 'identified' => array(), 'bad_tokens' => 0, 'survey_answers' => array(), 'privacy' => array(), 'overrides' => array() );

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

// Token de igrUser / igrOrder (api-connector.md §7.1): base64url(JSON) "." hex(HMAC-SHA256).
$token = static function ( ?string $token ) use ( &$state ): ?array {
	if ( ! is_array( $state['site'] ) || ! is_string( $token ) || 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([0-9a-f]{64})$/', $token, $m ) ) {
		return null;
	}
	if ( ! hash_equals( hash_hmac( 'sha256', $m[1], $state['site']['secret'] ), $m[2] ) ) {
		return null;
	}
	$data = json_decode( (string) base64_decode( strtr( $m[1], '-_', '+/' ), true ), true );
	if ( ! is_array( $data ) || ( $data['site'] ?? null ) !== $state['site']['site_id'] || ! is_int( $data['exp'] ?? null ) || $data['exp'] < time() ) {
		return null;
	}
	return $data;
};

// Esquemas compartidos con el hub.
$schemas   = dirname( __DIR__, 3 ) . '/hub/resources/schemas/events';
$validator = new Validator();
$validator->resolver()->registerPrefix( 'https://growth.imagina.cloud/schemas/events/', $schemas );
$validate = static function ( object $wire, array $event ) use ( $validator, $schemas ): ?string {
	$env = $validator->validate( $wire, 'https://growth.imagina.cloud/schemas/events/_envelope.json' );
	if ( ! $env->isValid() ) {
		return 'envelope: ' . json_encode( ( new ErrorFormatter() )->format( $env->error(), true ), JSON_UNESCAPED_UNICODE );
	}
	$name   = (string) $event['event'];
	$schema = str_starts_with( $name, 'custom.' ) ? 'custom' : $name;
	if ( ! is_readable( $schemas . '/' . $schema . '.json' ) ) {
		return 'unknown_event';
	}
	$props = $validator->validate( $wire->properties, 'https://growth.imagina.cloud/schemas/events/' . $schema . '.json' );
	return $props->isValid() ? null : 'properties: ' . json_encode( ( new ErrorFormatter() )->format( $props->error(), true ), JSON_UNESCAPED_UNICODE );
};

// CORS de los endpoints públicos (el navegador llama desde el dominio del sitio).
$public = '/v1/b' === $path || str_starts_with( (string) $path, '/p/' );
if ( $public ) {
	header( 'Access-Control-Allow-Origin: ' . ( $_SERVER['HTTP_ORIGIN'] ?? '*' ) );
	header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Content-Type' );
	header( 'Vary: Origin' );
	if ( 'OPTIONS' === $method ) {
		flock( $lock, LOCK_UN );
		http_response_code( 204 );
		exit;
	}
}

if ( 'POST' === $method && '/_config' === $path ) {
	$state['overrides'] = array_replace_recursive( $state['overrides'], (array) json_decode( $body, true ) );
	$respond( 200, $state['overrides'] );
}

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
			'consent'            => $state['overrides']['consent'] ?? array(
				'mode'               => 'strict',
				'privacy_policy_url' => null,
				'texts'              => array(
					array( 'kind' => 'marketing_consent', 'channel' => 'whatsapp', 'version' => 'v1', 'label' => 'Quiero recibir ofertas por WhatsApp. Puedo darme de baja cuando quiera.' ),
					array( 'kind' => 'marketing_consent', 'channel' => 'email', 'version' => 'v1', 'label' => 'Quiero recibir ofertas por correo. Puedo darme de baja cuando quiera.' ),
				),
			),
			'measurement'        => $state['overrides']['measurement'] ?? array( 'mode' => 'server_only', 'pixels' => new stdClass() ),
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

if ( 'POST' === $method && '/v1/s/privacy/requests' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$req = json_decode( $body, true );
	if ( ! is_array( $req ) || ! in_array( $req['kind'] ?? '', array( 'access', 'delete' ), true ) || ! filter_var( $req['email'] ?? '', FILTER_VALIDATE_EMAIL ) ) {
		$respond( 422, array( 'error' => 'invalid' ) );
	}
	$id                 = 'dr_' . strtoupper( bin2hex( random_bytes( 8 ) ) );
	$state['privacy'][] = array( 'request_id' => $id, 'kind' => $req['kind'], 'email_hash' => hash( 'sha256', strtolower( $req['email'] ) ), 'wp_request_id' => $req['wp_request_id'] ?? null );
	$respond( 202, array( 'request_id' => $id, 'status' => 'pending', 'due_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 15 * 86400 ) ) );
}

// Ingesta del navegador (api-connector.md §4.2): text/plain con {"k": "pk_…", "events": [...]}, ≤ 20 eventos.
if ( 'POST' === $method && '/v1/b' === $path ) {
	$req  = json_decode( $body, true );
	$wire = json_decode( $body );
	if ( ! is_array( $req ) || ! is_array( $req['events'] ?? null ) || count( $req['events'] ) > 20 ) {
		$respond( 400, array( 'error' => 'bad_batch' ) );
	}
	if ( ! is_array( $state['site'] ) || ( $req['k'] ?? '' ) !== $state['site']['public_key'] ) {
		$respond( 401, array( 'error' => 'unknown_key' ) );
	}
	++$state['browser_batches'];
	$rejected = array();
	$accepted = 0;
	foreach ( $req['events'] as $index => $event ) {
		$event_id = (string) ( $event['event_id'] ?? '' );
		$error    = $validate( $wire->events[ $index ], $event );
		if ( null === $error && 'browser' !== ( $event['source']['type'] ?? '' ) ) {
			$error = 'source_type';
		}
		if ( null === $error && ( $event['source']['site_id'] ?? '' ) !== $state['site']['site_id'] ) {
			$error = 'site_mismatch';
		}
		if ( null !== $error ) {
			$rejected[]               = array( 'event_id' => $event_id, 'reason' => strtok( $error, ':' ) );
			$state['schema_errors'][] = array( 'event' => $event['event'] ?? '?', 'event_id' => $event_id, 'where' => 'browser', 'error' => $error );
			continue;
		}
		if ( isset( $state['seen'][ $event_id ] ) ) {
			++$state['duplicates'];
			++$accepted;
			continue;
		}
		$state['seen'][ $event_id ] = 1;
		$name                       = (string) $event['event'];
		$state['events'][ $name ]   = ( $state['events'][ $name ] ?? 0 ) + 1;
		if ( 'identify' === $name && isset( $event['properties']['token'] ) ) {
			$payload = $token( $event['properties']['token'] );
			if ( null === $payload ) {
				++$state['bad_tokens'];
			} else {
				$state['identified'][] = array( 'anonymous_id' => $event['identity']['anonymous_id'] ?? null, 'ref' => $payload['ref'] ?? null );
			}
		}
		$state['browser'][] = $event;
		++$accepted;
	}
	$state['browser']       = array_slice( $state['browser'], -300 );
	$state['schema_errors'] = array_slice( $state['schema_errors'], -200 );
	$respond( 202, array( 'accepted' => $accepted, 'rejected' => $rejected ) );
}

// Widgets activos (api-connector.md §8.1).
if ( 'GET' === $method && 1 === preg_match( '#^/p/(pk_[A-Za-z0-9]+)/widgets\.json$#', (string) $path, $m ) ) {
	if ( ! is_array( $state['site'] ) || $m[1] !== $state['site']['public_key'] ) {
		$respond( 404, array( 'error' => 'unknown_key' ) );
	}
	header( 'Cache-Control: public, max-age=300' );
	$widgets = $state['overrides']['widgets'] ?? array(
		array(
			'id'          => 'sv_01JD3QH0SURVEY0000000000',
			'kind'        => 'hdyhau_survey',
			'slot'        => 'thankyou',
			'title'       => 'Una pregunta rápida',
			'question'    => '¿Cómo nos conociste?',
			'options'     => array(
				array( 'key' => 'instagram', 'label' => 'Instagram' ),
				array( 'key' => 'google', 'label' => 'Google' ),
				array( 'key' => 'recomendacion', 'label' => 'Me lo recomendaron' ),
			),
			'allow_other' => true,
			'other_label' => 'Otro',
			'thanks'      => '¡Gracias por contarnos!',
		),
	);
	$type = (string) ( $_GET['page_type'] ?? '' );
	$respond( 200, array( 'widgets' => array_values( array_filter( $widgets, static fn( array $w ): bool => 'thankyou' !== ( $w['slot'] ?? '' ) || 'thankyou' === $type ) ) ) );
}

// Respuesta de la encuesta (api-connector.md §8.2).
if ( 'POST' === $method && 1 === preg_match( '#^/p/(pk_[A-Za-z0-9]+)/surveys/([A-Za-z0-9_]+)/answers$#', (string) $path, $m ) ) {
	if ( ! is_array( $state['site'] ) || $m[1] !== $state['site']['public_key'] ) {
		$respond( 404, array( 'error' => 'unknown_key' ) );
	}
	$req     = json_decode( $body, true );
	$payload = $token( is_array( $req ) ? ( $req['order_token'] ?? null ) : null );
	$options = array( 'instagram', 'google', 'recomendacion', 'otro' );
	if ( null === $payload || ! isset( $payload['order'] ) || ! in_array( $req['answer'] ?? '', $options, true ) ) {
		++$state['bad_tokens'];
		$respond( 422, array( 'error' => 'invalid' ) );
	}
	foreach ( $state['survey_answers'] as $answer ) {
		if ( $answer['order_id'] === $payload['order'] ) {
			$respond( 409, array( 'error' => 'already_answered' ) );
		}
	}
	$state['survey_answers'][] = array( 'survey_id' => $m[2], 'order_id' => $payload['order'], 'answer' => $req['answer'], 'other' => $req['other'] ?? null, 'anonymous_id' => $req['anonymous_id'] ?? null );
	$respond( 202, array( 'ok' => true ) );
}

$respond( 404, array( 'error' => 'not_found', 'path' => $path ) );
