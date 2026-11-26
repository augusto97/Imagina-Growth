<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Capture\Support\WhatsAppNumber;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Galería de plantillas de «Popups y formularios» (modulos.md M12, rediseño v2): un widget listo para un objetivo
 * —capturar WhatsApp con cupón, retener a quien se va, quiz, encuesta, formulario de contacto, botón de WhatsApp— que
 * se crea con un clic. Cada plantilla es el tipo de widget con sus valores por defecto (`WidgetSchema::defaults`) más
 * los textos, el disparador y el cupón de su objetivo; `CreateWidget` la deja en borrador para revisarla y prenderla.
 *
 * `audience`: `store` (tiene cupón, catálogo o carrito), `services` (sin tienda) o `both`. El panel muestra primero
 * las de su tipo de negocio y nunca ofrece cupones a un negocio sin tienda (el cupón lo crea WooCommerce).
 */
final class WidgetTemplates
{
    /** @var array<string, array{kind: string, objective: string, audience: 'store'|'services'|'both'}> */
    public const ALL = [
        'whatsapp_coupon' => ['kind' => 'popup', 'objective' => 'capture', 'audience' => 'store'],
        'exit_coupon' => ['kind' => 'popup', 'objective' => 'exit', 'audience' => 'store'],
        'spin_wheel' => ['kind' => 'spin_wheel', 'objective' => 'exit', 'audience' => 'store'],
        'product_quiz' => ['kind' => 'quiz', 'objective' => 'quiz', 'audience' => 'store'],
        'survey' => ['kind' => 'quiz', 'objective' => 'survey', 'audience' => 'store'],
        'two_step' => ['kind' => 'two_step', 'objective' => 'capture', 'audience' => 'store'],
        'newsletter' => ['kind' => 'embedded_form', 'objective' => 'capture', 'audience' => 'store'],
        'shipping_bar' => ['kind' => 'bar', 'objective' => 'announce', 'audience' => 'store'],
        'contact_form' => ['kind' => 'embedded_form', 'objective' => 'contact', 'audience' => 'services'],
        'wa_button' => ['kind' => 'wa_widget', 'objective' => 'contact', 'audience' => 'both'],
        'callback' => ['kind' => 'popup', 'objective' => 'capture', 'audience' => 'services'],
        'exit_info' => ['kind' => 'popup', 'objective' => 'exit', 'audience' => 'services'],
    ];

