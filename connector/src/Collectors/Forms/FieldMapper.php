<?php
/**
 * Mapeo automático de campos de formularios (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Lleva los campos de cualquier plugin a los del contrato (`form.submitted`):
 * `email`, `phone`, `first_name`, `last_name`, `company`, `city`, `message`,
 * `service` y `budget`, más las casillas de consentimiento en `consents`.
 * Nunca envía contraseñas, tarjetas, archivos ni números de documento.
 */
final class FieldMapper {

	/** Campos que no se envían nunca, por tipo o por nombre. */
	private const SECRET_TYPES = '/^(password|file|file-upload|fileupload|upload|signature|credit_?card|creditcard|stripe|square|paypal|payment|captcha|recaptcha|hcaptcha|turnstile|honeypot|hidden)/';
	private const SECRET_NAMES = '/(pass|contrase|\bclave\b|\bcard|tarjeta|\bcvv\b|\bcvc\b|\biban\b|cuenta banc|cedula|documento|\bdni\b|\bnit\b|\brut\b|\bcurp\b|\brfc\b|pasaporte|archivo|adjunto|\bfiles?\b|\bupload)/';

	private const RULES = array(
		'email'      => '/(e.?mail|correo)/',
		'phone'      => '/(phone|tel[eé]fono|telefono|\btel\b|celular|cel\b|m[oó]vil|movil|whats?app|wpp)/',
		'last_name'  => '/(last.?name|apellido|surname)/',
		'company'    => '/(company|empresa|compania|negocio|organizacion|razon.?social)/',
		'first_name' => '/(first.?name|nombres?\b|^name$|your.?name|full.?name|nombre)/',
		'city'       => '/(city|ciudad|municipio|localidad)/',
		'budget'     => '/(budget|presupuesto)/',
		'service'    => '/(servicio|service|inter[eé]s|producto|plan\b|tema|asunto|subject)/',
		'message'    => '/(message|mensaje|comentario|consulta|pregunta|detalle|your.?message)/',
	);

	/**
	 * @param list<array{name?: string, label?: string, type?: string, value?: mixed}> $fields Campos crudos.
	 * @return array{fields: array<string, string>, consents: array<string, bool>}
	 */
	public static function map( array $fields ): array {
		$out      = array();
		$consents = array();
		foreach ( $fields as $field ) {
			$type  = strtolower( (string) ( $field['type'] ?? 'text' ) );
			$key   = self::normalize( trim( (string) ( $field['name'] ?? '' ) . ' ' . (string) ( $field['label'] ?? '' ) ) );
			$value = $field['value'] ?? null;
			if ( 1 === preg_match( self::SECRET_TYPES, $type ) || 1 === preg_match( self::SECRET_NAMES, $key ) ) {
				continue;
			}
			// En las casillas, el texto de la opción marcada también cuenta («Novedades» → «Acepto ofertas por WhatsApp»).
			$probe = in_array( $type, array( 'checkbox', 'checkboxes', 'listcheckbox', 'radio' ), true ) ? trim( $key . ' ' . self::normalize( self::text( $value ) ) ) : $key;
			if ( self::is_consent( $type, $probe ) ) {
				$consents[ self::consent_key( $probe ) ] = self::truthy( $value );
				continue;
			}
			$text = self::text( $value );
			if ( '' === $text ) {
				continue;
			}
			$target = self::target( $type, $key, $text );
			if ( null === $target ) {
				continue;
			}
			if ( 'first_name' === $target && ! isset( $out['last_name'] ) && 1 === preg_match( '/(full.?name|^name$|your.?name|nombre completo|^nombre$)/', $key ) && str_contains( $text, ' ' ) ) {
				// «Nombre» con nombre y apellido juntos.
				$parts             = preg_split( '/\s+/', $text, 2 );
				$out['first_name'] = (string) ( $parts[0] ?? $text );
				$out['last_name']  = (string) ( $parts[1] ?? '' );
				continue;
			}
			if ( ! isset( $out[ $target ] ) ) {
				$out[ $target ] = mb_substr( $text, 0, 'message' === $target ? 5000 : 300 );
			}
		}
		return array(
			'fields'   => array_filter( $out, static fn( string $v ): bool => '' !== $v ),
			'consents' => $consents,
		);
	}

