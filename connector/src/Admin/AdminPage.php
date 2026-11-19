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
use ImaginaGrowth\Connector\Collectors\Forms\FormCollector;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\ConnectionManager;
use ImaginaGrowth\Connector\Consent\ConsentState;
use ImaginaGrowth\Connector\Diagnostics;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Settings;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

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
		foreach ( array( 'connect', 'manual_code', 'disconnect', 'backfill', 'retry_dead', 'flush', 'settings', 'onsite' ) as $action ) {
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
	 * Guarda la pestaña «Datos».
	 */
	public static function action_settings(): void {
		self::guard( 'igr_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verificado en guard().
		$roles = isset( $_POST['igr_roles'] ) && is_array( $_POST['igr_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['igr_roles'] ) ) : array();
		Settings::save(
			array(
				'tracker'        => ! empty( $_POST['igr_tracker'] ),
				'forms'          => ! empty( $_POST['igr_forms'] ),
				'wa_ref'         => ! empty( $_POST['igr_wa_ref'] ),
				'wa_numbers'     => isset( $_POST['igr_wa_numbers'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['igr_wa_numbers'] ) ) : '',
				'wa_format'      => isset( $_POST['igr_wa_format'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['igr_wa_format'] ) ) : '',
				'banner'         => ! empty( $_POST['igr_banner'] ),
				'cookie_domain'  => isset( $_POST['igr_cookie_domain'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['igr_cookie_domain'] ) ) : '',
				'excluded_roles' => empty( $_POST['igr_roles_custom'] ) ? null : $roles,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'        => 'data',
					'igr_notice' => 'saved',
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}

	/**
	 * Guarda la pestaña «En el sitio».
	 */
	public static function action_onsite(): void {
		self::guard( 'igr_onsite' );
		$changes = array();
		foreach ( array( 'bis', 'price_drop', 'points', 'rewards', 'wallet', 'top_banner' ) as $key ) {
			$changes[ $key ] = ! empty( $_POST[ 'igr_' . $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado en guard().
		}
		Settings::save( $changes );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'        => 'onsite',
					'igr_notice' => 'saved',
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
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
			'data'        => __( 'Datos', 'imagina-growth' ),
			'onsite'      => __( 'En el sitio', 'imagina-growth' ),
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
		if ( 'data' === $tab ) {
			self::render_data();
		} elseif ( 'onsite' === $tab ) {
			self::render_onsite();
		} elseif ( 'diagnostics' === $tab ) {
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

		$lag = Scheduler::lag_minutes();
		if ( $lag > 10 ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(
				/* translators: %d: minutos de retraso. */
				sprintf( __( 'Las tareas programadas de WordPress van %d minutos atrasadas: la importación y el envío no avanzan solos.', 'imagina-growth' ), $lag )
			) . '</strong> ' . esc_html__( 'Usa «Importar y enviar ahora» (abajo) y pide a tu hosting un cron real cada minuto (ver Diagnóstico).', 'imagina-growth' ) . '</p></div>';
		}

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
		self::render_sync_now();
		// El estado de la carga de la página: lo reemplaza el avance en vivo al usar el botón.
		echo '<div id="igr-backfill-static">';
		self::render_backfill_state( $state );
		echo '</div>';
	}

	/**
	 * Estado guardado de la importación.
	 *
	 * @param array<string, mixed> $state Estado de Runner.
	 */
	private static function render_backfill_state( array $state ): void {
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
	 * Botón «Importar y enviar ahora» (Admin\SyncNow): repite tramos por AJAX hasta terminar y muestra el avance.
	 */
	private static function render_sync_now(): void {
		echo '<div class="card" style="max-width:none"><p>' . esc_html__( 'La importación y el envío corren solos en segundo plano. Si no avanzan (por ejemplo, porque el cron de WordPress no corre), hazlo desde aquí y deja esta página abierta hasta que termine.', 'imagina-growth' ) . '</p>';
		echo '<p><button type="button" class="button button-primary" id="igr-sync-now">' . esc_html__( 'Importar y enviar ahora', 'imagina-growth' ) . '</button> <span id="igr-sync-msg" aria-live="polite"></span></p>';
		echo '<div id="igr-sync-progress"></div></div>';
		$config = array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => SyncNow::ACTION,
			'nonce'  => wp_create_nonce( SyncNow::ACTION ),
			'names'  => array(
				'products'      => __( 'Productos', 'imagina-growth' ),
				'categories'    => __( 'Categorías', 'imagina-growth' ),
				'customers'     => __( 'Clientes', 'imagina-growth' ),
				'orders'        => __( 'Pedidos', 'imagina-growth' ),
				'refunds'       => __( 'Reembolsos', 'imagina-growth' ),
				'reviews'       => __( 'Reseñas', 'imagina-growth' ),
				'subscriptions' => __( 'Suscripciones', 'imagina-growth' ),
			),
			'text'   => array(
				'working' => __( 'Importando y enviando… no cierres esta página.', 'imagina-growth' ),
				'sent'    => __( 'enviados en esta sesión', 'imagina-growth' ),
				'pending' => __( 'pendientes', 'imagina-growth' ),
				'done'    => __( 'Listo: todo quedó importado y enviado a Imagina Growth.', 'imagina-growth' ),
				'error'   => __( 'Se detuvo por un error:', 'imagina-growth' ),
				'stuck'   => __( 'No hubo avance en varios intentos. Revisa la pestaña Diagnóstico.', 'imagina-growth' ),
				'network' => __( 'No se pudo hablar con tu propio sitio (la petición falló o tardó demasiado). Intenta de nuevo.', 'imagina-growth' ),
				'dead'    => __( 'eventos rechazados (ver Diagnóstico)', 'imagina-growth' ),
			),
		);
		$js     = 'window.igrSyncNow=' . wp_json_encode( $config ) . ';' . <<<'JS'
(function(){
var c=window.igrSyncNow,b=document.getElementById('igr-sync-now'),m=document.getElementById('igr-sync-msg'),p=document.getElementById('igr-sync-progress');
if(!b){return;}
var total=0,idle=0,last=-1;
function fmt(n){return Number(n).toLocaleString();}
function draw(d){
  var st=document.getElementById('igr-backfill-static');if(st){st.style.display='none';}
  var h='<table class="widefat striped"><tbody>';
  (d.entities||[]).forEach(function(e){var pct=e.total>0?Math.min(100,Math.floor(e.sent*100/e.total)):100;
    h+='<tr><td>'+(c.names[e.key]||e.key)+'</td><td>'+fmt(e.sent)+' / '+fmt(e.total)+' <progress max="100" value="'+pct+'" style="vertical-align:middle;width:180px"></progress></td></tr>';});
  h+='</tbody></table><p>'+fmt(total)+' '+c.text.sent+' · '+fmt(d.pending)+' '+c.text.pending+(d.dead>0?' · '+fmt(d.dead)+' '+c.text.dead:'')+'</p>';
  p.innerHTML=h;
}
function stop(msg,color){b.disabled=false;m.textContent=msg;m.style.color=color||'';}
function step(first){
  var f=new FormData();f.append('action',c.action);f.append('nonce',c.nonce);if(first){f.append('first','1');}
  fetch(c.url,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
    if(!r||!r.success){stop(c.text.error+' '+((r&&r.data&&r.data.message)||''),'#b32d2e');return;}
    var d=r.data;total+=d.sent;draw(d);
    if(d.error){stop(c.text.error+' '+d.error,'#b32d2e');return;}
    if(d.done){stop(c.text.done,'#008a20');return;}
    var mark=d.pending+'/'+total+'/'+JSON.stringify(d.entities);
    idle=(mark===last&&!d.busy)?idle+1:0;last=mark;
    if(idle>=5){stop(c.text.stuck,'#996800');return;}
    setTimeout(function(){step(false);},d.busy?3000:300);
  }).catch(function(){stop(c.text.network,'#b32d2e');});
}
b.addEventListener('click',function(){b.disabled=true;total=0;idle=0;last=-1;m.style.color='';m.textContent=c.text.working;step(true);});
})();
JS;
		wp_print_inline_script_tag( $js );
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
	 * Pestaña «Datos»: qué recoge el sitio.
	 */
	private static function render_data(): void {
		$s       = Settings::all();
		$banner  = ConsentState::banner();
		$forms   = FormCollector::detected();
		$custom  = null !== $s['excluded_roles'];
		$checked = static fn( bool $on ): string => $on ? ' checked' : '';
		$inner   = '<table class="form-table" role="presentation"><tbody>';
		$inner  .= '<tr><th scope="row">' . esc_html__( 'Seguimiento en el navegador', 'imagina-growth' ) . '</th><td><label><input type="checkbox" name="igr_tracker" value="1"' . $checked( (bool) $s['tracker'] ) . '> ' . esc_html__( 'Cargar el script de seguimiento (máximo 6 KB) en las páginas del sitio', 'imagina-growth' ) . '</label></td></tr>';
		$inner  .= '<tr><th scope="row">' . esc_html__( 'Roles que no se miden', 'imagina-growth' ) . '</th><td><label><input type="checkbox" name="igr_roles_custom" value="1"' . $checked( $custom ) . '> ' . esc_html__( 'Elegir aquí en lugar de usar los del panel de Imagina Growth', 'imagina-growth' ) . '</label><br>';
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$on     = $custom && in_array( $role, (array) $s['excluded_roles'], true );
			$inner .= '<label style="display:inline-block;margin:4px 12px 0 0"><input type="checkbox" name="igr_roles[]" value="' . esc_attr( (string) $role ) . '"' . $checked( $on ) . '> ' . esc_html( translate_user_role( (string) $name ) ) . '</label>';
		}
		$inner .= '</td></tr>';
		$inner .= '<tr><th scope="row">' . esc_html__( 'Formularios', 'imagina-growth' ) . '</th><td><label><input type="checkbox" name="igr_forms" value="1"' . $checked( (bool) $s['forms'] ) . '> ' . esc_html__( 'Registrar los envíos de formularios como leads', 'imagina-growth' ) . '</label><p class="description">' . esc_html(
			array() === $forms
				? __( 'No se detectó ningún plugin de formularios compatible.', 'imagina-growth' )
				/* translators: %s: lista de plugins de formularios. */
				: sprintf( __( 'Detectados: %s.', 'imagina-growth' ), implode( ', ', $forms ) )
		) . '</p></td></tr>';
		$inner .= '<tr><th scope="row">' . esc_html__( 'Atribución de WhatsApp', 'imagina-growth' ) . '</th><td><label><input type="checkbox" name="igr_wa_ref" value="1"' . $checked( (bool) $s['wa_ref'] ) . '> ' . esc_html__( 'Agregar una referencia corta al mensaje de los enlaces de WhatsApp', 'imagina-growth' ) . '</label>';
		$inner .= '<p><label>' . esc_html__( 'Números del negocio (con indicativo, separados por coma)', 'imagina-growth' ) . '<br><input type="text" class="regular-text" name="igr_wa_numbers" value="' . esc_attr( implode( ', ', (array) $s['wa_numbers'] ) ) . '" placeholder="573001234567"></label></p>';
		$inner .= '<p><label>' . esc_html__( 'Formato del mensaje', 'imagina-growth' ) . '<br><input type="text" class="regular-text" name="igr_wa_format" value="' . esc_attr( (string) $s['wa_format'] ) . '"></label></p><p class="description">' . esc_html__( 'Debe contener {token}; {text} es el mensaje original.', 'imagina-growth' ) . '</p></td></tr>';
		$inner .= '<tr><th scope="row">' . esc_html__( 'Consentimiento de cookies', 'imagina-growth' ) . '</th><td>';
		$chosen = ConsentState::workspace_mode();
		$modes  = array(
			'strict'   => __( 'Estricto: sin aceptar no se guarda ningún identificador', 'imagina-growth' ),
			'informed' => __( 'Informado: se mide desde la primera visita y se respeta el rechazo', 'imagina-growth' ),
		);
		$inner .= '<p><strong>' . esc_html( $modes[ ConsentState::mode() ] ?? '' ) . '</strong><br><span class="description">' . esc_html(
			'auto' === $chosen
				? __( 'Según el sitio, como está elegido en el panel de Imagina Growth: estricto si hay aviso de cookies, informado si no.', 'imagina-growth' )
				: __( 'Elegido en el panel de Imagina Growth (Consentimiento).', 'imagina-growth' )
		) . '</span></p>';
		if ( 'strict' === $chosen && 'igr-banner' === $banner && ! $s['banner'] ) {
			$inner .= '<p class="description">' . esc_html__( 'El sitio no tiene aviso de cookies y el modo es estricto: se muestra el aviso liviano de Imagina Growth para que el visitante pueda aceptar.', 'imagina-growth' ) . '</p>';
		}
		if ( in_array( $banner, array( 'none', 'igr-banner' ), true ) ) {
			$inner .= '<label><input type="checkbox" name="igr_banner" value="1"' . $checked( (bool) $s['banner'] ) . '> ' . esc_html__( 'Mostrar un aviso de cookies liviano de Imagina Growth', 'imagina-growth' ) . '</label><p class="description">' . esc_html__( 'Sin aviso, se mide la analítica y nunca se activan las cookies de anuncios.', 'imagina-growth' ) . '</p>';
		} else {
			$inner .= '<input type="hidden" name="igr_banner" value="' . ( $s['banner'] ? '1' : '' ) . '">';
			/* translators: %s: plugin de cookies. */
			$inner .= '<p>' . esc_html( sprintf( __( 'Se respeta la decisión del visitante en %s.', 'imagina-growth' ), $banner ) ) . '</p>';
		}
		$inner .= '</td></tr>';
		$inner .= '<tr><th scope="row">' . esc_html__( 'Dominio de la cookie', 'imagina-growth' ) . '</th><td><input type="text" class="regular-text" name="igr_cookie_domain" value="' . esc_attr( (string) $s['cookie_domain'] ) . '" placeholder=".tienda.co"><p class="description">' . esc_html__( 'Solo si la tienda y el sitio usan subdominios distintos. Vacío = el dominio actual.', 'imagina-growth' ) . '</p></td></tr>';
		$inner .= '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Guardar', 'imagina-growth' ) . '</button></p>';
		self::form( 'igr_settings', $inner );
	}

	/**
	 * Pestaña «En el sitio»: elementos automáticos y cómo poner los slots.
	 */
	private static function render_onsite(): void {
		$s       = Settings::all();
		$trk     = TrackerConfig::get();
		$active  = is_array( $trk['ons'] ?? null ) ? $trk['ons'] : array();
		$checked = static fn( bool $on ): string => $on ? ' checked' : '';
		$rows    = array(
			'bis'        => array( __( '«Avísame cuando vuelva» en los productos agotados', 'imagina-growth' ), 'bis' ),
			'price_drop' => array( __( '«Avísame si baja de precio» en los productos con stock', 'imagina-growth' ), 'pd' ),
			'points'     => array( __( '«Gana N puntos» en la ficha y en el carrito', 'imagina-growth' ), 'points' ),
			'rewards'    => array( __( '«Mis recompensas» en Mi cuenta', 'imagina-growth' ), 'rw' ),
			'wallet'     => array( __( '«Usar saldo» en el checkout', 'imagina-growth' ), 'wallet' ),
			'top_banner' => array( __( 'Banner por segmento arriba de la página', 'imagina-growth' ), 'top' ),
		);
		$inner   = '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Elementos automáticos', 'imagina-growth' ) . '</th><td>';
		foreach ( $rows as $key => [ $label, $flag ] ) {
			$inner .= '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="igr_' . esc_attr( $key ) . '" value="1"' . $checked( (bool) $s[ $key ] ) . '> ' . esc_html( $label ) . '</label>';
			if ( (bool) $s[ $key ] && empty( $active[ $flag ] ) ) {
				$inner .= '<p class="description" style="margin:-4px 0 8px 24px">' . esc_html__( 'Se mostrará cuando el módulo esté activo en el panel de Imagina Growth.', 'imagina-growth' ) . '</p>';
			}
		}
		$inner .= '</td></tr></tbody></table><p><button class="button button-primary">' . esc_html__( 'Guardar', 'imagina-growth' ) . '</button></p>';
		self::form( 'igr_onsite', $inner );
		echo '<h2>' . esc_html__( 'Dónde poner los widgets', 'imagina-growth' ) . '</h2><p>' . esc_html__( 'Los popups, barras y el botón de WhatsApp se configuran en el panel de Imagina Growth y aparecen solos. Para los elementos incrustados usa un bloque «Imagina Growth», un widget de Elementor o un shortcode:', 'imagina-growth' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach (
			array(
				'[igr_form id="wg_…"]'              => __( 'Formulario incrustado', 'imagina-growth' ),
				'[igr_quiz id="wg_…"]'              => __( 'Quiz', 'imagina-growth' ),
				'[igr_reco type="bought_together"]' => __( 'Recomendaciones: para_ti, bought_together, recently_viewed, best_sellers_category, trending o complete_cart', 'imagina-growth' ),
				'[igr_reviews layout="carousel"]'   => __( 'Reseñas: list, carousel, gallery o badge', 'imagina-growth' ),
				'[igr_loyalty]'                     => __( 'Mis recompensas (solo en páginas con sesión)', 'imagina-growth' ),
			) as $code => $what
		) {
			echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $what ) . '</td></tr>';
		}
		echo '</tbody></table>';
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
			'saved'        => __( 'Los ajustes se guardaron.', 'imagina-growth' ),
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
