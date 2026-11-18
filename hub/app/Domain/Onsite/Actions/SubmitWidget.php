<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Contacts\Data\CaptureResult;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\WidgetCapture;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * POST /p/{clave}/widgets/{id}/submit (api-connector.md §8.3): crea o une el contacto
 * (CaptureContact) con cada casilla marcada y su evidencia, da el cupón único del
 * widget (uno por persona: 409 si lo pide otra vez), sortea el premio de la ruleta en
 * el hub, guarda las respuestas del quiz como atributos y devuelve sus productos, da
 * el regalo del referido (M14) y envía el doble opt-in si el widget lo pide. Debe
 * correr dentro del contexto del espacio de trabajo.
 *
 * @phpstan-type Meta array{ip: string|null, user_agent: string|null, country: string|null}
 */
final class SubmitWidget
{
    public function __construct(
        private readonly CaptureContact $capture,
        private readonly CouponIssuer $coupons,
        private readonly ReferralRewards $referrals,
        private readonly ResolveQuizOutcome $quiz,
        private readonly SendDoubleOptIn $optIn,
        private readonly ConsentTexts $texts,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  Meta  $meta
     * @return array{0: int, 1: array<string, mixed>} código HTTP y cuerpo
     */
    public function handle(Workspace $workspace, Site $site, OnsiteWidget $widget, array $body, array $meta): array
    {
        $variant = WidgetSchema::variant($widget, is_string($body['variant'] ?? null) ? $body['variant'] : null);
        $step = $widget->kind === 'two_step' ? max(1, min(2, (int) ($body['step'] ?? 1))) : 1;
        $content = $step === 2 ? (array) ($variant['content']['step2'] ?? []) : $variant['content'];
        $anonymousId = is_string($body['anonymous_id'] ?? null) && $body['anonymous_id'] !== '' ? mb_substr($body['anonymous_id'], 0, 64) : null;
        $pageUrl = is_string($body['page_url'] ?? null) ? mb_substr($body['page_url'], 0, 500) : null;

        // 1. Datos y respuestas.
        [$fields, $errors] = $this->fields($workspace, (array) ($content['fields'] ?? []), is_array($body['fields'] ?? null) ? $body['fields'] : []);
        $answers = [];
        $quiz = $widget->kind === 'quiz' ? $widget->quiz : null;
        if ($quiz !== null) {
            $answers = $this->quiz->answers($quiz, is_array($body['answers'] ?? null) ? $body['answers'] : []);
            if ($answers === null) {
                $errors['answers'][] = __('Una de las respuestas no es válida.');
            }
        }
        $hasContact = $fields['email'] !== null || $fields['phone'] !== null;
        $contactOptional = $quiz !== null && (bool) ($variant['content']['contact_optional'] ?? false);
        if (! $hasContact && ! $contactOptional && $errors === []) {
            $errors['fields'][] = __('Escribe un correo o un WhatsApp válido.');
        }
        if ($errors !== []) {
            return [422, ['ok' => false, 'message' => __('Revisa los datos e inténtalo de nuevo.'), 'errors' => $errors]];
        }
        $answers ??= [];

        // Quiz sin datos de contacto (opcional): solo el resultado.
        if (! $hasContact && $quiz !== null) {
            $outcome = $this->quiz->outcome($quiz, $site, $answers, null, $anonymousId);

            return [200, ['ok' => true, 'message' => $outcome['message'] !== '' ? $outcome['message'] : (string) ($content['success'] ?? ''),
                'products' => $outcome['products'], 'double_opt_in' => false]];
        }

        // 2. Contacto y consentimientos (solo los canales que el widget ofrece).
        $offered = (array) ($widget->consent_config['channels'] ?? []);
        $consents = [];
        foreach ((array) ($body['consents'] ?? []) as $channel => $checked) {
            if (in_array($channel, $offered, true)) {
                $consents[(string) $channel] = $checked === true;
            }
        }
        // Las casillas que vio la persona son las del texto legal vigente (widgets.json crea la v1 si no hay).
        $this->texts->current($workspace);
        // Segundo paso del formulario en 2 pasos: es la misma persona del primero (mismo widget y
        // navegador, hace menos de una hora), así que el WhatsApp se une a su correo y no a un perfil nuevo.
        $known = $step === 2 ? $this->firstStep($widget, $anonymousId) : null;
        try {
            $result = $this->capture->handle($workspace, $site, new CaptureInput(
                source: 'widget:'.$widget->publicId(),
                email: $fields['email'] ?? $known?->email,
                phone: $fields['phone'] ?? $known?->phone,
                firstName: $fields['first_name'],
                lastName: $fields['last_name'],
                anonymousId: $anonymousId,
                consents: $consents,
                pageUrl: $pageUrl,
                ip: $meta['ip'],
                userAgent: $meta['user_agent'],
                doubleOptInEmail: (bool) ($widget->consent_config['double_opt_in'] ?? false),
            ));
        } catch (InvalidArgumentException $e) {
            return [422, ['ok' => false, 'message' => $e->getMessage(), 'errors' => ['fields' => [$e->getMessage()]]]];
        }
        $contact = $result->contact;

        // 3. Cupón, premio, quiz y referido, con un candado por widget y persona.
        try {
            return Cache::lock('igr-onsite:submit:'.$widget->id.':'.$contact->id, 20)->block(10, fn () => $this->reward(
                $workspace, $site, $widget, $variant['key'], $content, $step, $result, $answers, $anonymousId, $body, $meta['ip'],
            ));
        } catch (LockTimeoutException) {
            return [429, ['ok' => false, 'message' => __('Estamos procesando tu solicitud. Inténtalo de nuevo en unos segundos.')]];
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, string>  $answers
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function reward(Workspace $workspace, Site $site, OnsiteWidget $widget, string $variant, array $content, int $step, CaptureResult $result, array $answers, ?string $anonymousId, array $body, ?string $ip): array
    {
        $contact = $result->contact;
        $existing = WidgetCapture::query()->where('widget_id', $widget->id)
            ->whereIn('contact_id', ExportContactData::contactIds($contact))->orderBy('id')->first();
        $now = CarbonImmutable::now();

        $prize = null;
        $couponSpec = null;
        if ($widget->kind === 'spin_wheel') {
            if ($existing?->prize !== null) {
                return [409, ['ok' => false, 'error' => 'already_played', 'message' => __('Ya giraste esta ruleta. ¡Gracias por participar!')]];
            }
            $prize = $this->draw((array) ($widget->coupon_config['prizes'] ?? []));
            $couponSpec = is_array($prize['coupon'] ?? null) ? $prize['coupon'] : null;
        } elseif ((bool) ($widget->coupon_config['enabled'] ?? false)) {
            if ($existing?->coupon_id !== null) {
                if ($step === 1) {
                    return [409, ['ok' => false, 'error' => 'already_claimed', 'message' => __('Ya recibiste el cupón de esta promoción. Revisa tu correo o tu WhatsApp.')]];
                }
            } else {
                $couponSpec = (array) $widget->coupon_config;
            }
        }

        $coupon = null;
        $couponFailed = false;
        if ($couponSpec !== null) {
            try {
                $coupon = $this->coupons->issue(new CouponSpec(
                    kind: (string) ($couponSpec['kind'] ?? 'percent'),
                    amount: (string) ($couponSpec['amount'] ?? '0'),
                    sourceType: 'widget',
                    sourceId: $widget->publicId(),
                    contactId: $contact->id,
                    expiresInDays: (int) ($couponSpec['expires_in_days'] ?? 7),
                    prefix: (string) ($couponSpec['prefix'] ?? 'IGR'),
                    minimumAmount: isset($couponSpec['minimum_amount']) ? (string) $couponSpec['minimum_amount'] : null,
                    siteId: $site->id,
                    description: __('Widget «:name»', ['name' => $widget->name]),
                ));
            } catch (CouponIssueException) {
                // La captura queda; el cupón (o el giro de la ruleta) se puede pedir otra vez.
                $couponFailed = true;
                $prize = null;
                Log::warning('igr.onsite.coupon_failed', ['widget' => $widget->id, 'site' => $site->id]);
            }
        }

        // Regalo del amigo referido (M14): con cualquier widget que traiga el código.
        $referralCoupon = null;
        $code = $body['referral_code'] ?? null;
        if (is_string($code) && preg_match('/^[A-Za-z0-9_-]{3,32}$/', $code) === 1) {
            try {
                $referralCoupon = $this->referrals->onFriendCaptured($contact->id, $code, $anonymousId, $result->wasKnown, $site->id, $ip);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Quiz: atributos del perfil y productos del resultado.
        $outcome = null;
        if ($widget->kind === 'quiz' && $widget->quiz !== null) {
            $attributes = $this->quiz->attributes($widget->quiz, $answers);
            if ($attributes !== []) {
                $contact->forceFill(['custom_attributes' => array_merge((array) ($contact->custom_attributes ?? []), $attributes)])->save();
            }
            $outcome = $this->quiz->outcome($widget->quiz, $site, $answers, $contact->id, $anonymousId);
        }

        $this->record($widget, $variant, $contact, $anonymousId, $existing, $coupon, $prize, $answers, $result->wasKnown, $now);

        $doubleOptIn = in_array('email', $result->pendingDoubleOptIn, true);
        if ($doubleOptIn) {
            try {
                $this->optIn->handle($workspace, $site, $widget, $contact->id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $shown = $coupon ?? ($widget->kind === 'referral_welcome' ? $referralCoupon : null);
        $message = $this->message($content, $widget, $shown, $prize, $outcome, $couponFailed);
        if ($doubleOptIn) {
            $message = trim($message.' '.__('Revisa tu correo y confirma tu suscripción.'));
        }

        return [200, array_filter([
            'ok' => true,
            'message' => $message,
            'coupon' => $shown !== null ? $this->couponOut($shown, $site) : null,
            'referral_coupon' => $referralCoupon !== null && $shown?->id !== $referralCoupon->id ? $this->couponOut($referralCoupon, $site) : null,
            'prize' => $prize !== null ? ['key' => (string) $prize['key'], 'label' => (string) $prize['label']] : null,
            'products' => $outcome['products'] ?? null,
            'double_opt_in' => $doubleOptIn,
            'next_step' => $widget->kind === 'two_step' && $step === 1 ? 2 : null,
        ], fn ($v) => $v !== null)];
    }

    /**
     * @param  array<int, mixed>  $configured  campos del widget (o del paso)
     * @param  array<mixed>  $given
     * @return array{0: array{email: string|null, phone: string|null, first_name: string|null, last_name: string|null}, 1: array<string, list<string>>}
     */
    private function fields(Workspace $workspace, array $configured, array $given): array
    {
        $values = ['email' => null, 'phone' => null, 'first_name' => null, 'last_name' => null];
        /** @var array<string, list<string>> $errors */
        $errors = [];
        foreach ($configured as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! array_key_exists($field['key'], $values)) {
                continue;
            }
            $key = $field['key'];
            $raw = $given[$key] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value === '') {
                if ((bool) ($field['required'] ?? false)) {
                    $errors[$key][] = match ($key) {
                        'email' => __('Escribe tu correo.'),
                        'phone' => __('Escribe tu número de WhatsApp.'),
                        default => __('Este dato es obligatorio.'),
                    };
                }

                continue;
            }
            [$clean, $error] = $this->clean($workspace, $key, $value);
            if ($error !== null) {
                $errors[$key][] = $error;

                continue;
            }
            $values[$key] = $clean;
        }

        return [['email' => $values['email'], 'phone' => $values['phone'], 'first_name' => $values['first_name'], 'last_name' => $values['last_name']], $errors];
    }

    /** @return array{0: string|null, 1: string|null} valor limpio y error */
    private function clean(Workspace $workspace, string $key, string $value): array
    {
        if ($key === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 190
                ? [null, __('Escribe un correo válido.')]
                : [mb_strtolower($value), null];
        }
        if ($key === 'phone') {
            $country = (string) $workspace->setting('default_phone_country', $workspace->country ?: 'CO');

            return Phone::toE164($value, $country) === null
                ? [null, __('Escribe un número de WhatsApp válido, con el indicativo si no es de :country.', ['country' => $country])]
                : [mb_substr($value, 0, 40), null];
        }

        return [mb_substr(strip_tags($value), 0, 80), null];
    }

    private function firstStep(OnsiteWidget $widget, ?string $anonymousId): ?Contact
    {
        if ($anonymousId === null) {
            return null;
        }
        $contactId = WidgetCapture::query()->where('widget_id', $widget->id)->where('anonymous_id', $anonymousId)
            ->where('captured_at', '>=', now()->subHour())->orderByDesc('id')->value('contact_id');

        return is_string($contactId) ? ConfirmDoubleOptIn::canonical($contactId) : null;
    }

    /**
     * Sorteo del premio con las probabilidades guardadas en el hub.
     *
     * @param  array<int, mixed>  $prizes
     * @return array<string, mixed>|null
     */
    public function draw(array $prizes): ?array
    {
        $prizes = array_values(array_filter($prizes, fn ($p) => is_array($p) && (int) ($p['weight'] ?? 0) > 0));
        $total = array_sum(array_map(fn (array $p) => (int) $p['weight'], $prizes));
        if ($total <= 0) {
            return null;
        }
        $roll = random_int(1, $total);
        foreach ($prizes as $prize) {
            $roll -= (int) $prize['weight'];
            if ($roll <= 0) {
                return $prize;
            }
        }

        return $prizes[count($prizes) - 1];
    }

    /**
     * @param  array<string, mixed>|null  $prize
     * @param  array<string, string>  $answers
     */
    private function record(OnsiteWidget $widget, string $variant, Contact $contact, ?string $anonymousId, ?WidgetCapture $existing, ?CouponIssued $coupon, ?array $prize, array $answers, bool $wasKnown, CarbonImmutable $now): void
    {
        if ($existing === null) {
            WidgetCapture::query()->create([
                'widget_id' => $widget->id,
                'variant' => mb_substr($variant, 0, 8),
                'contact_id' => $contact->id,
                'anonymous_id' => $anonymousId,
                'coupon_id' => $coupon?->id,
                'prize' => $prize !== null ? mb_substr((string) $prize['key'], 0, 40) : null,
                'answers' => $answers !== [] ? $answers : null,
                'was_known' => $wasKnown,
                'captured_at' => $now,
            ]);

            return;
        }
        $existing->forceFill(array_filter([
            'coupon_id' => $existing->coupon_id ?? $coupon?->id,
            'prize' => $existing->prize ?? ($prize !== null ? mb_substr((string) $prize['key'], 0, 40) : null),
            'answers' => $answers !== [] ? array_merge((array) ($existing->answers ?? []), $answers) : $existing->answers,
            'anonymous_id' => $existing->anonymous_id ?? $anonymousId,
        ], fn ($v) => $v !== null) + ['updated_at' => $now])->save();
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $prize
     * @param  array{rule: string|null, message: string, products: list<array<string, mixed>>}|null  $outcome
     */
    private function message(array $content, OnsiteWidget $widget, ?CouponIssued $coupon, ?array $prize, ?array $outcome, bool $couponFailed): string
    {
        if ($widget->kind === 'spin_wheel' && $coupon === null && $prize !== null) {
            return (string) ($content['lose'] ?? '') ?: __('¡Gracias por participar!');
        }
        $text = $outcome !== null && $outcome['message'] !== '' ? $outcome['message'] : (string) ($content['success'] ?? '');
        if ($couponFailed) {
            return __('¡Listo, quedaste registrado! No pudimos generar tu cupón en este momento; inténtalo de nuevo en unos minutos.');
        }
        if ($coupon === null) {
            // Sin cupón: se quitan las frases que lo mencionan.
            $text = trim((string) preg_replace('/[^.!?¡]*\{\{\s*codigo\s*\}\}[^.!?]*[.!?]?/u', '', $text));
        }
        $text = strtr($text, [
            '{{codigo}}' => $coupon->code ?? '', '{{ codigo }}' => $coupon->code ?? '',
            '{{premio}}' => (string) ($prize['label'] ?? ''), '{{ premio }}' => (string) ($prize['label'] ?? ''),
        ]);

        return $text !== '' ? $text : __('¡Gracias! Ya quedaste registrado.');
    }

    /** @return array{code: string, description: string, expires_at: string|null} */
    private function couponOut(CouponIssued $coupon, Site $site): array
    {
        return [
            'code' => $coupon->code,
            'description' => self::describe($coupon->kind, $coupon->amount, (string) ($site->getAttribute('currency') ?: 'COP'), $coupon->rules['minimum_amount'] ?? null),
            'expires_at' => $coupon->expires_at?->setTimezone($site->timezone ?: 'America/Bogota')->toDateString(),
        ];
    }

    public static function describe(string $kind, string $amount, string $currency, mixed $minimum = null): string
    {
        $money = fn (mixed $v) => ($currency === 'USD' ? 'US$' : '$').number_format((float) $v, $currency === 'USD' ? 2 : 0, ',', '.');
        $text = match ($kind) {
            'percent' => __(':n% de descuento', ['n' => rtrim(rtrim(number_format((float) $amount, 2, ',', '.'), '0'), ',')]),
            'free_shipping' => __('Envío gratis'),
            default => __(':amount de descuento', ['amount' => $money($amount)]),
        };

        return is_numeric($minimum) && (float) $minimum > 0 ? $text.' '.__('en compras desde :min', ['min' => $money($minimum)]) : $text;
    }
}
