<?php
/**
 * Comandos de la Fase 3 (docs/api-connector.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\ReviewCollector;
use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Commands\Handlers\CustomerSetTier;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Onsite\Store;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * review.publish, review.reply, review.unpublish y customer.set_tier con WordPress y WooCommerce reales.
 */
final class CommandsPhase3Test extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->connect();
		ReviewCollector::register();
	}

	/**
	 * Ejecuta un comando firmado como el hub.
	 *
	 * @param string               $command Comando.
	 * @param array<string, mixed> $args    Argumentos.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function run_command( string $command, array $args = array() ): array {
		$body    = (string) wp_json_encode(
			array(
				'command'    => $command,
				'args'       => (object) $args,
				'request_id' => '01JF' . wp_generate_password( 22, false ),
				'nonce'      => bin2hex( random_bytes( 16 ) ),
				'issued_at'  => gmdate( 'c' ),
			)
		);
		$request = new \WP_REST_Request( 'POST', '/' . CommandsController::NAMESPACE_V1 . '/commands' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-igr-signature', Hmac::header( self::SECRET, $body ) );
		$response = rest_get_server()->dispatch( $request );
		return array( $response->get_status(), (array) json_decode( (string) wp_json_encode( $response->get_data() ), true ) );
	}

	/**
	 * Argumentos de review.publish.
	 *
	 * @param int                  $product_id Producto.
	 * @param array<string, mixed> $extra      Cambios.
	 * @return array<string, mixed>
	 */
	private function review( int $product_id, array $extra = array() ): array {
		return array_merge(
			array(
				'review_id'    => 'rv_01JF0REVIEW0000000000001',
				'product_id'   => (string) $product_id,
				'rating'       => 5,
				'title'        => 'Cubre muy bien',
				'body'         => 'Una sola mano y quedó perfecta.',
				'author_name'  => 'Ana R.',
				'author_email' => 'Ana@Example.com',
				'verified'     => true,
				'media'        => array(
					array(
						'url'   => 'https://cdn.imagina.test/r/1.jpg',
						'type'  => 'image',
						'thumb' => 'https://cdn.imagina.test/r/1-t.jpg',
					),
					array(
						'url'  => 'https://cdn.imagina.test/r/2.mp4',
						'type' => 'video',
					),
				),
				'created_at'   => '2026-11-10T15:30:00Z',
			),
			$extra
		);
	}

	public function test_review_publish_crea_la_resena_nativa_idempotente_y_sin_volver_al_hub(): void {
		$product           = $this->product( '150000' );
		[ $status, $body ] = $this->run_command( 'review.publish', $this->review( $product->get_id() ) );
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$id      = (int) $body['data']['comment_id'];
		$comment = get_comment( $id );
		$this->assertSame( 'review', $comment->comment_type );
		$this->assertSame( '1', (string) $comment->comment_approved );
		$this->assertSame( 'Ana R.', $comment->comment_author );
		$this->assertSame( 'ana@example.com', $comment->comment_author_email );
		$this->assertSame( '2026-11-10 15:30:00', $comment->comment_date_gmt );
		$this->assertSame( '5', (string) get_comment_meta( $id, 'rating', true ) );
		$this->assertSame( '1', (string) get_comment_meta( $id, 'verified', true ) );
		$this->assertSame( 'rv_01JF0REVIEW0000000000001', get_comment_meta( $id, 'igr_review_id', true ) );
		$this->assertTrue( wc_review_is_from_verified_owner( $id ) );
		$media = json_decode( (string) get_comment_meta( $id, 'igr_media', true ), true );
		$this->assertSame( 'https://cdn.imagina.test/r/1-t.jpg', $media[0]['thumb'] );
		$this->assertSame( 'video', $media[1]['type'] );
		$this->assertSame( '5.00', wc_get_product( $product->get_id() )->get_average_rating() );
		$this->assertSame( 1, wc_get_product( $product->get_id() )->get_review_count() );

		// Idempotente por review_id.
		[ , $again ] = $this->run_command( 'review.publish', $this->review( $product->get_id(), array( 'body' => 'otro texto' ) ) );
		$this->assertSame( (string) $id, $again['data']['comment_id'] );

		// La reseña que vino del hub no vuelve al hub como review.submitted.
		$this->assertSame( array(), $this->outbox( 'review.submitted' ) );
		$this->assertNull( ReviewCollector::envelope( new EventFactory( self::SITE_ID, 'woocommerce', '1.0.0' ), $comment ) );

		// Título y fotos debajo del texto (plantilla de WooCommerce).
		ob_start();
		Store::review_title( $comment );
		Store::review_media( $comment );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<strong>Cubre muy bien</strong>', $html );
		$this->assertStringContainsString( 'src="https://cdn.imagina.test/r/1-t.jpg"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'href="https://cdn.imagina.test/r/2.mp4"', $html );
	}

	public function test_review_publish_valida_los_argumentos(): void {
		$product     = $this->product();
		[ $s1, $b1 ] = $this->run_command( 'review.publish', $this->review( $product->get_id(), array( 'rating' => 7 ) ) );
		$this->assertSame( 422, $s1 );
		$this->assertSame( 'invalid_args', $b1['error']['code'] );
		[ $s2 ] = $this->run_command( 'review.publish', $this->review( 999999 ) );
		$this->assertSame( 404, $s2 );
		[ $s3 ] = $this->run_command(
			'review.publish',
			$this->review(
				$product->get_id(),
				array(
					'media' => array(
						array(
							'url'  => 'javascript:alert(1)',
							'type' => 'image',
						),
					),
				)
			)
		);
		$this->assertSame( 422, $s3 );
		$this->assertSame(
			0,
			(int) get_comments(
				array(
					'count'   => true,
					'post_id' => $product->get_id(),
					'type'    => 'review',
					'status'  => 'all',
				)
			)
		);
	}

	public function test_review_reply_y_unpublish(): void {
		$product           = $this->product();
		[ , $published ]   = $this->run_command( 'review.publish', $this->review( $product->get_id() ) );
		$review_id         = (int) $published['data']['comment_id'];
		[ $status, $body ] = $this->run_command(
			'review.reply',
			array(
				'comment_id'  => (string) $review_id,
				'body'        => '¡Gracias, Ana! Nos alegra que te haya gustado.',
				'author_name' => 'Pinturas La 14',
			)
		);
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$reply = get_comment( (int) $body['data']['comment_id'] );
		$this->assertSame( (string) $review_id, (string) $reply->comment_parent );
		$this->assertSame( 'Pinturas La 14', $reply->comment_author );
		$this->assertSame( '1', (string) $reply->comment_approved );
		[ , $same ] = $this->run_command(
			'review.reply',
			array(
				'comment_id'  => (string) $review_id,
				'body'        => '¡Gracias, Ana! Nos alegra que te haya gustado.',
				'author_name' => 'Pinturas La 14',
			)
		);
		$this->assertSame( $body['data']['comment_id'], $same['data']['comment_id'] );
		[ $missing ] = $this->run_command(
			'review.reply',
			array(
				'comment_id'  => (string) $reply->comment_ID,
				'body'        => 'x',
				'author_name' => 'Tienda',
			)
		);
		$this->assertSame( 404, $missing, 'solo se responde a una reseña' );

		[ $s, $b ] = $this->run_command( 'review.unpublish', array( 'comment_id' => (string) $review_id ) );
		$this->assertSame( 200, $s );
		$this->assertTrue( $b['data']['ok'] );
		$this->assertSame( '0', (string) get_comment( $review_id )->comment_approved );
		$this->assertSame( 0, wc_get_product( $product->get_id() )->get_review_count() );

		// Publicarla otra vez (la moderación cambió de opinión) la vuelve a aprobar, sin duplicarla.
		[ , $again ] = $this->run_command( 'review.publish', $this->review( $product->get_id() ) );
		$this->assertSame( (string) $review_id, $again['data']['comment_id'] );
		$this->assertSame( '1', (string) get_comment( $review_id )->comment_approved );
		$this->assertSame( array(), $this->outbox( 'review.submitted' ) );
	}

	public function test_customer_set_tier_con_rol_seguro(): void {
		$user              = self::factory()->user->create( array( 'role' => 'customer' ) );
		[ $status, $body ] = $this->run_command(
			'customer.set_tier',
			array(
				'customer_ref' => 'wc:' . $user,
				'tier_key'     => 'tr_plata',
				'tier_name'    => 'Plata',
				'role'         => 'cliente_plata',
			)
		);
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$this->assertSame( 'tr_plata', get_user_meta( $user, CustomerSetTier::META, true ) );
		$this->assertSame( 'Plata', get_user_meta( $user, CustomerSetTier::META_NAME, true ) );
		$roles = get_userdata( $user )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( 'cliente_plata', $roles );
		$this->assertSame( array( 'read' => true ), get_role( 'cliente_plata' )->capabilities );

		// Sube a Oro: quita el rol de Plata y conserva los demás.
		$this->run_command(
			'customer.set_tier',
			array(
				'customer_ref' => 'wc:' . $user,
				'tier_key'     => 'tr_oro',
				'tier_name'    => 'Oro',
				'role'         => 'cliente_oro',
			)
		);
		$roles = get_userdata( $user )->roles;
		$this->assertContains( 'cliente_oro', $roles );
		$this->assertNotContains( 'cliente_plata', $roles );
		$this->assertContains( 'customer', $roles );

		// Un rol con permisos nunca se asigna.
		foreach ( array( 'administrator', 'shop_manager', 'editor' ) as $role ) {
			[ $s, $b ] = $this->run_command(
				'customer.set_tier',
				array(
					'customer_ref' => 'wc:' . $user,
					'tier_key'     => 'tr_x',
					'role'         => $role,
				)
			);
			$this->assertSame( 422, $s, $role );
			$this->assertSame( 'role_not_allowed', $b['error']['code'] );
		}
		add_role(
			'vip_con_permisos',
			'VIP',
			array(
				'read'       => true,
				'edit_posts' => true,
			)
		);
		[ $s ] = $this->run_command(
			'customer.set_tier',
			array(
				'customer_ref' => 'wc:' . $user,
				'tier_key'     => 'tr_x',
				'role'         => 'vip_con_permisos',
			)
		);
		$this->assertSame( 422, $s );
		$this->assertNotContains( 'vip_con_permisos', get_userdata( $user )->roles );
		remove_role( 'vip_con_permisos' );

		// Sin nivel: se borran la meta y el rol del nivel.
		$this->run_command( 'customer.set_tier', array( 'customer_ref' => 'wc:' . $user ) );
		$this->assertSame( '', get_user_meta( $user, CustomerSetTier::META, true ) );
		$this->assertNotContains( 'cliente_oro', get_userdata( $user )->roles );

		[ $missing ] = $this->run_command(
			'customer.set_tier',
			array(
				'customer_ref' => 'wc:999999',
				'tier_key'     => 'tr_oro',
			)
		);
		$this->assertSame( 404, $missing );
		remove_role( 'cliente_plata' );
		remove_role( 'cliente_oro' );
	}
}