    /** @return list<array{key: string, kind: string, objective: string, audience: string, gives_coupon: bool}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::ALL as $key => $template) {
            $out[] = ['key' => $key] + $template + ['gives_coupon' => in_array($key, ['whatsapp_coupon', 'exit_coupon', 'spin_wheel', 'two_step', 'shipping_bar'], true)];
        }

        return $out;
    }

    public static function kind(string $key): ?string
    {
        return self::ALL[$key]['kind'] ?? null;
    }

    /**
     * Lo que cambia frente a los valores por defecto del tipo, para `CreateWidget`.
     *
     * @return array{content?: array<string, mixed>, consent_config?: array<string, mixed>, coupon_config?: array<string, mixed>, targeting?: array<string, mixed>, design?: array<string, mixed>, quiz?: array<string, mixed>}
     */
    public static function overrides(string $key, Workspace $workspace): array
    {
        $name = ['key' => 'first_name', 'type' => 'text', 'required' => false, 'label' => __('Tu nombre')];
        $nameRequired = ['required' => true] + $name;
        $phone = ['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => __('Tu WhatsApp')];
        $email = ['key' => 'email', 'type' => 'email', 'required' => true, 'label' => __('Tu correo')];
        $noCoupon = ['enabled' => false];
        $once = ['max_views' => 1, 'cooldown_days' => 14];

        return match ($key) {
            'exit_coupon' => [
                'content' => [
                    'title' => __('¡Espera! Llévate 10% antes de irte'),
                    'body' => __('Déjanos tu WhatsApp y te enviamos el cupón para que termines tu compra cuando quieras.'),
                    'cta' => __('Enviarme el cupón'),
                ],
                'targeting' => ['trigger' => ['type' => 'exit_intent', 'value' => null], 'frequency' => $once],
                'design' => ['layout' => 'text_only'],
            ],
            'survey' => [
                'content' => [
                    'title' => __('Cuéntanos qué buscas'),
                    'body' => __('Dos preguntas rápidas para mostrarte lo que de verdad te sirve.'),
                    'start' => __('Responder'),
                    'cta' => __('Ver lo que te recomendamos'),
                    'success' => __('¡Gracias! Esto es lo que te recomendamos'),
                    'contact_optional' => true,
                    'fields' => [$name, ['required' => false] + $email],
                ],
                'targeting' => ['trigger' => ['type' => 'scroll', 'value' => 50], 'frequency' => $once],
                'quiz' => [
                    'questions' => [
                        ['id' => 'q1', 'text' => __('¿Qué te trae hoy por aquí?'), 'attribute' => 'encuesta_motivo', 'options' => [
                            ['key' => 'comprar', 'label' => __('Quiero comprar algo'), 'next' => 'q2'],
                            ['key' => 'comparar', 'label' => __('Estoy comparando precios'), 'next' => 'q2'],
                            ['key' => 'mirar', 'label' => __('Solo estoy mirando'), 'next' => 'q2'],
                        ]],
                        ['id' => 'q2', 'text' => __('¿Qué es lo más importante para ti?'), 'attribute' => 'encuesta_prioridad', 'options' => [
                            ['key' => 'precio', 'label' => __('El precio'), 'next' => 'end'],
                            ['key' => 'calidad', 'label' => __('La calidad'), 'next' => 'end'],
                            ['key' => 'rapidez', 'label' => __('Que llegue rápido'), 'next' => 'end'],
                        ]],
                    ],
                    'outcomes' => [
                        'rules' => [],
                        'fallback' => ['products' => ['product_ids' => [], 'category_ids' => [], 'brands' => [], 'max_price' => null, 'kind' => 'trending'], 'message' => ''],
                        'limit' => 4,
                    ],
                ],
            ],
            'contact_form' => [
                'content' => [
                    'title' => __('Escríbenos y te respondemos hoy'),
                    'body' => __('Déjanos tus datos y una persona del equipo te contacta por WhatsApp.'),
                    'fields' => [$nameRequired, $phone, ['required' => false] + $email],
                    'cta' => __('Quiero que me contacten'),
                    'success' => __('¡Gracias! Te escribimos muy pronto.'),
                ],
                'consent_config' => ['channels' => ['whatsapp', 'email']],
                'coupon_config' => $noCoupon,
            ],
            'callback' => [
                'content' => [
                    'title' => __('¿Te ayudamos a elegir?'),
                    'body' => __('Déjanos tu WhatsApp y te escribimos en horario de atención para resolver tus dudas, sin compromiso.'),
                    'fields' => [$nameRequired, $phone],
                    'cta' => __('Sí, escríbanme'),
                    'success' => __('¡Listo! Te escribimos muy pronto.'),
                ],
                'consent_config' => ['channels' => ['whatsapp']],
                'coupon_config' => $noCoupon,
                'targeting' => ['trigger' => ['type' => 'scroll', 'value' => 50], 'frequency' => $once],
                'design' => ['layout' => 'text_only'],
            ],
            'exit_info' => [
                'content' => [
                    'title' => __('Antes de irte, ¿te enviamos la información?'),
                    'body' => __('Déjanos tu WhatsApp y te mandamos precios y disponibilidad, sin compromiso.'),
                    'fields' => [$name, $phone],
                    'cta' => __('Enviarme la información'),
                    'success' => __('¡Listo! Te escribimos muy pronto.'),
                ],
                'consent_config' => ['channels' => ['whatsapp']],
                'coupon_config' => $noCoupon,
                'targeting' => ['trigger' => ['type' => 'exit_intent', 'value' => null], 'frequency' => $once],
                'design' => ['layout' => 'text_only'],
            ],
            'wa_button' => [
                'content' => ['wa' => [
                    'number' => WhatsAppNumber::digits($workspace, WhatsAppNumber::account(null)) ?? '',
                    'text' => __('Hola, quiero información'),
                ]],
            ],
            default => [],
        };
    }
}
