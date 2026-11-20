<?php

use App\Analytics\Semantic\Period;
use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Agency\Reports\MonthlyReportPdf;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\Detectors\ConversionDropDetector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Middleware\ResolveWorkspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use App\Support\Tenancy\WorkspaceLocale;
use App\Support\Translation\RegionalFileLoader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/*
 * El idioma del espacio de trabajo en el backend (hub/CLAUDE.md §14): `lang/es` es la base; `es_MX` y
 * `es_VE` solo llevan lo que cambia, y lo aplican las peticiones, los jobs, los hallazgos y el PDF.
 */

function widgetPhoneMessage(): string
{
    return 'Escribe el número de WhatsApp con el indicativo del país (por ejemplo, 573001234567).';
}

function localeWorkspace($test, string $locale, string $country): Workspace
{
    $test->owner ??= $test->userWith2fa();
    $ws = $test->workspaceFor($test->owner, 'Negocio '.$country);
    $ws->forceFill(['locale' => $locale, 'country' => $country])->save();

    return $ws;
}

it('es_MX y es_VE se mezclan sobre lang/es: frases, grupos y validación', function () {
    expect(app('translator')->getLoader())->toBeInstanceOf(RegionalFileLoader::class)
        // Frases de lang/es.json que no cambian llegan a los idiomas regionales (Laravel no las hace caer solo).
        ->and(__('roles.owner', [], 'es_MX'))->toBe('Dueño')
        ->and(__('All rights reserved.', [], 'es_VE'))->toBe('Todos los derechos reservados.')
        // Lo que cambia.
        ->and(__(widgetPhoneMessage(), [], 'es'))->toBe(widgetPhoneMessage())
        ->and(__(widgetPhoneMessage(), [], 'es_MX'))->toContain('código del país')->toContain('525512345678')
        ->and(__(widgetPhoneMessage(), [], 'es_VE'))->toContain('584121234567')
        ->and(__('insight.device.desktop', [], 'es_CO'))->toBe('computador')
        ->and(__('insight.device.desktop', [], 'es_MX'))->toBe('computadora')
        ->and(__('insight.device.mobile', [], 'es_MX'))->toBe('celular')
        ->and(__('validation.required', ['attribute' => 'correo'], 'es_VE'))->toBe(__('validation.required', ['attribute' => 'correo'], 'es'))
        ->and(app()->getFallbackLocale())->toBe('es')
        ->and(RegionalFileLoader::baseOf('es_MX'))->toBe('es')
        ->and(RegionalFileLoader::baseOf('es'))->toBeNull();
});

it('cada llave de lang/es_MX y lang/es_VE existe en lang/es o en el código, con sus marcadores, y cambia el texto', function () {
    $base = json_decode((string) file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);
    // Las frases sin traducción en es.json usan su propio texto como llave: deben estar escritas en el código.
    $code = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])->map(fn ($f) => $f->getContents())->implode("\n");
    $markers = fn (string $text): array => collect(preg_match_all('/:[a-z_]+/i', $text, $m) ? $m[0] : [])->unique()->sort()->values()->all();
    foreach (['es_MX', 'es_VE'] as $locale) {
        $lines = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        foreach ($lines as $key => $text) {
            $original = $base[$key] ?? $key;
            expect(array_key_exists($key, $base) || str_contains($code, $key))->toBeTrue("{$locale}.json: «{$key}» no está en es.json ni en el código")
                ->and($text)->not->toBe($original)
                ->and($markers($text))->toBe($markers($original));
        }
        foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $file) {
            $group = basename($file, '.php');
            foreach (Arr::dot(require $file) as $key => $text) {
                expect(Lang::has("{$group}.{$key}", 'es', false))->toBeTrue("{$locale}/{$group}.{$key} no existe en lang/es")
                    ->and($text)->not->toBe(__("{$group}.{$key}", [], 'es'));
            }
        }
    }
});

it('WorkspaceLocale da el idioma de Laravel y lo restaura, también si el callback falla', function () {
    $mx = localeWorkspace($this, 'es-MX', 'MX');
    $raro = localeWorkspace($this, 'pt-BR', 'CO');
    expect(WorkspaceLocale::of($mx))->toBe('es_MX')
        ->and(WorkspaceLocale::of($raro))->toBe(config('app.locale'))
        ->and(WorkspaceLocale::run($mx, fn () => app()->getLocale()))->toBe('es_MX')
        ->and(app()->getLocale())->toBe('es');

    expect(fn () => WorkspaceLocale::run($mx, fn () => throw new RuntimeException('falla')))->toThrow(RuntimeException::class);
    expect(app()->getLocale())->toBe('es');
});

it('las peticiones del espacio de trabajo responden en su idioma y lo dejan como estaba', function () {
    Route::middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
        ->get('/api/w/{workspace}/__idioma', fn () => response()->json(['locale' => app()->getLocale(), 'texto' => __('insight.device.desktop'), 'error' => __(widgetPhoneMessage())]));

    $ve = localeWorkspace($this, 'es-VE', 'VE');
    $co = localeWorkspace($this, 'es-CO', 'CO');
    $this->actingAs($this->owner)->getJson("/api/w/{$ve->publicId()}/__idioma")->assertOk()
        ->assertJsonPath('locale', 'es_VE')->assertJsonPath('texto', 'computadora')->assertJsonPath('error', __(widgetPhoneMessage(), [], 'es_VE'));
    expect(app()->getLocale())->toBe('es');
    $this->actingAs($this->owner)->getJson("/api/w/{$co->publicId()}/__idioma")->assertOk()
        ->assertJsonPath('locale', 'es_CO')->assertJsonPath('texto', 'computador')->assertJsonPath('error', widgetPhoneMessage());
});

