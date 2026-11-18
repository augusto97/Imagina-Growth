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
 * Fase 3 (§5.5 y §8.3): `widgets.json` con widgets de captación, `visitor.json`, `widgets/{id}/submit`
 * (cupón, premio de la ruleta y productos del quiz), `reco`, `watch`, `me` (+ `redeem` y `share`),
 * `reviews` (+ `badges`), `points`, `banner` y `POST /v1/s/wallet/redeem` (crea el cupón en el sitio con
 * `coupon.create` firmado antes de responder, como el hub).
 * Guarda agregados en `$MOCK_HUB_DATA/state.json` para que las pruebas los inspeccionen (`GET /_state`).
 * `POST /_config` reemplaza claves de primer nivel: la respuesta de `/v1/s/config` (`measurement`, `consent`,
 * `onsite`), los widgets (`widgets`), los productos de `reco` (`reco`) y el saldo (`wallet_balance`).
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
$state += array( 'submits' => array(), 'watches' => array(), 'redeems' => array(), 'shares' => array(), 'wallet_redeems' => array(), 'public_requests' => array() );

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
	$state['overrides'] = array_merge( $state['overrides'], (array) json_decode( $body, true ) );
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
				'mode'               => 'auto',
				'privacy_policy_url' => null,
				'texts'              => array(
					array( 'kind' => 'marketing_consent', 'channel' => 'whatsapp', 'version' => 'v1', 'label' => 'Quiero recibir ofertas por WhatsApp. Puedo darme de baja cuando quiera.' ),
					array( 'kind' => 'marketing_consent', 'channel' => 'email', 'version' => 'v1', 'label' => 'Quiero recibir ofertas por correo. Puedo darme de baja cuando quiera.' ),
				),
			),
			'measurement'        => $state['overrides']['measurement'] ?? array( 'mode' => 'server_only', 'pixels' => new stdClass() ),
			'onsite'             => $state['overrides']['onsite'] ?? array( 'loyalty' => true, 'wallet' => true, 'product_watch' => true ),
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
	$keep = static fn( array $w ): bool => ( 'thankyou' !== ( $w['slot'] ?? '' ) || 'thankyou' === $type )
		&& ( empty( $w['targeting']['page_types'] ) || in_array( $type, $w['targeting']['page_types'], true ) );
	$respond(
		200,
		array(
			'widgets'  => array_values( array_filter( $widgets, $keep ) ),
			// Propuesta: los textos de consentimiento para los slots que no son widgets («avísame»).
			'consents' => array( array( 'channel' => 'email', 'label' => 'Quiero recibir ofertas por correo. Puedo darme de baja cuando quiera.', 'version' => 'v1' ) ),
		)
	);
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


// ---------------------------------------------------------------- Fase 3 (api-connector.md §5.5 y §8.3)

$pk_ok = static function ( string $pk ) use ( &$state ): bool {
	return is_array( $state['site'] ) && $pk === $state['site']['public_key'];
};
$public_body = static function () use ( $body ): array {
	$req = json_decode( $body, true );
	return is_array( $req ) ? $req : array();
};
$log_public = static function ( string $what ) use ( &$state ): void {
	$state['public_requests'][] = array( 'path' => $what, 'query' => $_GET );
	$state['public_requests']   = array_slice( $state['public_requests'], -100 );
};
$user_ref = static function ( ?string $tok ) use ( $token ): ?string {
	$p = $token( $tok );
	return is_array( $p ) && isset( $p['ref'] ) ? (string) $p['ref'] : null;
};
$fixture_products = static function () use ( &$state ): array {
	return $state['overrides']['reco'] ?? array(
		array( 'id' => '1', 'name' => 'Producto recomendado', 'price' => '150000.00', 'regular_price' => '150000.00', 'sale_price' => null, 'currency' => 'COP', 'image' => null, 'url' => 'http://127.0.0.1:8080/', 'rating_avg' => 4.5, 'rating_count' => 12 ),
	);
};

