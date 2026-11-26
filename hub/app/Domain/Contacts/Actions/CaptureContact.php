<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Actions;

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Contacts\Data\CaptureResult;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\IdentityInput;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Crea o une el contacto con lo que la persona dejó en el sitio y registra cada
 * consentimiento marcado con su evidencia (cumplimiento.md §3.1: casillas por canal,
 * sin marcar, con el texto legal vigente y su versión). La usan los widgets (M12),
 * «avísame» (M16), las reseñas (M15) y los referidos (M14).
 *
 * La identidad es la misma de la ingesta (IdentityResolver): correo y teléfono
 * normalizados con índice ciego, y el `anonymous_id` del navegador queda vinculado.
 * Con `doubleOptInEmail`, el consentimiento de correo queda pendiente hasta que la
 * persona confirme (`pending_double_optin`); quien llama envía el correo de
 * confirmación. Sin sitio (páginas de captura del hub, leads de anuncios) el
 * contacto se resuelve solo por correo, teléfono y navegador. `evidence` suma a
 * la prueba lo que quien llama mostró (formulario, texto de la casilla, anuncio).
 */
final class CaptureContact
{
    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly RecordConsent $consents,
        private readonly ConsentTexts $texts,
    ) {}

    public function handle(Workspace $workspace, ?Site $site, CaptureInput $input): CaptureResult
    {
        $email = $input->email !== null && filter_var(trim($input->email), FILTER_VALIDATE_EMAIL) ? trim($input->email) : null;
        $phone = $input->phone !== null ? Phone::toE164($input->phone, $input->country ?? $workspace->setting('default_phone_country', $workspace->country)) : null;
        if ($email === null && $phone === null) {
            throw new InvalidArgumentException(__('Escribe un correo o un WhatsApp válido.'));
        }
        $wasKnown = $this->known($workspace, $email, $phone);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($workspace, $site, $input, $email, $phone, $wasKnown, $now): CaptureResult {
            $resolved = $this->identity->resolve($workspace, $site, new IdentityInput(
                email: $email,
                phone: $phone,
                anonymousId: $input->anonymousId,
                firstName: $input->firstName !== null ? mb_substr(trim($input->firstName), 0, 80) : null,
                lastName: $input->lastName !== null ? mb_substr(trim($input->lastName), 0, 80) : null,
                country: $input->country,
            ), $now);
            $contact = Contact::query()->findOrFail((string) $resolved['contact_id']);

            $granted = [];
            $pending = [];
            $evidence = array_filter([
                'page_url' => $input->pageUrl !== null ? mb_substr($input->pageUrl, 0, 500) : null,
                'ip_hash' => RecordConsent::evidenceHash($input->ip),
                'ua_hash' => RecordConsent::evidenceHash($input->userAgent),
            ]) + $input->evidence;
            foreach ($input->consents as $channel => $checked) {
                if ($checked !== true || ! in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
                    continue;
                }
                if (($channel === 'email' && $email === null) || ($channel !== 'email' && $phone === null)) {
                    continue; // no se consiente un canal sin su dirección
                }
                $version = $this->texts->latest($workspace, $channel)?->version;
                if ($channel === 'email' && $input->doubleOptInEmail) {
                    DB::table('contact_consents')->updateOrInsert(
                        ['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing'],
                        ['id' => DB::table('contact_consents')->where('contact_id', $contact->id)->where('channel', 'email')->where('purpose', 'marketing')->value('id') ?? strtoupper((string) Str::ulid()),
                            'status' => 'pending_double_optin', 'source' => mb_substr($input->source, 0, 64), 'legal_text_version' => $version, 'updated_at' => $now, 'created_at' => $now],
                    );
                    $pending[] = 'email';

                    continue;
                }
                $this->consents->handle($workspace, $contact->id, $channel, 'marketing', 'granted', mb_substr($input->source, 0, 64), $version, $now, $evidence);
                $granted[] = $channel;
            }

            return new CaptureResult($contact->refresh(), $wasKnown, $granted, $pending);
        });
    }

    private function known(Workspace $workspace, ?string $email, ?string $phone): bool
    {
        $values = array_filter([
            $email !== null ? BlindIndex::email($workspace->id, $email) : null,
            $phone !== null ? BlindIndex::phone($workspace->id, $phone) : null,
        ]);

        return $values !== [] && ContactIdentity::query()->whereIn('type', ['email_hash', 'phone_hash'])->whereIn('value', $values)->exists();
    }
}
