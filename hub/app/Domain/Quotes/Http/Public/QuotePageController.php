<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Http\Public;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Bookings\Http\Public\PublicBooking;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Quotes\Actions\SubmitQuote;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Quotes\Support\QuotePage;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La página del cotizador en el hub (M18): `/p/{clave}/cotizar/{qc_…}`. Una pregunta por paso con JavaScript y, sin
 * él, todas en un formulario normal; el precio lo calcula siempre el hub. Sin sesión ni CSRF (routes/public): la
 * protegen el campo trampa y los límites por IP con hash. Al enviar redirige al resultado con un enlace firmado
 * (`/r/{qt_…}/{firma}`), que se puede volver a abrir 30 días.
 */
final class QuotePageController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly PublicBooking $public) {}

    /** GET /p/{key}/cotizar/{qc_…} */
    public function show(Request $request, string $key, string $calculator, PublicWidgetConfig $widgets): Response
    {
        $ctx = $this->context($request, $key, $calculator, 'page', 60);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, , $model] = $ctx;

        return $this->current->run($workspace, fn () => $this->page($request, $workspace, $model, $widgets, [], []));
    }

    /** POST /p/{key}/cotizar/{qc_…} (formulario) */
    public function submit(Request $request, string $key, string $calculator, SubmitQuote $submit, PublicWidgetConfig $widgets): Response
    {
        $ctx = $this->context($request, $key, $calculator, 'submit', 10);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $site, $model] = $ctx;
        $self = QuotePage::origin($request, $workspace).'/p/'.$key.'/cotizar/'.$model->publicId();
        // El campo trampa: un bot lo llena; a la persona no le aparece.
        if (is_string($request->input('website')) && trim($request->input('website')) !== '') {
            return redirect()->to($self, 303);
        }

        return $this->current->run($workspace, function () use ($request, $workspace, $site, $model, $submit, $widgets, $key, $self): Response {
            $answers = QuotePage::answersFrom($request, (array) $model->definition);
            $consents = [];
            foreach ((array) $request->input('consent', []) as $channel => $checked) {
                $consents[(string) $channel] = in_array($checked, ['1', 'on', 'true', true], true);
            }
            try {
                $result = $submit->handle(
                    $workspace, $site, $model, $answers, (array) $request->input('c', []), $consents, null,
                    $self, $request->ip(), (string) $request->userAgent(), self::source($request),
                );
            } catch (ValidationException $e) {
                return $this->page($request, $workspace, $model, $widgets, self::flat($e->errors()), $request->all(), 422);
            }

            return redirect()->to(QuotePage::origin($request, $workspace).'/p/'.$key.'/cotizar/'.$model->publicId()
                .'/r/'.$result['quote']->publicId().'/'.QuotePage::signature($result['quote']), 303)->withHeaders(['Cache-Control' => 'no-store, private']);
        });
    }

    /** GET /p/{key}/cotizar/{qc_…}/r/{qt_…}/{firma} — el precio que vio la persona */
    public function result(Request $request, string $key, string $calculator, string $quote, string $signature): Response
    {
        $ctx = $this->context($request, $key, $calculator, 'page', 60);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, , $model] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $model, $quote, $signature, $key, $request): Response {
            $row = Quote::findByPublicId($quote);
            if ($row === null || $row->calculator_id !== $model->id || ! hash_equals(QuotePage::signature($row), $signature)
                || $row->created_at->lt(now()->subDays(QuotePage::RESULT_DAYS))) {
                return QuotePage::view('quotes.missing', $workspace, [], 404);
            }
            $settings = QuoteCapture::for($model->capture);
            $min = (string) $row->estimate_min;
            $max = (string) $row->estimate_max;
            try {
                $lines = QuoteEngine::estimate($model, (array) $row->answers)['lines'];
            } catch (ValidationException) {
                $lines = []; // El cotizador cambió después: el rango guardado sigue valiendo, el desglose no.
            }

            return QuotePage::view('quotes.result', $workspace, [
                'name' => $model->name,
                'range' => QuotePage::range($min, $max, $row->currency),
                'lines' => array_map(fn (array $l) => $l['label'], $lines),
                'note' => $model->definition['note'] ?? null,
                'message' => $settings['success'] ?? __('Es un valor estimado. Te contactaremos para confirmar el precio final.'),
                'whatsapp' => PublicQuoteController::whatsapp($settings['whatsapp_number'], $model->name, $min, $max, $row->currency, $row->publicId()),
                'again' => QuotePage::origin($request, $workspace).'/p/'.$key.'/cotizar/'.$model->publicId(),
                'reference' => $row->publicId(),
            ]);
        });
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function page(Request $request, Workspace $workspace, QuoteCalculator $model, PublicWidgetConfig $widgets, array $errors, array $old, int $status = 200): Response
    {
        return QuotePage::view('quotes.page', $workspace, self::pageData($workspace, $model, $widgets, $errors, $old) + [
            'action' => QuotePage::origin($request, $workspace).$request->getPathInfo(),
            'method' => 'post',
            'utm' => array_filter(self::source($request), fn ($v, $k) => $k !== 'channel' && $k !== 'page', ARRAY_FILTER_USE_BOTH),
        ], $status);
    }

    /**
     * Lo que necesita la vista del formulario (también la vista previa del panel).
     *
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    public static function pageData(Workspace $workspace, QuoteCalculator $model, PublicWidgetConfig $widgets, array $errors, array $old): array
    {
        $capture = QuoteCapture::for($model->capture);
        $definition = (array) $model->definition;
        $currency = (string) (($definition['currency'] ?? null) ?: ($workspace->currency ?: 'COP'));
        $consents = array_values(array_filter($widgets->legalConsents($workspace), fn (array $c) => in_array($c['channel'], $capture['consent_channels'], true)
            && ($c['channel'] !== 'email' || in_array('email', $capture['fields'], true))
            && ($c['channel'] === 'email' || in_array('phone', $capture['fields'], true))));

        return [
            'name' => $model->name,
            'intro' => $definition['intro'] ?? null,
            'note' => $definition['note'] ?? null,
            'steps' => QuotePage::steps($definition, ! $capture['required'], $currency),
            'capture' => $capture['required'] ? [
                'fields' => $capture['fields'], 'require' => $capture['require'], 'consents' => $consents,
                'title' => $capture['title'] ?? __('¿A dónde te enviamos tu cotización?'),
            ] : null,
            'cta' => $capture['cta'] ?? __('Ver mi cotización'),
            'errors' => $errors,
            'old' => $old,
        ];
    }

    /** @return array{0: Workspace, 1: Site|null, 2: QuoteCalculator}|Response */
    private function context(Request $request, string $key, string $calculator, string $bucket, int $perMinute): array|Response
    {
        $ctx = $this->public->resolve($key);
        if ($ctx === null || $this->limited($request, $bucket, $perMinute)) {
            return QuotePage::view('quotes.missing', null, [], $ctx === null ? 404 : 429);
        }
        [$workspace, $site] = $ctx;
        if (! QuotePage::hostAllows($request, $workspace) || ! app(Entitlements::class)->allows($workspace, 'quotes')) {
            return QuotePage::view('quotes.missing', $workspace, [], 404);
        }
        $model = $this->current->run($workspace, fn () => QuoteCalculator::findByPublicId($calculator));
        if (! $model instanceof QuoteCalculator || $model->status !== 'active') {
            return QuotePage::view('quotes.missing', $workspace, [], 404);
        }
        if ($model->site_id !== null) {
            // Un cotizador de un sitio se abre con la clave de ese sitio (o la del espacio de trabajo).
            if (str_starts_with($key, 'pk_') && $site?->id !== $model->site_id) {
                return QuotePage::view('quotes.missing', $workspace, [], 404);
            }
            $site = Site::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($model->site_id)->first() ?? $site;
        }

        return [$workspace, $site, $model];
    }

    private function limited(Request $request, string $bucket, int $perMinute): bool
    {
        $key = 'igr-quote-page:'.$bucket.':'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** @return array<string, string> */
    private static function source(Request $request): array
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'ttclid'] as $field) {
            $value = $request->input('utm.'.$field) ?? $request->query($field);
            if (is_string($value) && $value !== '') {
                $utm[$field] = $value;
            }
        }

        return PublicQuoteController::source(['utm' => $utm], 'quote_page');
    }

    /**
     * @param  array<string, array<int, string>|string>  $errors
     * @return array<string, string>
     */
    private static function flat(array $errors): array
    {
        return array_map(fn ($e) => (string) (is_array($e) ? ($e[0] ?? '') : $e), $errors);
    }
}