if ( 1 === preg_match( '#^/p/(pk_[A-Za-z0-9]+)/(visitor\.json|reco|points|program|banner|me|reviews|reviews/badges)$#', (string) $path, $m ) && 'GET' === $method ) {
	if ( ! $pk_ok( $m[1] ) ) {
		$respond( 404, array( 'error' => 'unknown_key' ) );
	}
	$log_public( $m[2] );
	switch ( $m[2] ) {
		case 'visitor.json':
			header( 'Cache-Control: private, no-store' );
			$aid  = (string) ( $_GET['aid'] ?? '' );
			$subs = array( 'email' => false, 'whatsapp' => false );
			$known = false;
			foreach ( $state['submits'] as $sub ) {
				if ( '' !== $aid && $sub['anonymous_id'] === $aid ) {
					$known = true;
					foreach ( (array) $sub['consents'] as $ch => $on ) {
						$subs[ $ch ] = $subs[ $ch ] || (bool) $on;
					}
				}
			}
			$views     = count( array_filter( $state['browser'], static fn( $e ): bool => '' !== $aid && 'page.viewed' === $e['event'] && ( $e['identity']['anonymous_id'] ?? '' ) === $aid ) );
			$returning = $views > 1;
			$respond( 200, array( 'known' => $known, 'returning' => $returning, 'subscribed' => $subs, 'country' => 'CO', 'segments' => array() ) );
		case 'reco':
			header( 'Cache-Control: ' . ( in_array( $_GET['kind'] ?? '', array( 'para_ti', 'recently_viewed', 'complete_cart' ), true ) ? 'private' : 'public' ) . ', max-age=60' );
			$limit = max( 1, min( 12, (int) ( $_GET['limit'] ?? 4 ) ) );
			$respond( 200, array( 'kind' => (string) ( $_GET['kind'] ?? 'trending' ), 'products' => array_slice( $fixture_products(), 0, $limit ), 'fallback' => false ) );
		case 'points':
			header( 'Cache-Control: public, max-age=300' );
			$base   = (float) ( $_GET['price'] ?? $_GET['cart_total'] ?? 0 );
			$points = (int) floor( $base / 1000 );
			$respond( 200, array( 'points' => $points, 'label' => $points > 0 ? 'Gana ' . $points . ' Puntos Prueba' : null ) );
		case 'program':
			header( 'Cache-Control: public, max-age=300' );
			$respond(
				200,
				array(
					'active'        => true,
					'currency_name' => 'Puntos Prueba',
					'explainer'     => array( 'title' => 'Así funciona Puntos Prueba', 'sections' => array() ),
					'tiers'         => array( array( 'name' => 'Plata', 'benefits' => array( 'Envío gratis' ) ) ),
					'rewards'       => array( array( 'id' => 'rw_01JF0REWARD000000000001', 'name' => '$20.000 de descuento', 'points' => 500, 'kind' => 'coupon_fixed' ) ),
				)
			);
		case 'banner':
			header( 'Cache-Control: private, no-store' );
			$ref = $user_ref( isset( $_GET['token'] ) ? (string) $_GET['token'] : null );
			$respond( 200, array( 'banner' => 'account' === ( $_GET['placement'] ?? '' ) && null !== $ref ? array( 'id' => 'bn_01JF0BANNER0000000000001', 'text' => 'Eres cliente Plata: envío gratis este mes', 'cta' => 'Ver beneficios', 'url' => 'http://127.0.0.1:8080/', 'style' => 'success' ) : null ) );
		case 'me':
			header( 'Cache-Control: private, no-store' );
			$ref = $user_ref( isset( $_GET['token'] ) ? (string) $_GET['token'] : null );
			if ( null === $ref ) {
				$respond( 401, array( 'ok' => false, 'error' => array( 'code' => 'invalid_token' ) ) );
			}
			$spent   = array_sum( array_map( static fn( $r ) => $r['ref'] === $ref ? (int) $r['points'] : 0, $state['redeems'] ) );
			$balance = 1532 - $spent;
			$code    = 'REF' . strtoupper( substr( hash( 'sha256', $ref ), 0, 5 ) );
			$respond(
				200,
				array(
					'program'  => array( 'active' => true, 'currency_name' => 'Puntos Prueba' ),
					'points'   => array( 'balance' => $balance, 'pending' => 282, 'currency_name' => 'Puntos Prueba', 'expiring' => array( 'points' => 120, 'at' => '2026-12-31' ) ),
					'tier'     => array( 'name' => 'Plata', 'next_name' => 'Oro', 'progress_pct' => 64, 'benefits' => array( 'Envío gratis' ) ),
					'wallet'   => array( 'balance' => (string) ( $state['overrides']['wallet_balance'] ?? '35000.00' ), 'currency' => 'COP' ),
					'referral' => array( 'code' => $code, 'link' => 'http://127.0.0.1:8080/?ref=' . $code, 'share_text' => 'Te regalo 10% en la tienda de prueba', 'friend_reward' => '10% en tu primera compra', 'referrer_reward' => '500 puntos' ),
					'rewards'  => array(
						array( 'id' => 'rw_01JF0REWARD000000000001', 'name' => '$20.000 de descuento', 'points' => 500, 'kind' => 'coupon_fixed', 'affordable' => $balance >= 500 ),
						array( 'id' => 'rw_01JF0REWARD000000000002', 'name' => 'Kit de brochas', 'points' => 5000, 'kind' => 'product', 'affordable' => $balance >= 5000 ),
					),
				)
			);
		case 'reviews':
			header( 'Cache-Control: public, max-age=300' );
			$page  = max( 1, (int) ( $_GET['page'] ?? 1 ) );
			$media = array( array( 'url' => 'http://127.0.0.1:8090/_media/1.svg', 'type' => 'image', 'thumb' => 'http://127.0.0.1:8090/_media/1.svg' ) );
			$all   = array(
				array( 'id' => 'rv_01', 'product_id' => '88', 'rating' => 5, 'title' => 'Excelente cubrimiento', 'body' => 'Con una mano quedó perfecta.', 'author' => 'Ana R.', 'verified' => true, 'media' => $media, 'created_at' => '2026-11-01T10:00:00Z', 'reply' => '¡Gracias, Ana!' ),
				array( 'id' => 'rv_02', 'product_id' => '88', 'rating' => 4, 'title' => null, 'body' => 'Buena, el color es un poco más claro.', 'author' => 'Luis P.', 'verified' => false, 'media' => array(), 'created_at' => '2026-10-20T10:00:00Z', 'reply' => null ),
			);
			if ( '1' === (string) ( $_GET['with_media'] ?? '0' ) ) {
				$all = array_values( array_filter( $all, static fn( $r ) => array() !== $r['media'] ) );
			}
			$respond( 200, array( 'summary' => array( 'avg' => 4.8, 'count' => 23, 'distribution' => array( '5' => 20, '4' => 2, '3' => 1, '2' => 0, '1' => 0 ) ), 'reviews' => 1 === $page ? $all : array(), 'next_page' => null ) );
		case 'reviews/badges':
			header( 'Cache-Control: public, max-age=300' );
			$out = array();
			foreach ( array_filter( explode( ',', (string) ( $_GET['product_ids'] ?? '' ) ) ) as $pid ) {
				$out[ $pid ] = array( 'avg' => 4.8, 'count' => 23 );
			}
			$respond( 200, $out );
	}
}