	/**
	 * Campo destino según el tipo y el nombre.
	 *
	 * @param string $type Tipo.
	 * @param string $key  Nombre + etiqueta normalizados.
	 * @param string $text Valor.
	 */
	private static function target( string $type, string $key, string $text ): ?string {
		if ( 'email' === $type || ( 1 === preg_match( self::RULES['email'], $key ) && is_email( $text ) ) ) {
			return is_email( $text ) ? 'email' : null;
		}
		if ( in_array( $type, array( 'tel', 'phone', 'phone_number', 'phone-number' ), true ) || 1 === preg_match( self::RULES['phone'], $key ) ) {
			return 1 === preg_match( '/\d{6,}/', (string) preg_replace( '/\D/', '', $text ) ) ? 'phone' : null;
		}
		foreach ( array( 'last_name', 'company', 'first_name', 'city', 'budget', 'service', 'message' ) as $target ) {
			if ( 1 === preg_match( self::RULES[ $target ], $key ) ) {
				return $target;
			}
		}
		return 'textarea' === $type ? 'message' : null;
	}

	/**
	 * ¿Es una casilla de consentimiento?
	 *
	 * @param string $type Tipo.
	 * @param string $key  Nombre + etiqueta normalizados.
	 */
	private static function is_consent( string $type, string $key ): bool {
		if ( in_array( $type, array( 'acceptance', 'gdpr-checkbox', 'gdpr', 'gdpr_agreement', 'gdpr-agreement', 'terms-n-condition', 'terms_and_condition', 'consent' ), true ) ) {
			return true;
		}
		return in_array( $type, array( 'checkbox', 'checkboxes', 'listcheckbox', 'radio' ), true )
			&& 1 === preg_match( '/(acept|consent|autoriz|privacidad|politica|newsletter|boletin|marketing|ofertas|promocion|novedades|whats?app.*(ofert|novedad|mensaje)|terminos|tratamiento)/', $key );
	}

	/**
	 * Clave en `consents{}`: por canal si se nombra; si no, la de privacidad o marketing genérico.
	 *
	 * @param string $key Nombre + etiqueta normalizados.
	 */
	private static function consent_key( string $key ): string {
		if ( 1 === preg_match( '/(whats?app|wpp)/', $key ) ) {
			return 'whatsapp_marketing';
		}
		if ( 1 === preg_match( '/\bsms\b/', $key ) ) {
			return 'sms_marketing';
		}
		if ( 1 === preg_match( '/(correo|e.?mail|newsletter|boletin)/', $key ) ) {
			return 'email_marketing';
		}
		if ( 1 === preg_match( '/(ofertas|marketing|promocion|novedades|publicidad)/', $key ) ) {
			return 'marketing';
		}
		// «Acepto la política de privacidad» autoriza el tratamiento, no el marketing.
		return 'privacy_policy';
	}

	/**
	 * Texto de un valor (los arreglos se unen con comas).
	 *
	 * @param mixed $value Valor.
	 */
	public static function text( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : '', $value ) ) );
		}
		return is_scalar( $value ) ? trim( sanitize_textarea_field( (string) $value ) ) : '';
	}

	/**
	 * ¿Marcada?
	 *
	 * @param mixed $value Valor.
	 */
	private static function truthy( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== array_filter( $value, static fn( $v ): bool => '' !== (string) $v && '0' !== (string) $v );
		}
		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Minúsculas sin tildes, con espacios en vez de símbolos.
	 *
	 * @param string $text Texto.
	 */
	private static function normalize( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}
}
