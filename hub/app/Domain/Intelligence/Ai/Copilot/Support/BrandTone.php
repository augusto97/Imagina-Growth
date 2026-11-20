<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Net\SafeHttp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tono de marca (inteligencia.md §6.5): lo aprende de la portada y de la página
 * «Nosotros» del sitio conectado y de los textos de las plantillas ya aprobadas,
 * y lo guarda en `workspaces.settings.brand_tone` por 7 días. El copiloto y el
 * redactor lo usan en cada propuesta.
 */
final class BrandTone
{
    public const TTL_DAYS = 7;

    private const ABOUT_PATHS = ['/nosotros/', '/quienes-somos/', '/sobre-nosotros/', '/about/', '/about-us/'];

    public function __construct(private readonly AiGateway $ai) {}

    /** @return array<string, mixed>|null el perfil guardado, si está vigente */
    public function cached(Workspace $workspace): ?array
    {
        $tone = data_get($workspace->settings ?? [], 'brand_tone');
        if (! is_array($tone) || ! is_string($tone['updated_at'] ?? null)) {
            return null;
        }

        return CarbonImmutable::parse($tone['updated_at'])->addDays(self::TTL_DAYS)->isFuture() ? $tone : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function learn(Workspace $workspace): array
    {
        $sources = [];
        $texts = [];
        $site = Site::query()->where('workspace_id', $workspace->id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();
        if ($site !== null) {
            $base = rtrim((string) ($site->url ?: 'https://'.$site->host), '/');
            if (($home = $this->page($base.'/')) !== null) {
                $texts[] = "Portada:\n".$home;
                $sources[] = $base.'/';
            }
            foreach (self::ABOUT_PATHS as $path) {
                if (($about = $this->page($base.$path)) !== null) {
                    $texts[] = "Nosotros:\n".$about;
                    $sources[] = $base.$path;
                    break;
                }
            }
        }
        $approved = DB::table('templates')->where('workspace_id', $workspace->id)->where('status', 'ready')
            ->orderByDesc('updated_at')->limit(8)->get(['subject', 'preheader', 'body_text'])
            ->map(fn ($t) => trim(implode(' · ', array_filter([(string) $t->subject, (string) $t->preheader, mb_substr((string) $t->body_text, 0, 300)]))))
            ->filter()->values()->all();
        if ($approved !== []) {
            $texts[] = "Textos aprobados:\n- ".implode("\n- ", $approved);
            $sources[] = 'templates';
        }
        if ($texts === []) {
            throw new AiUnavailable('invalid', __('No hay de dónde aprender el tono: conecta el sitio o aprueba alguna plantilla.'));
        }
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'traits', 'do', 'dont', 'examples'], 'properties' => [
            'summary' => ['type' => 'string'], 'traits' => ['type' => 'array', 'items' => ['type' => 'string']],
            'do' => ['type' => 'array', 'items' => ['type' => 'string']], 'dont' => ['type' => 'array', 'items' => ['type' => 'string']],
            'examples' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
        $result = $this->ai->generate(AiRequest::ask($workspace->id, 'content',
            'Eres editor de marca. Describe en español el tono de voz de esta marca a partir de sus textos: un resumen de 2 frases, de 3 a 5 rasgos, qué hacer, qué evitar y 2 frases de ejemplo con ese tono. No copies datos personales.',
            (string) PiiScrubber::scrub(implode("\n\n", $texts)), $schema));
        $tone = [
            'summary' => mb_substr((string) ($result->json['summary'] ?? ''), 0, 500),
            'traits' => array_slice(array_map('strval', (array) ($result->json['traits'] ?? [])), 0, 6),
            'do' => array_slice(array_map('strval', (array) ($result->json['do'] ?? [])), 0, 6),
            'dont' => array_slice(array_map('strval', (array) ($result->json['dont'] ?? [])), 0, 6),
            'examples' => array_slice(array_map('strval', (array) ($result->json['examples'] ?? [])), 0, 3),
            'sources' => $sources,
            'updated_at' => now()->toIso8601String(),
        ];
        $settings = (array) ($workspace->settings ?? []);
        $settings['brand_tone'] = $tone;
        $workspace->forceFill(['settings' => $settings])->save();

        return $tone;
    }

    /** Texto visible de una página (sin scripts ni estilos), o null si no responde. */
    private function page(string $url): ?string
    {
        try {
            // La URL sale del sitio del cliente: solo direcciones públicas, sin redirecciones (S5).
            $response = SafeHttp::for($url, httpsOnly: false)->timeout(5)->connectTimeout(3)->withHeaders(['User-Agent' => 'ImaginaGrowth/1.0 (tono de marca)'])->get($url);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful() || ! str_contains((string) $response->header('Content-Type'), 'html')) {
            return null;
        }
        $html = (string) preg_replace('#<(script|style|noscript|svg|nav|footer)\b[^>]*>.*?</\1>#is', ' ', $response->body());
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, 3000);
    }
}
