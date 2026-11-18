/* Conecta la tienda al hub simulado y la deja desconectada al terminar. */
import { HUB, php, wp } from './store';

export default async function setup(): Promise<() => Promise<void>> {
    await fetch(HUB + '/_reset', { method: 'POST' });
    try {
        wp('igr', 'disconnect');
    } catch {
        /* ya estaba desconectada */
    }
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
    };
}
