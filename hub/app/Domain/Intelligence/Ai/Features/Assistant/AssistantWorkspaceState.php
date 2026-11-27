<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Queries\SetupStatus;

/**
 * Cómo está la cuenta, en palabras, para que «Pregúntale a la IA» responda «¿cómo empiezo?» con lo que de verdad falta
 * (inteligencia.md §6.3). Traduce `SetupStatus` (la consulta reutilizable, sin IA ni datos de contactos) a hechos que
 * el modelo entiende sin adivinar claves: tipo de negocio, qué está conectado, el asistente de configuración, las
 * estrategias prendidas y las recomendadas con su nombre y lo que les falta. Se arma con el rol de la petición (antes
 * de `CurrentWorkspace::run`).
 */
final class AssistantWorkspaceState
{
    /** @return array<string, mixed> */
    public function facts(Workspace $workspace, bool $canManage): array
    {
        return self::describe(SetupStatus::for($workspace), $canManage);
    }

    /**
     * @param  array<string, mixed>  $status  lo de `SetupStatus`
     * @return array<string, mixed>
     */
    public static function describe(array $status, bool $canManage): array
    {
        $facts = [
            'negocio' => [
                'tipo' => match ($status['workspace']['business_type'] ?? null) {
                    'store' => 'tienda en línea',
                    'services' => 'servicios (sin tienda en línea)',
                    'both' => 'tienda en línea y servicios',
                    default => 'sin definir',
                },
                'pais' => $status['workspace']['country'] ?? null,
                'moneda' => $status['workspace']['currency'] ?? null,
            ],
            'puede_configurar' => $canManage,
        ];
        if (is_array($status['site'] ?? null)) {
            $facts['web'] = match ($status['site']['state']) {
                'connected' => 'conectada',
                'pending' => 'pendiente: falta pegar el código de conexión en el plugin',
                'disconnected' => 'desconectada: hay que volver a conectarla',
                default => 'sin conectar',
            };
            $facts['tienda_woocommerce'] = (bool) $status['site']['store'];
            $facts['historial_importado'] = match ($status['site']['history_import']) {
                'completed' => 'listo',
                'running' => 'en curso',
                'failed' => 'falló: revisa la tarjeta de tu web',
                'none' => 'sin importar',
                default => 'no aplica',
            };
        }
        if (is_array($status['channels'] ?? null)) {
            $wa = $status['channels']['whatsapp'];
            $facts['whatsapp'] = match ($wa['state']) {
                'active' => $wa['kind'] === 'official' ? 'oficial (API de Meta)' : 'no oficial (solo texto, sin campañas ni automatizaciones de marketing)',
                'down' => 'desconectado: hay que reconectarlo',
                default => 'sin conectar',
            };
            foreach (['email' => 'correo', 'sms' => 'sms', 'messenger' => 'messenger', 'instagram' => 'instagram'] as $key => $label) {
                $facts[$label] = match ($status['channels'][$key]['state'] ?? 'none') {
                    'active' => 'conectado',
                    'down' => 'con problemas: hay que revisarlo',
                    default => 'sin conectar',
                };
            }
        }
        if (is_array($status['ads'] ?? null)) {
            $facts['anuncios_conectados'] = array_keys(array_filter(['Meta' => $status['ads']['meta'], 'Google' => $status['ads']['google'], 'TikTok' => $status['ads']['tiktok']]));
        }
        if (is_array($status['agenda'] ?? null) && ($status['agenda']['services'] > 0 || ($status['workspace']['business_type'] ?? null) !== 'store')) {
            $facts['agenda'] = ['servicios_para_reservar' => $status['agenda']['services'], 'calendarios_de_google_conectados' => $status['agenda']['calendars']];
        }
        if (is_array($status['setup'] ?? null)) {
            $facts['configuracion_inicial'] = [
                'terminada' => (bool) $status['setup']['finished'],
                'pasos_hechos' => (int) $status['setup']['completed'],
                'pasos_total' => (int) $status['setup']['total'],
            ];
        }
        if (is_array($status['strategies'] ?? null)) {
            $name = fn (string $key): string => (string) __('strategies.items.'.$key.'.name');
            $facts['estrategias_activas'] = array_map(fn (string $k) => ['clave' => $k, 'nombre' => $name($k)], $status['strategies']['active']);
            $facts['estrategias_recomendadas'] = array_map(fn (array $s) => [
                'clave' => $s['key'],
                'nombre' => $name($s['key']),
                'para_que' => (string) __('strategies.items.'.$s['key'].'.promise'),
                'lista_para_prender' => $s['status'] === 'available',
                'le_falta' => array_map(fn (string $r) => $r === 'module'
                    ? __('Que tu plan incluya esta función')
                    : (string) __('strategies.requirements.'.$r.'.label'), array_slice($s['missing'], 0, 3)),
            ], $status['strategies']['recommended']);
        }

        return $facts;
    }
}