if ( 'GET' === $method && str_starts_with( (string) $path, '/_media/' ) ) {
	flock( $lock, LOCK_UN );
	header( 'Content-Type: image/svg+xml' );
	echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300" fill="#2563eb"/></svg>';
	exit;
}

// Envío de un widget: crea o une el contacto (aquí, solo lo guarda), cupón único, premio y productos.
if ( 'POST' === $method && 1 === preg_match( '#^/p/(pk_[A-Za-z0-9]+)/widgets/([A-Za-z0-9_-]+)/submit$#', (string) $path, $m ) ) {
	if ( ! $pk_ok( $m[1] ) ) {
		$respond( 404, array( 'error' => 'unknown_key' ) );
	}
	$req    = $public_body();
	$widget = null;
	foreach ( (array) ( $state['overrides']['widgets'] ?? array() ) as $w ) {
		if ( ( $w['id'] ?? '' ) === $m[2] ) {
			$widget = $w;
		}
	}
	if ( null === $widget ) {
		$respond( 404, array( 'error' => 'unknown_widget' ) );
	}
	$fields  = is_array( $req['fields'] ?? null ) ? $req['fields'] : array();
	$errors  = array();
	$content = $widget['content'] ?? array();
	foreach ( (array) ( $content['fields'] ?? array() ) as $f ) {
		$v = trim( (string) ( $fields[ $f['key'] ] ?? '' ) );
		if ( ! empty( $f['required'] ) && '' === $v ) {
			$errors[ $f['key'] ] = array( 'Este dato es obligatorio.' );
		} elseif ( 'phone' === $f['key'] && '' !== $v && strlen( preg_replace( '/\D/', '', $v ) ) < 10 ) {
			$errors[ $f['key'] ] = array( 'Escribe un celular colombiano de 10 dígitos.' );
		} elseif ( 'email' === $f['key'] && '' !== $v && ! filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
			$errors[ $f['key'] ] = array( 'Escribe un correo válido.' );
		}
	}
	if ( array() !== $errors ) {
		$respond( 422, array( 'ok' => false, 'message' => 'Revisa los datos e inténtalo de nuevo.', 'errors' => $errors ) );
	}
	$contact = strtolower( (string) ( $fields['email'] ?? '' ) ) . preg_replace( '/\D/', '', (string) ( $fields['phone'] ?? '' ) );
	foreach ( $state['submits'] as $sub ) {
		if ( '' !== $contact && $sub['widget_id'] === $m[2] && $sub['contact'] === $contact ) {
			$respond( 409, array( 'ok' => false, 'error' => 'already_claimed', 'message' => 'Ya recibiste el cupón de esta promoción. Revisa tu correo o tu WhatsApp.' ) );
		}
	}
	$state['submits'][] = array(
		'widget_id'     => $m[2],
		'kind'          => $widget['kind'],
		'variant'       => $req['variant'] ?? null,
		'step'          => $req['step'] ?? null,
		'anonymous_id'  => $req['anonymous_id'] ?? null,
		'page_url'      => $req['page_url'] ?? null,
		'contact'       => $contact,
		'fields'        => array_keys( $fields ),
		'consents'      => $req['consents'] ?? array(),
		'answers'       => $req['answers'] ?? null,
		'referral_code' => $req['referral_code'] ?? null,
	);
	$step = (int) ( $req['step'] ?? 1 );
	$out  = array( 'ok' => true, 'message' => '¡Listo!', 'double_opt_in' => false );
	if ( 'two_step' === $widget['kind'] && 1 === $step ) {
		$out['next_step'] = 2;
	}
	if ( in_array( $widget['kind'], array( 'popup', 'embedded_form', 'spin_wheel', 'two_step', 'referral_welcome', 'bar', 'teaser' ), true ) && '' !== $contact && 1 === $step ) {
		$out['coupon'] = array( 'code' => 'BIENV-' . strtoupper( substr( hash( 'sha256', $contact . $m[2] ), 0, 6 ) ), 'description' => '10% en tu primera compra', 'expires_at' => gmdate( 'Y-m-d', time() + 14 * 86400 ) );
	}
	if ( 'spin_wheel' === $widget['kind'] ) {
		$slices       = $content['slices'] ?? array( array( 'key' => '10', 'label' => '10%' ) );
		$prize        = $slices[ min( 1, count( $slices ) - 1 ) ];
		$out['prize'] = array( 'key' => $prize['key'], 'label' => $prize['label'] . ' de descuento' );
	}
	if ( 'quiz' === $widget['kind'] ) {
		$out['message']  = 'Esto es lo que te recomendamos';
		$out['products'] = array_slice( $fixture_products(), 0, 2 );
	}
	$respond( 200, $out );
}

