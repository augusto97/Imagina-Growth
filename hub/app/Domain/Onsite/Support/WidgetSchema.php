<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Onsite\Models\OnsiteWidget;

/**
 * Forma de la configuración de un widget (M12) y sus valores por defecto por tipo.
 * La valida y normaliza WidgetValidator; la publica PublicWidgetConfig.
 *
 * - `targeting`: disparador, páginas, tipos de página, dispositivos, UTM, referido,
 *   países, segmentos, valor del carrito, visitante nuevo, suscriptores y frecuencia.
 * - `variants`: `[{key, weight, design, content}]`; la primera es la base (se copia en
 *   `design` y `content`).
 * - `consent_config`: `{channels, double_opt_in, template_id}`.
 * - `coupon_config`: `{enabled, kind, amount, expires_in_days, prefix, minimum_amount}`
 *   o, en la ruleta, `{prizes: [{key, label, weight, coupon|null}]}`.
 */
final class WidgetSchema
{
    public const TRIGGERS = ['exit_intent', 'time', 'scroll', 'immediate', 'click'];

    /** Tipos de página del contrato (eventos.md §3). El checkout nunca muestra widgets. */
    public const PAGE_TYPES = ['home', 'product', 'category', 'cart', 'thankyou', 'search', 'account', 'blog', 'landing', 'other'];

    public const DEVICES = ['mobile', 'desktop', 'tablet'];

    public const FIELD_KEYS = ['email' => 'email', 'phone' => 'tel', 'first_name' => 'text', 'last_name' => 'text'];

    public const CONSENT_CHANNELS = ['email', 'whatsapp', 'sms'];

    public const COUPON_KINDS = ['percent', 'fixed_cart', 'free_shipping'];

    public const POSITIONS = [
        'popup' => ['center', 'corner', 'fullscreen'],
        'bar' => ['top', 'bottom'],
        'teaser' => ['left', 'right'],
        'wa_widget' => ['right', 'left'],
    ];

