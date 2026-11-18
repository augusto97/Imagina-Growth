/* Conecta la tienda al hub simulado y la deja desconectada al terminar. */
import { HUB, php, wp } from './store';

/** Borra los pedidos de prueba (devolviendo el stock) para no alterar la tienda demo. */
export function cleanup(): string {
    return php(
        'global $wpdb; $n = 0; foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE \'e2e-%@example.com\'") as $id) { $o = wc_get_order((int) $id); if ($o) { wc_increase_stock_levels($o); $o->delete(true); $n++; } }' +
            // Cupones de saldo que creó el hub simulado (Fase 3) y reglas de reescritura sin «Mis recompensas».
            ' foreach (get_posts(array("post_type" => "shop_coupon", "meta_key" => "igr_coupon", "numberposts" => -1, "post_status" => "any")) as $c) { wp_delete_post($c->ID, true); }' +
            ' ImaginaGrowth\\Connector\\Onsite\\Account::sync_rewrite(); echo $n;',
    );
}

export default async function setup(): Promise<() => Promise<void>> {
    await fetch(HUB + '/_reset', { method: 'POST' });
    try {
        wp('igr', 'disconnect');
    } catch {
        /* ya estaba desconectada */
    }
    // El modo «Próximamente» de WooCommerce oculta la tienda a los visitantes: se apaga durante la prueba.
    const comingSoon = wp('option', 'get', 'woocommerce_coming_soon');
    wp('option', 'update', 'woocommerce_coming_soon', 'no');
    wp('igr', 'connect', 'IGR-E2E0-TEST-0001');
    // La importación histórica no hace falta aquí: 10.000 pedidos solo harían lento al hub simulado.
    php('ImaginaGrowth\\Connector\\Backfill\\Runner::pause(true); as_unschedule_all_actions("igr_backfill_step");');
    php('ImaginaGrowth\\Connector\\Connection\\RemoteConfig::refresh();');
    php(
        'ImaginaGrowth\\Connector\\Settings::save(array("tracker" => true, "banner" => false, "wa_ref" => true, "wa_numbers" => "573001234567", "excluded_roles" => null));',
    );
    return async () => {
        try {
            wp('igr', 'disconnect');
        } catch {
            /* nada que hacer */
        }
        wp('option', 'update', 'woocommerce_coming_soon', comingSoon || 'no');
        cleanup();
    };
}