if ( 'POST' === $method && 1 === preg_match( '#^/p/(pk_[A-Za-z0-9]+)/(watch|me/redeem|me/share)$#', (string) $path, $m ) ) {
	if ( ! $pk_ok( $m[1] ) ) {
		$respond( 404, array( 'error' => 'unknown_key' ) );
	}
	$req = $public_body();
	if ( 'watch' === $m[2] ) {
		$email = (string) ( $req['email'] ?? '' );
		$phone = (string) ( $req['phone'] ?? '' );
		if ( 1 !== preg_match( '/^\d+$/', (string) ( $req['product_id'] ?? '' ) ) || ! in_array( $req['kind'] ?? '', array( 'back_in_stock', 'price_drop' ), true ) || ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) && strlen( preg_replace( '/\D/', '', $phone ) ) < 7 ) ) {
			$respond( 422, array( 'error' => 'invalid', 'message' => 'Escribe un correo o un WhatsApp válido.', 'errors' => array( 'email' => array( 'Escribe un correo o un WhatsApp válido.' ) ) ) );
		}
		$state['watches'][] = array( 'product_id' => $req['product_id'], 'kind' => $req['kind'], 'channel' => '' !== $email ? 'email' : 'whatsapp', 'consents' => $req['consents'] ?? array(), 'consent_version' => $req['consent_version'] ?? null, 'anonymous_id' => $req['anonymous_id'] ?? null );
		$respond( 200, array( 'ok' => true, 'message' => 'back_in_stock' === $req['kind'] ? 'Te avisaremos cuando vuelva.' : 'Te avisaremos si baja de precio.' ) );
	}
	$ref = $user_ref( isset( $req['token'] ) ? (string) $req['token'] : null );
	if ( null === $ref ) {
		$respond( 401, 'me/share' === $m[2] ? array( 'error' => 'invalid_token' ) : array( 'ok' => false, 'error' => array( 'code' => 'invalid_token' ) ) );
	}
	if ( 'me/share' === $m[2] ) {
		$state['shares'][] = array( 'ref' => $ref, 'channel' => $req['channel'] ?? null );
		$respond( 202, array( 'ok' => true ) );
	}
	$points = array( 'rw_01JF0REWARD000000000001' => 500, 'rw_01JF0REWARD000000000002' => 5000 )[ $req['reward_id'] ?? '' ] ?? null;
	$spent  = array_sum( array_map( static fn( $r ) => $r['ref'] === $ref ? (int) $r['points'] : 0, $state['redeems'] ) );
	if ( null === $points ) {
		$respond( 422, array( 'ok' => false, 'error' => array( 'code' => 'unknown_reward', 'message' => 'Esta recompensa no está disponible.' ) ) );
	}
	if ( 1532 - $spent < $points ) {
		$respond( 422, array( 'ok' => false, 'error' => array( 'code' => 'not_redeemed', 'message' => 'No tienes puntos suficientes.' ) ) );
	}
	$state['redeems'][] = array( 'ref' => $ref, 'reward_id' => $req['reward_id'], 'points' => $points );
	$respond( 200, array( 'ok' => true, 'balance' => 1532 - $spent - $points, 'coupon' => array( 'code' => 'PUNTOS-' . strtoupper( bin2hex( random_bytes( 3 ) ) ), 'description' => '$20.000 de descuento', 'expires_at' => gmdate( 'Y-m-d', time() + 30 * 86400 ) ) ) );
}

