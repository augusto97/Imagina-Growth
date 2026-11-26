<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Actions;

use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Una cotización pedida por una persona (M18), desde el widget del sitio o desde la página del cotizador en el hub:
 * calcula en el hub (`QuoteEngine`), revisa los datos de contacto que pide el cotizador, registra el contacto con las
 * casillas que marcó (`CaptureContact`) y guarda la cotización con su lead y sus eventos (`RequestQuote`).
 *
 * Los errores salen como `ValidationException` con las claves de siempre (`answers.<campo>`, `fields.phone`,
 * `fields.email`) para que cada pantalla los ponga junto a su campo.
 */
final class SubmitQuote
{
    public function __construct(private readonly CaptureContact $capture, private readonly RequestQuote $request, private readonly PublicWidgetConfig $widgets) {}

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $fields  first_name, last_name, phone, email
     * @param  array<string, mixed>  $consents  canal => true|false
     * @param  array<string, mixed>  $source  canal, UTM, página
     * @return array{quote: Quote, estimate: array{min: string, max: string, mid: string, answers: array<string, mixed>, lines: list<array{label: string, amount: string}>}, contact: Contact|null}
     *
     * @throws ValidationException
     */
    public function handle(
        Workspace $workspace, ?Site $site, QuoteCalculator $calculator, array $answers, array $fields, array $consents,
        ?string $anonymousId, ?string $pageUrl, ?string $ip, string $userAgent, array $source,
    ): array {
        $settings = QuoteCapture::for($calculator->capture);
        $estimate = QuoteEngine::estimate($calculator, $answers);
        $email = self::str($fields['email'] ?? null, 255);
        $phone = self::str($fields['phone'] ?? null, 30);
        $contact = null;
        if ($settings['required'] || $email !== null || $phone !== null) {
            $errors = [];
            $needsPhone = $settings['require'] === 'phone' && in_array('phone', $settings['fields'], true);
            $needsEmail = $settings['require'] === 'email' && in_array('email', $settings['fields'], true);
            if ($needsPhone && $phone === null) {
                $errors['fields.phone'] = [__('Escribe tu WhatsApp.')];
            }
            if ($needsEmail && $email === null) {
                $errors['fields.email'] = [__('Escribe tu correo.')];
            }
            if (! $needsPhone && ! $needsEmail && $email === null && $phone === null) {
                $errors['fields.phone'] = [__('Escribe tu WhatsApp o tu correo.')];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $offered = array_column($this->widgets->legalConsents($workspace), 'channel');
            $given = [];
            foreach ($consents as $channel => $checked) {
                if (in_array($channel, $settings['consent_channels'], true) && in_array($channel, $offered, true)) {
                    $given[(string) $channel] = $checked === true;
                }
            }
            try {
                $contact = $this->capture->handle($workspace, $site, new CaptureInput(
                    source: 'quote:'.$calculator->publicId(), email: $email, phone: $phone,
                    firstName: self::str($fields['first_name'] ?? null, 80), lastName: self::str($fields['last_name'] ?? null, 80),
                    anonymousId: $anonymousId, consents: $given, pageUrl: $pageUrl, ip: $ip, userAgent: $userAgent,
                ))->contact;
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([($email !== null && $phone === null ? 'fields.email' : 'fields.phone') => [$e->getMessage()]]);
            }
        }
        $quote = $this->request->handle($workspace, $calculator, $estimate, $contact, $site, $anonymousId, $source);

        return ['quote' => $quote, 'estimate' => $estimate, 'contact' => $contact];
    }

    /** Texto sin caracteres de control, recortado; null si queda vacío. */
    public static function str(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