    /** @return array<string, mixed> */
    public static function defaults(string $kind, string $brand = ''): array
    {
        $email = ['key' => 'email', 'type' => 'email', 'required' => true, 'label' => __('Tu correo')];
        $phone = ['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => __('Tu WhatsApp')];
        $name = ['key' => 'first_name', 'type' => 'text', 'required' => false, 'label' => __('Tu nombre')];
        $theme = ['background' => '#FFFFFF', 'text' => '#111827', 'accent' => '#4F46E5', 'accent_text' => '#FFFFFF'];
        $frequency = ['max_views' => 2, 'cooldown_days' => 7];
        $targeting = [
            'trigger' => ['type' => 'time', 'value' => 8],
            'pages' => ['include' => [], 'exclude' => []],
            'page_types' => [], 'devices' => [], 'new_visitors' => null, 'utm' => [], 'referral' => false,
            'countries' => [], 'segments' => [], 'cart_value_min' => null, 'hide_for_subscribers' => true, 'frequency' => $frequency,
        ];
        $gift = ['enabled' => true, 'kind' => 'percent', 'amount' => '10', 'expires_in_days' => 7, 'prefix' => 'BIENVENIDA', 'minimum_amount' => null];

        $config = match ($kind) {
            'popup' => [
                'design' => ['position' => 'center', 'layout' => 'image_left', 'image_url' => null, 'theme' => $theme],
                'content' => ['title' => __('10% en tu primera compra'), 'body' => __('Déjanos tu WhatsApp y te enviamos el cupón al instante.'),
                    'fields' => [$name, $phone], 'cta' => __('Quiero mi cupón'), 'success' => __('¡Listo! Tu cupón es {{codigo}}'), 'footer' => ''],
                'consent' => ['channels' => ['whatsapp'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => $gift,
            ],
            'embedded_form' => [
                'design' => ['layout' => 'text_only', 'image_url' => null, 'theme' => $theme],
                'content' => ['title' => __('Suscríbete'), 'body' => __('Recibe novedades y ofertas antes que nadie.'),
                    'fields' => [$name, $email], 'cta' => __('Suscribirme'), 'success' => __('¡Gracias por suscribirte!'), 'footer' => ''],
                'consent' => ['channels' => ['email'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => ['enabled' => false] + $gift,
                'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null], 'hide_for_subscribers' => false, 'frequency' => ['max_views' => 0, 'cooldown_days' => 0]],
            ],
            'bar' => [
                'design' => ['position' => 'top', 'theme' => ['background' => '#111827', 'text' => '#FFFFFF', 'accent' => '#FACC15', 'accent_text' => '#111827']],
                'content' => ['title' => __('Envío gratis desde $150.000. Suscríbete y recibe 10%.'), 'body' => '',
                    'fields' => [$email], 'cta' => __('Quiero el 10%'), 'success' => __('Tu cupón: {{codigo}}'), 'footer' => ''],
                'consent' => ['channels' => ['email'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => $gift,
                'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null]],
            ],
            'spin_wheel' => [
                'design' => ['position' => 'center', 'theme' => $theme],
                'content' => ['title' => __('Gira la ruleta y gana'), 'body' => __('Escribe tu WhatsApp, gira y descubre tu premio.'),
                    'fields' => [$phone], 'cta' => __('Girar'), 'success' => __('¡Ganaste {{premio}}! Tu cupón es {{codigo}}'),
                    'lose' => __('Esta vez no hubo premio. ¡Gracias por participar!'), 'footer' => ''],
                'consent' => ['channels' => ['whatsapp'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => ['prizes' => [
                    ['key' => 'p5', 'label' => '5%', 'weight' => 50, 'coupon' => ['kind' => 'percent', 'amount' => '5', 'expires_in_days' => 3, 'prefix' => 'RULETA', 'minimum_amount' => null]],
                    ['key' => 'p10', 'label' => '10%', 'weight' => 30, 'coupon' => ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 3, 'prefix' => 'RULETA', 'minimum_amount' => null]],
                    ['key' => 'envio', 'label' => __('Envío gratis'), 'weight' => 15, 'coupon' => ['kind' => 'free_shipping', 'amount' => '0', 'expires_in_days' => 3, 'prefix' => 'RULETA', 'minimum_amount' => null]],
                    ['key' => 'p20', 'label' => '20%', 'weight' => 5, 'coupon' => ['kind' => 'percent', 'amount' => '20', 'expires_in_days' => 3, 'prefix' => 'RULETA', 'minimum_amount' => null]],
                ]],
                'targeting' => ['trigger' => ['type' => 'exit_intent', 'value' => null]],
            ],
            'teaser' => [
                'design' => ['position' => 'left', 'theme' => $theme],
                'content' => ['title' => __('¿Quieres 10%?'), 'body' => __('Déjanos tu correo y te lo enviamos.'),
                    'fields' => [$email], 'cta' => __('Lo quiero'), 'success' => __('Tu cupón: {{codigo}}'), 'footer' => ''],
                'consent' => ['channels' => ['email'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => $gift,
                'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null]],
            ],
            'two_step' => [
                'design' => ['position' => 'center', 'layout' => 'image_left', 'image_url' => null, 'theme' => $theme],
                'content' => ['title' => __('10% en tu primera compra'), 'body' => __('Escribe tu correo para recibir el cupón.'),
                    'fields' => [$email], 'cta' => __('Continuar'), 'success' => __('Tu cupón es {{codigo}}'), 'footer' => '',
                    'step2' => ['title' => __('¿Te lo enviamos también por WhatsApp?'), 'body' => __('Te avisamos de ofertas y del estado de tus pedidos.'),
                        'fields' => [$phone], 'cta' => __('Sí, por WhatsApp'), 'success' => __('¡Listo! Ya estás en la lista.'), 'skip' => __('No, gracias')]],
                'consent' => ['channels' => ['email', 'whatsapp'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => $gift,
            ],
            'quiz' => [
                'design' => ['position' => 'center', 'display' => 'popup', 'theme' => $theme],
                'content' => ['title' => __('Encuentra tu producto ideal'), 'body' => __('Responde 3 preguntas y te recomendamos lo mejor para ti.'),
                    'fields' => [$name, $email], 'cta' => __('Ver mi resultado'), 'success' => __('Esto es lo que te recomendamos'), 'footer' => '',
                    'start' => __('Empezar'), 'contact_optional' => false],
                'consent' => ['channels' => ['email'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => ['enabled' => false] + $gift,
                'targeting' => ['trigger' => ['type' => 'click', 'value' => null], 'hide_for_subscribers' => false],
            ],
            'wa_widget' => [
                'design' => ['position' => 'right', 'theme' => ['background' => '#25D366', 'text' => '#FFFFFF', 'accent' => '#25D366', 'accent_text' => '#FFFFFF']],
                'content' => ['title' => __('¿Te ayudamos? Escríbenos'), 'body' => '', 'fields' => [], 'cta' => __('Abrir WhatsApp'), 'success' => '', 'footer' => '',
                    'wa' => ['number' => '', 'text' => __('Hola, quiero información')]],
                'consent' => ['channels' => [], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => ['enabled' => false] + $gift,
                'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null], 'hide_for_subscribers' => false, 'frequency' => ['max_views' => 0, 'cooldown_days' => 0]],
            ],
            'referral_welcome' => [
                'design' => ['position' => 'center', 'layout' => 'text_only', 'image_url' => null, 'theme' => $theme],
                'content' => ['title' => __('Tu amigo te regaló un descuento'), 'body' => __('Déjanos tu WhatsApp y te enviamos el regalo para tu primera compra.'),
                    'fields' => [$name, $phone], 'cta' => __('Recibir mi regalo'), 'success' => __('¡Listo! Tu cupón es {{codigo}}'), 'footer' => ''],
                'consent' => ['channels' => ['whatsapp'], 'double_opt_in' => false, 'template_id' => null],
                'coupon' => ['enabled' => false] + $gift,
                'targeting' => ['trigger' => ['type' => 'time', 'value' => 3], 'referral' => true, 'new_visitors' => null, 'hide_for_subscribers' => true],
            ],
            default => throw new \InvalidArgumentException("Tipo de widget desconocido: {$kind}"),
        };

        return [
            'targeting' => array_replace($targeting, $config['targeting'] ?? []),
            'design' => $config['design'],
            'content' => $config['content'],
            'consent_config' => $config['consent'],
            'coupon_config' => $config['coupon'],
            'quiz' => $kind === 'quiz' ? self::sampleQuiz() : null,
        ];
    }

    /** @return array{questions: list<array<string, mixed>>, outcomes: array<string, mixed>} */
    public static function sampleQuiz(): array
    {
        return [
            'questions' => [
                ['id' => 'q1', 'text' => __('¿Para qué lo necesitas?'), 'attribute' => 'quiz_uso', 'options' => [
                    ['key' => 'casa', 'label' => __('Para mi casa'), 'next' => 'q2'],
                    ['key' => 'negocio', 'label' => __('Para mi negocio'), 'next' => 'q2'],
                ]],
                ['id' => 'q2', 'text' => __('¿Qué es lo más importante para ti?'), 'attribute' => 'quiz_prioridad', 'options' => [
                    ['key' => 'precio', 'label' => __('El precio'), 'next' => 'end'],
                    ['key' => 'calidad', 'label' => __('La calidad'), 'next' => 'end'],
                ]],
            ],
            'outcomes' => [
                'rules' => [],
                'fallback' => ['products' => ['product_ids' => [], 'category_ids' => [], 'brands' => [], 'max_price' => null, 'kind' => 'trending'], 'message' => ''],
                'limit' => 4,
            ],
        ];
    }

    /** ¿El widget entrega cupón (regalo fijo o premio de la ruleta)? */
    public static function givesCoupon(OnsiteWidget $widget): bool
    {
        $config = $widget->coupon_config ?? [];
        if ($widget->kind === 'spin_wheel') {
            foreach ((array) ($config['prizes'] ?? []) as $prize) {
                if (is_array($prize) && is_array($prize['coupon'] ?? null)) {
                    return true;
                }
            }

            return false;
        }

        return (bool) ($config['enabled'] ?? false);
    }

    /**
     * Variantes completas (la primera es la base).
     *
     * @return list<array{key: string, weight: int, design: array<string, mixed>, content: array<string, mixed>}>
     */
    public static function variants(OnsiteWidget $widget): array
    {
        $out = [];
        foreach ((array) ($widget->variants ?? []) as $variant) {
            if (! is_array($variant) || ! is_string($variant['key'] ?? null)) {
                continue;
            }
            $out[] = [
                'key' => $variant['key'],
                'weight' => max(0, (int) ($variant['weight'] ?? 0)),
                'design' => is_array($variant['design'] ?? null) ? $variant['design'] : ($widget->design ?? []),
                'content' => is_array($variant['content'] ?? null) ? $variant['content'] : ($widget->content ?? []),
            ];
        }

        return $out !== [] ? $out : [['key' => 'a', 'weight' => 100, 'design' => $widget->design ?? [], 'content' => $widget->content ?? []]];
    }

    /**
     * La variante pedida, o la base si no existe.
     *
     * @return array{key: string, weight: int, design: array<string, mixed>, content: array<string, mixed>}
     */
    public static function variant(OnsiteWidget $widget, ?string $key): array
    {
        $variants = self::variants($widget);
        foreach ($variants as $variant) {
            if ($variant['key'] === $key) {
                return $variant;
            }
        }

        return $variants[0];
    }
}