it('los jobs del espacio de trabajo corren en su idioma y lo restauran al terminar', function () {
    $mx = localeWorkspace($this, 'es-MX', 'MX');
    $job = new class($mx->id) implements WorkspaceAware
    {
        public function __construct(private readonly string $ws) {}

        public function workspaceId(): string
        {
            return $this->ws;
        }
    };
    $seen = app(SetWorkspaceContext::class)->handle($job, fn () => [app()->getLocale(), app(CurrentWorkspace::class)->id(), __('insight.device.desktop')]);
    expect($seen)->toBe(['es_MX', $mx->id, 'computadora'])->and(app()->getLocale())->toBe('es');

    expect(fn () => app(SetWorkspaceContext::class)->handle($job, fn () => throw new RuntimeException('falla')))->toThrow(RuntimeException::class);
    expect(app()->getLocale())->toBe('es');
});

it('los hallazgos se redactan en el idioma del espacio de trabajo', function () {
    $mx = localeWorkspace($this, 'es-MX', 'MX');
    app()->instance(ConversionDropDetector::class, new class implements Detector
    {
        public function key(): string
        {
            return 'conversion_drop';
        }

        public function detect(Workspace $workspace, CarbonImmutable $now): array
        {
            return [new InsightCandidate('device:desktop', 'warning', __('La conversión bajó en :device', ['device' => __('insight.device.desktop')]), ['locale' => app()->getLocale()])];
        }
    });

    app(InsightEngine::class)->run($mx, CarbonImmutable::now(), true, ['conversion_drop']);
    $insight = app(CurrentWorkspace::class)->run($mx, fn () => Insight::query()->where('detector_key', 'conversion_drop')->firstOrFail());
    expect($insight->title)->toBe('La conversión bajó en computadora')
        ->and($insight->facts['locale'])->toBe('es_MX')
        ->and(app()->getLocale())->toBe('es');
});

it('el PDF del reporte mensual se arma en el idioma del espacio de trabajo', function () {
    Storage::fake('local');
    $ve = localeWorkspace($this, 'es-VE', 'VE');
    $seen = [];
    View::composer('agency.monthly-report-pdf', function ($view) use (&$seen): void {
        $seen = [app()->getLocale(), $view->getData()['lang'] ?? null];
    });
    $report = new MonthlyReport(['period' => CarbonImmutable::parse('2026-11-01'), 'facts' => ['negocio' => $ve->name, 'mes' => 'noviembre de 2026', 'moneda' => 'USD']]);

    $path = app(MonthlyReportPdf::class)->render($ve, $report);
    expect($seen)->toBe(['es_VE', 'es-VE'])
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(app()->getLocale())->toBe('es');
});

it('los correos de Laravel (pie, saludo, restablecer la contraseña) salen en español', function () {
    $user = $this->userWith2fa();
    foreach (['es', 'es_MX', 'es_VE'] as $locale) {
        app()->setLocale($locale);
        $html = (string) (new ResetPassword('token-de-prueba'))->toMail($user)->render();
        expect($html)->toContain('Todos los derechos reservados.')->toContain('¡Hola!')->toContain('Saludos,')
            ->toContain('Restablecer la contraseña')->toContain('Si el botón «Restablecer la contraseña» no funciona')
            ->not->toContain('All rights reserved')->not->toContain('Hello!')->not->toContain('trouble clicking');
    }
    app()->setLocale('es');
    expect((new ResetPassword('t'))->toMail($user)->subject)->toBe('Restablece tu contraseña');
});

it('el motivo de festivo dice «feriado» en Venezuela', function () {
    $ve = localeWorkspace($this, 'es-VE', 'VE');
    expect(PolicyDecision::label(PolicyDecision::HOLIDAY))->toStartWith('Festivo')
        ->and(WorkspaceLocale::run($ve, fn () => PolicyDecision::label(PolicyDecision::HOLIDAY)))->toBe('Feriado: no se envían mensajes comerciales');
});

it('las semanas empiezan el lunes en todos los idiomas, aunque Carbon tome el domingo en es_CO, es_MX y es_VE', function () {
    $mx = localeWorkspace($this, 'es-MX', 'MX');
    [$carbon, $buckets] = WorkspaceLocale::run($mx, fn () => [
        CarbonImmutable::parse('2026-11-20')->startOfWeek()->toDateString(),
        Period::make('America/Mexico_City', '2026-06-03', '2026-11-20', 'none', 'week')->buckets(),
    ]);
    // Lo que haría Carbon con el idioma del espacio de trabajo: por eso el código nunca lo llama sin el día.
    expect($carbon)->toBe('2026-11-15')
        ->and($buckets[0])->toBe('2026-06-01')
        ->and(collect($buckets)->every(fn (string $d) => CarbonImmutable::parse($d)->isMonday()))->toBeTrue();

    $loose = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => preg_match('/(startOfWeek|endOfWeek)\(\)/', $f->getContents()) === 1)
        ->map(fn ($f) => $f->getRelativePathname())->values()->all();
    expect($loose)->toBe([]);
});
