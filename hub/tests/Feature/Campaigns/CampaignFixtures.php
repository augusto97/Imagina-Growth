<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de prueba de campañas: espacio de trabajo en Colombia, contactos con o
 * sin consentimiento, un segmento fijo, cuentas de canal y plantillas.
 *
 * @property User $owner
 * @property Workspace $ws
 */
trait CampaignFixtures
{
    public function campaignWorkspace(): void
    {
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
        WaRateCard::seed();
        $this->inWs(function () {
            foreach (['whatsapp' => 'meta_cloud', 'email' => 'ses'] as $channel => $provider) {
                ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => $channel, 'provider' => $provider, 'display_name' => $channel, 'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true]);
            }
        });
    }

    public function inWs(callable $fn, ?Workspace $ws = null): mixed
    {
        return app(CurrentWorkspace::class)->run($ws ?? $this->ws, $fn);
    }

    /** @param array<string, mixed> $attrs */
    public function contact(string $name, array $consents = ['whatsapp', 'email'], array $attrs = [], ?Workspace $ws = null): Contact
    {
        $ws ??= $this->ws;

        return $this->inWs(function () use ($name, $consents, $attrs, $ws) {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(4));
            $email = $slug.'@ejemplo.com';
            $phone = '+5730'.random_int(10000000, 99999999);
            $contact = Contact::query()->create($attrs + [
                'workspace_id' => $ws->id, 'first_name' => $name, 'country' => 'CO',
                'email' => $email, 'email_bidx' => BlindIndex::email($ws->id, $email),
                'phone' => $phone, 'phone_bidx' => BlindIndex::phone($ws->id, $phone),
            ]);
            foreach ($consents as $channel) {
                DB::table('contact_consents')->insert([
                    'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'contact_id' => $contact->id,
                    'channel' => $channel, 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'checkout',
                    'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $contact;
        }, $ws);
    }

    /** Segmento fijo con estos contactos. */
    public function segmentOf(string $name, array $contacts, ?Workspace $ws = null): Segment
    {
        $ws ??= $this->ws;

        return $this->inWs(function () use ($name, $contacts, $ws) {
            $segment = Segment::query()->create(['workspace_id' => $ws->id, 'name' => $name, 'kind' => Segment::KIND_STATIC, 'is_system' => false, 'member_count' => count($contacts)]);
            foreach ($contacts as $c) {
                DB::table('segment_memberships')->insert(['segment_id' => $segment->id, 'contact_id' => $c->id, 'workspace_id' => $ws->id, 'entered_at' => now()]);
            }

            return $segment;
        }, $ws);
    }

    /** Plantilla de la tabla `templates` (la gestiona M08); devuelve el ID público. */
    public function template(string $channel = 'whatsapp', string $name = 'Oferta', ?Workspace $ws = null): string
    {
        $id = strtoupper((string) Str::ulid());
        DB::table('templates')->insert([
            'id' => $id, 'workspace_id' => ($ws ?? $this->ws)->id, 'channel' => $channel, 'name' => $name,
            'category' => 'marketing', 'status' => 'ready', 'body_text' => 'Hola {{ contacto.nombre }}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'tpl_'.$id;
    }

    public function api(string $path = ''): string
    {
        return "/api/w/{$this->ws->publicId()}/campaigns".$path;
    }

    /**
     * Crea una campaña lista por la API y devuelve el modelo.
     *
     * @param  array<string, mixed>  $patch
     */
    public function readyCampaign(array $segments, array $patch = [], string $channel = 'whatsapp'): Campaign
    {
        $id = $this->actingAs($this->owner)->postJson($this->api(), ['name' => 'Black Friday VIP', 'channel' => $channel])->assertCreated()->json('id');
        $this->actingAs($this->owner)->patchJson($this->api("/$id"), $patch + [
            'audience' => ['segments_include' => array_map(fn (Segment $s) => $s->publicId(), $segments)],
            'template_id' => $this->template($channel),
            'send_mode' => 'now',
        ])->assertOk();

        return $this->inWs(fn () => Campaign::findByPublicId($id));
    }
}
