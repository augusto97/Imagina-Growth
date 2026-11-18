<?php
/**
 * Respuesta HTTP simplificada.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Resultado de una llamada al hub.
 */
final class HttpResponse {

	/**
	 * Constructor.
	 *
	 * @param int               $status Código HTTP (0 si hubo error de red o timeout).
	 * @param string            $body   Cuerpo.
	 * @param array<mixed>|null $json   Cuerpo decodificado.
	 * @param string|null       $error  Error de transporte.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly ?array $json,
		public readonly ?string $error = null
	) {}

	/**
	 * 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Error reintentable: red, timeout, 408, 425, 429 o 5xx.
	 */
	public function retryable(): bool {
		return 0 === $this->status || $this->status >= 500 || in_array( $this->status, array( 408, 425, 429 ), true );
	}

	/**
	 * Descripción corta para el registro.
	 */
	public function describe(): string {
		if ( null !== $this->error ) {
			return 'network: ' . $this->error;
		}
		$code = is_array( $this->json ) && isset( $this->json['error'] ) && is_string( $this->json['error'] ) ? ' ' . $this->json['error'] : '';
		return 'HTTP ' . $this->status . $code;
	}
}