// Saldo a favor (§5.5): firmado; crea el cupón en el sitio con coupon.create antes de responder.
if ( 'POST' === $method && '/v1/s/wallet/redeem' === $path ) {
	if ( ! $verify() ) {
		$respond( 401, array( 'error' => 'signature' ) );
	}
	$req     = json_decode( $body, true );
	$balance = (float) ( $state['overrides']['wallet_balance'] ?? '35000.00' );
	if ( ! is_array( $req ) || 1 !== preg_match( '/^wc:\d+$/', (string) ( $req['customer_ref'] ?? '' ) ) ) {
		$respond( 422, array( 'error' => 'invalid' ) );
	}
	if ( $balance <= 0 ) {
		$respond( 409, array( 'ok' => false, 'error' => array( 'code' => 'no_balance' ) ) );
	}
	$amount  = number_format( min( $balance, (float) $req['cart_total'] ), 2, '.', '' );
	$code    = 'SALDO-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
	$command = json_encode(
		array(
			'command'    => 'coupon.create',
			'args'       => array( 'code' => $code, 'kind' => 'fixed_cart', 'amount' => $amount, 'expires_at' => gmdate( 'Y-m-d', time() + 86400 ), 'usage_limit' => 1, 'individual_use' => false, 'description' => 'Saldo a favor' ),
			'request_id' => 'wr_' . bin2hex( random_bytes( 8 ) ),
			'nonce'      => bin2hex( random_bytes( 16 ) ),
			'issued_at'  => gmdate( 'c' ),
		)
	);
	$ch = curl_init( rtrim( (string) $state['site']['request']['rest_url'], '/' ) . '/commands' );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $command, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'X-IGR-Signature: ' . Hmac::header( $state['site']['secret'], $command ) ) ) );
	$res    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	$state['wallet_redeems'][] = array( 'request' => $req, 'coupon_status' => $status, 'code' => $code );
	if ( 200 !== $status ) {
		$respond( 503, array( 'error' => 'coupon_create_failed', 'detail' => substr( $res, 0, 300 ) ) );
	}
	$respond( 200, array( 'coupon' => array( 'code' => $code, 'amount' => $amount, 'expires_at' => gmdate( 'Y-m-d\T23:59:59\Z', time() + 86400 ) ), 'balance' => number_format( $balance, 2, '.', '' ) ) );
}

$respond( 404, array( 'error' => 'not_found', 'path' => $path ) );
