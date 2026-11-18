<?php
/**
 * Recolector de formularios: `form.submitted` (eventos.md §4.4).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

use ImaginaGrowth\Connector\Collectors\Roles;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Tracking\RequestContext;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Registra los adaptadores de los plugins activos y convierte cada envío en
 * `form.submitted`. Una inserción en el outbox, sin HTTP.
 */
final class FormCollector {

	/** @var list<class-string<FormAdapter>> */
	public const ADAPTERS = array(
		ContactForm7Adapter::class,
		WpFormsAdapter::class,
		GravityFormsAdapter::class,
		FluentFormsAdapter::class,
		ElementorFormsAdapter::class,
		JetFormBuilderAdapter::class,
		FormidableAdapter::class,
		NinjaFormsAdapter::class,
	);

	/**
	 * Engancha los adaptadores cuando los demás plugins ya cargaron.
	 */
	public static function register(): void {
		if ( did_action( 'init' ) ) {
			self::boot();
		} else {
			add_action( 'init', array( self::class, 'boot' ), 1 );
		}
	}

	/**
	 * Adaptadores de los plugins activos.
	 */
	public static function boot(): void {
		$trk = TrackerConfig::get();
		if ( is_array( $trk ) && isset( $trk['forms'] ) && false === $trk['forms'] ) {
			return;
		}
		foreach ( self::ADAPTERS as $adapter ) {
			if ( $adapter::available() ) {
				$adapter::register();
			}
		}
	}

	/**
	 * Plugins detectados (para las capacidades del sitio).
	 *
	 * @return list<string>
	 */
	public static function detected(): array {
		$map = array(
			ContactForm7Adapter::class   => 'cf7',
			WpFormsAdapter::class        => 'wpforms',
			GravityFormsAdapter::class   => 'gravity',
			FluentFormsAdapter::class    => 'fluentforms',
			ElementorFormsAdapter::class => 'elementor',
			JetFormBuilderAdapter::class => 'jetformbuilder',
			FormidableAdapter::class     => 'formidable',
			NinjaFormsAdapter::class     => 'ninjaforms',
		);
		$out = array();
		foreach ( $map as $adapter => $key ) {
			if ( $adapter::available() ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Arma y guarda `form.submitted`.
	 *
	 * @param FormSubmission $sub Envío.
	 */
	public static function record( FormSubmission $sub ): bool {
		if ( Roles::is_excluded() || Recorder::is_suppressed() ) {
			return false;
		}
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return false;
		}
		$fields   = (array) apply_filters( 'igr_form_fields', $sub->fields, $sub ); // Mapeo editable por formulario.
		$mapped   = FieldMapper::map( array_values( $fields ) );
		$ctx      = RequestContext::capture();
		$props    = array(
			'form_plugin' => mb_substr( $sub->plugin, 0, 40 ),
			'form_id'     => mb_substr( '' !== $sub->form_id ? $sub->form_id : 'unknown', 0, 64 ),
			'form_name'   => mb_substr( wp_strip_all_tags( $sub->form_name ), 0, 200 ),
			'fields'      => Json::obj( $mapped['fields'] ),
			'page_path'   => self::page_path(),
			'consents'    => Json::obj( $mapped['consents'] ),
		);
		$identity = $ctx['identity'];
		if ( isset( $mapped['fields']['email'] ) ) {
			$identity['email'] = strtolower( $mapped['fields']['email'] );
		}
		if ( isset( $mapped['fields']['phone'] ) ) {
			$identity['phone'] = mb_substr( $mapped['fields']['phone'], 0, 32 );
		}
		if ( is_user_logged_in() ) {
			$identity['customer_ref'] = 'wc:' . get_current_user_id();
		}
		return Recorder::record(
			$factory->make(
				'form.submitted',
				$props,
				array(
					'key'      => null !== $sub->entry_id && '' !== $sub->entry_id ? 'form:' . $sub->plugin . ':' . $sub->form_id . ':' . $sub->entry_id : null,
					'identity' => $identity,
					'context'  => $ctx['context'],
				)
			)
		);
	}

	/**
	 * Ruta de la página del formulario (los envíos por AJAX la traen en el Referer).
	 */
	private static function page_path(): string {
		$referer = wp_get_raw_referer();
		$url     = is_string( $referer ) && '' !== $referer ? $referer : ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/' );
		$path    = wp_parse_url( $url, PHP_URL_PATH );
		return mb_substr( is_string( $path ) && '' !== $path ? $path : '/', 0, 2048 );
	}
}
