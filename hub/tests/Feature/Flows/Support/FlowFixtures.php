<?php

namespace Tests\Feature\Flows\Support;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Datos de prueba de flujos (M09). */
trait FlowFixtures
{
    public User $owner;

    public Workspace $ws;

    public Site $site;

    public string $secret;

    public Contact $contact;

    /** Espacio de trabajo colombiano con sitio, un contacto con WhatsApp y correo con consentimiento, cuentas activas y las plantillas de la biblioteca. */
    public function flowWorld(): void
    {
        $this->useIngestInfrastructure();
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
        [$this->site, $this->secret] = $this->connectedSite($this->ws);
        $this->contact = $this->flowContact('ana@ejemplo.com', '+573001234567');
        app(CurrentWorkspace::class)->run($this->ws, function () {
            foreach (['whatsapp' => 'meta_cloud', 'email' => 'ses'] as $channel => $provider) {
                ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => $channel, 'provider' => $provider, 'display_name' => $channel, 'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true]);
            }
        });
        $this->flowTemplates();
    }

    public function flowContact(string $email, ?string $phone, array $consents = ['whatsapp', 'email']): Contact
    {
        return app(CurrentWorkspace::class)->run($this->ws, function () use ($email, $phone, $consents) {
            $contact = Contact::query()->create([
                'workspace_id' => $this->ws->id, 'first_name' => 'Ana', 'country' => 'CO',
                'email' => $email, 'email_bidx' => BlindIndex::email($this->ws->id, $email),
                'phone' => $phone, 'phone_bidx' => $phone !== null ? BlindIndex::phone($this->ws->id, $phone) : null,
                'lifecycle_stage' => 'customer', 'orders_count' => 1,
            ]);
            foreach ($consents as $channel) {
                DB::table('contact_consents')->insert([
                    'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact->id,
                    'channel' => $channel, 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'checkout',
                    'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $contact;
        });
    }

    /** Una plantilla lista por cada `library_key` que usan los flujos (M08 las provee en producción). */
    public function flowTemplates(): void
    {
        foreach (FlowLibrary::TEMPLATE_KEYS as $key) {
            $channels = $key === 'team_alert' ? ['email', 'whatsapp'] : [str_starts_with($key, 'wa_') ? 'whatsapp' : 'email'];
            foreach ($channels as $channel) {
                DB::table('templates')->insert([
                    'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'channel' => $channel, 'name' => $key,
                    'category' => 'marketing', 'library_key' => $key, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** CouponIssuer de prueba: guarda el cupón como si el sitio lo hubiera creado. */
    public function fakeCoupons(): void
    {
        app()->instance(CouponIssuer::class, new class implements CouponIssuer
        {
            public function issue(CouponSpec $spec): CouponIssued
            {
                $site = Site::query()->firstOrFail();

                return CouponIssued::query()->create([
                    'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'code' => $spec->prefix.'-PRUEBA'.random_int(10, 99),
                    'contact_id' => $spec->contactId, 'source_type' => $spec->sourceType, 'source_id' => $spec->sourceId,
                    'kind' => $spec->kind, 'amount' => $spec->amount, 'status' => 'active', 'expires_at' => now()->addDays($spec->expiresInDays),
                ]);
            }
        });
    }

    /** Un evento del sitio publicado por la ingesta (sin pasar por Redis Streams). */
    public function flowEvent(string $event, array $properties, ?Contact $contact = null, ?string $eventId = null): string
    {
        $eventId ??= (string) Str::uuid7();
        event(new EventIngested($this->ws->id, $this->site->id, $event, $eventId, ($contact ?? $this->contact)->id, [
            'schema_version' => 2, 'event_id' => $eventId, 'event' => $event, 'occurred_at' => now()->toIso8601String(),
            'source' => ['type' => 'server', 'platform' => 'woocommerce', 'site_id' => $this->site->publicId()],
            'identity' => [], 'context' => [], 'properties' => $properties,
        ]));

        return $eventId;
    }

    /** @return array<string, mixed> */
    public function cartProps(): array
    {
        return [
            'cart_id' => 'crt_01JDZZZZZZZZZZZZZZZZZZZZZZ', 'cart_token' => 'c_8f2a9d',
            'items' => [['product_id' => '88', 'qty' => 2, 'name' => 'Pintura Azul 1 galón', 'price' => '141000.00']],
            'subtotal' => '282000.00', 'currency' => 'COP', 'items_count' => 2, 'checkout_started' => true, 'minutes_inactive' => 47,
            'restore_url' => 'https://tienda.co/?igr_cart=abc.def',
        ];
    }

    public function api(string $path = ''): string
    {
        return "/api/w/{$this->ws->publicId()}/flows".$path;
    }
}
