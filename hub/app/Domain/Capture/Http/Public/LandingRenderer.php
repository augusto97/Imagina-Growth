<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http\Public;

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\LandingReviews;
use App\Domain\Capture\Support\LandingSchema;
use App\Domain\Capture\Support\LandingToken;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arma la página de captura y la de «gracias» con sus datos (M12). Lo usan la página pública y la vista previa del
 * editor, que pinta el borrador sin guardarlo con la misma vista: lo que se ve en el panel es lo que verá el cliente.
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class LandingRenderer
{
    public function __construct(private readonly PublicCapture $public) {}

    /**
     * @param  array<string, string>  $carry
     * @param  array<string, string>  $errors
     * @param  array<mixed>  $old
     */
    public function page(Request $request, Workspace $workspace, LandingPage $page, array $carry, array $errors, array $old, int $status = 200, bool $preview = false): Response
    {
        $texts = collect(app(PublicWidgetConfig::class)->legalConsents($workspace))->keyBy('channel');
        $consents = [];
        foreach ((array) ($page->consent_channels ?? []) as $channel) {
            if (isset($texts[$channel])) {
                $consents[] = ['channel' => (string) $channel, 'label' => (string) $texts[$channel]['label'], 'version' => (string) $texts[$channel]['version'],
                    'after' => $channel === 'email' ? 'email' : 'phone', 'checked' => ! empty(((array) $request->input('consent', []))[$channel] ?? null) && $status !== 200];
            }
        }
        $privacy = $workspace->getAttribute('privacy_policy_url');
        $content = LandingSchema::withDefaults((array) ($page->content ?? []));

        return $this->public->view('capture.page', $workspace, $page, [
            'title' => $page->text('title'),
            'eyebrow' => $page->text('eyebrow'),
            'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $page->text('body')) ?: []), fn ($p) => $p !== '')),
            'benefits' => array_values(array_filter((array) $content['benefits'], fn ($b) => is_string($b) && $b !== '')),
            'faq' => array_values(array_filter((array) $content['faq'], fn ($f) => is_array($f) && is_string($f['q'] ?? null) && $f['q'] !== '')),
            'reviews' => (bool) $content['reviews'] ? LandingReviews::summary($workspace) : null,
            'template' => in_array($content['template'], LandingSchema::TEMPLATES, true) ? $content['template'] : 'split',
            'button' => $page->text('button') ?: __('Enviar'),
            'logo' => self::logo($workspace, $page),
            'image' => $page->text('image_key') !== '' ? CaptureUrls::origin($request, $workspace).'/p/'.$page->slug.'/i/'.$page->text('image_key') : ($page->text('image_url') ?: null),
            'fields' => self::fields($page, $workspace),
            'consents' => $consents,
            'whatsapp' => $this->whatsapp($workspace, $page),
            'action' => CaptureUrls::origin($request, $workspace).'/p/'.$page->slug,
            'token' => $preview ? '' : LandingToken::issue($page),
            'carry' => $carry,
            'errors' => $errors,
            'old' => $old,
            'privacyUrl' => is_string($privacy) && str_starts_with($privacy, 'http') ? $privacy : null,
            'preview' => $preview,
        ], $status);
    }

    public function thanks(Request $request, Workspace $workspace, LandingPage $page, bool $preview = false): Response
    {
        return $this->public->view('capture.thanks', $workspace, $page, [
            'title' => $page->text('success_title') ?: __('¡Gracias! Recibimos tus datos'),
            'message' => $page->text('success_message'),
            'logo' => self::logo($workspace, $page),
            'whatsapp' => $this->whatsapp($workspace, $page),
            'back' => CaptureUrls::origin($request, $workspace).'/p/'.$page->slug,
            'preview' => $preview,
        ]);
    }

    /** @return array{url: string, label: string}|null */
    public function whatsapp(Workspace $workspace, LandingPage $page): ?array
    {
        $wa = (array) (($page->content ?? [])['whatsapp'] ?? []);
        if (! (bool) ($wa['enabled'] ?? false) || $page->wa_link_id === null) {
            return null;
        }
        $link = WhatsAppLink::query()->whereKey($page->wa_link_id)->where('status', 'active')->first();

        return $link === null ? null : ['url' => CaptureUrls::waLink($workspace, $link), 'label' => is_string($wa['label'] ?? null) && $wa['label'] !== '' ? $wa['label'] : __('Escríbenos por WhatsApp')];
    }

    public static function logo(Workspace $workspace, LandingPage $page): ?string
    {
        if ($page->text('logo_key') !== '') {
            return CaptureUrls::page($workspace, $page).'/i/'.$page->text('logo_key');
        }
        $url = $page->text('logo_url') ?: BusinessProfile::for($workspace)['logo_url'];

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Los campos con lo que necesita el HTML (tipo, autocompletado, teclado del celular).
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(LandingPage $page, Workspace $workspace): array
    {
        $out = [];
        foreach ((array) ($page->fields ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $out[] = [
                'key' => $key,
                'id' => 'f-'.str_replace('_', '-', $key),
                'label' => (string) ($field['label'] ?? $key),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => array_values((array) ($field['options'] ?? [])),
                'placeholder' => is_string($field['placeholder'] ?? null) ? $field['placeholder'] : ($key === 'phone' ? self::phoneExample($workspace) : ''),
                'autocomplete' => match ($key) {
                    'name' => 'name', 'email' => 'email', 'phone' => 'tel', default => 'off',
                },
                'inputmode' => match ($key) {
                    'email' => 'email', 'phone' => 'tel', default => null,
                },
            ];
        }

        return $out;
    }

    private static function phoneExample(Workspace $workspace): string
    {
        return match (strtoupper((string) $workspace->country)) {
            'MX' => __('Ej.: 55 1234 5678'),
            'VE' => __('Ej.: 412 123 4567'),
            default => __('Ej.: 300 123 4567'),
        };
    }
}
