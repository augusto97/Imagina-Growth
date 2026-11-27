<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

/**
 * «Tus próximos pasos» sin IA, a partir del estado de la cuenta (`AssistantWorkspaceState`): conectar la web, un canal,
 * terminar la configuración y prender las estrategias recomendadas, en ese orden y como mucho 3. El modelo los recibe
 * como sugerencia y el asistente los usa tal cual cuando la IA no deja un texto útil: nunca se queda mudo.
 */
final class AssistantPlan
{
    public const MAX_STEPS = 3;

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{text: string, page: string, ref: string|null, path: string}>
     */
    public static function steps(array $state): array
    {
        $steps = [];
        $add = function (string $text, string $page, ?string $ref = null) use (&$steps): void {
            $link = AssistantPages::link($page, $ref);
            if ($link !== null && count($steps) < self::MAX_STEPS) {
                $steps[] = ['text' => $text] + $link;
            }
        };
        $web = (string) ($state['web'] ?? '');
        $services = str_starts_with((string) ($state['negocio']['tipo'] ?? ''), 'servicios');
        if (str_starts_with($web, 'pendiente')) {
            $add(__('Termina de conectar tu web: pega el código de conexión en el plugin de WordPress.'), 'connections_web');
        } elseif (str_starts_with($web, 'desconectada')) {
            $add(__('Vuelve a conectar tu web: pide un código nuevo y pégalo en el plugin.'), 'connections_web');
        } elseif ($web === 'sin conectar') {
            $add($services
                ? __('Conecta tu web con el plugin de WordPress (o, si no tienes WordPress, crea una página de captura con QR).')
                : __('Conecta tu tienda con el plugin de WordPress para traer tus pedidos y clientes.'), 'connections_web');
        }
        $whatsapp = (string) ($state['whatsapp'] ?? 'sin conectar');
        $email = (string) ($state['correo'] ?? 'sin conectar');
        if (str_starts_with($whatsapp, 'desconectado')) {
            $add(__('Reconecta tu WhatsApp para seguir respondiendo y enviando mensajes.'), 'connections_whatsapp');
        } elseif ($whatsapp === 'sin conectar' && $email !== 'conectado') {
            $add(__('Conecta WhatsApp (o tu correo) para escribirles a tus clientes.'), 'connections_whatsapp');
        }
        if (isset($state['configuracion_inicial']['terminada']) && $state['configuracion_inicial']['terminada'] === false) {
            $add(__('Termina el asistente de configuración: deja tu cuenta lista paso a paso.'), 'setup');
        }
        foreach ((array) ($state['estrategias_recomendadas'] ?? []) as $strategy) {
            if (is_array($strategy) && is_string($strategy['clave'] ?? null)) {
                $add(__('Prende la estrategia «:name».', ['name' => (string) ($strategy['nombre'] ?? $strategy['clave'])]), 'strategy', $strategy['clave']);
            }
        }
        if ($steps === []) {
            $add(__('Revisa tus resultados y lo que generó la plataforma este mes.'), 'results');
            $add(__('Mira las recomendaciones con el dinero en juego y qué hacer.'), 'insights');
        }

        return $steps;
    }

    /**
     * La respuesta sin IA: en palabras, con los pasos aparte (el panel los muestra como enlaces).
     *
     * @param  array<string, mixed>  $state
     */
    public static function answer(array $state): string
    {
        return ($state['puede_configurar'] ?? true) === false
            ? __('Te dejo los próximos pasos según cómo está tu cuenta. Algunos los hace el dueño o un administrador del negocio: compártelos con esa persona.')
            : __('Te dejo los próximos pasos según cómo está tu cuenta. Empieza por el primero; cada uno te lleva a la pantalla donde se hace.');
    }
}
