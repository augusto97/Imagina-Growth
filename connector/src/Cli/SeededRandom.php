<?php
/**
 * Números aleatorios repetibles para la tienda de prueba (compatible con PHP 8.1).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Cli;

/**
 * Envoltura de mt_rand con semilla.
 */
final class SeededRandom {

	/**
	 * Constructor.
	 *
	 * @param int $seed Semilla.
	 */
	public function __construct( int $seed ) {
		mt_srand( $seed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Datos de prueba repetibles.
	}

	/**
	 * Entero en [min, max].
	 *
	 * @param int $min Mínimo.
	 * @param int $max Máximo.
	 */
	public function getInt( int $min, int $max ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return mt_rand( $min, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
	}

	/**
	 * Decimal en [min, max].
	 *
	 * @param float $min Mínimo.
	 * @param float $max Máximo.
	 */
	public function getFloat( float $min, float $max ): float { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $min + ( $max - $min ) * mt_rand() / mt_getrandmax(); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
	}
}
