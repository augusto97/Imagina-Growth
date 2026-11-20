<?php

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Auditoría F7 (cumplimiento.md §3.1): al importar, el origen, cómo se obtuvo la autorización (base legal) y la
 * fecha del consentimiento se declaran; nunca se inventa. Vale para el CSV y para el historial que importó el plugin.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->url = "/api/w/{$this->ws->publicId()}/contacts/imports";
    $this->declaration = ['channels' => ['email', 'whatsapp'], 'source' => 'Casilla del checkout de la tienda desde 2025', 'legal_basis' => 'checkout_checkbox', 'date' => '2025-06-01'];
});

function importCsv($test, string $csv, array $mapping, ?array $consent): TestResponse
{
    $payload = ['file' => UploadedFile::fake()->createWithContent('clientes.csv', $csv), 'mapping' => json_encode($mapping)];
    if ($consent !== null) {
        $payload['consent'] = json_encode($consent);
    }

    return $test->actingAs($test->owner)->post($test->url, $payload, ['Accept' => 'application/json']);
}

it('el CSV exige declarar cómo se obtuvo la autorización y la guarda en la evidencia', function () {
    $csv = "correo;whatsapp\nana@ejemplo.com;3001234567\n";
    $without = $this->declaration;
    unset($without['legal_basis']);
    importCsv($this, $csv, ['correo' => 'email', 'whatsapp' => 'phone'], $without)->assertStatus(422)->assertJsonValidationErrors('consent.legal_basis');
    importCsv($this, $csv, ['correo' => 'email', 'whatsapp' => 'phone'], ['legal_basis' => 'inventada'] + $this->declaration)->assertStatus(422);

    importCsv($this, $csv, ['correo' => 'email', 'whatsapp' => 'phone'], $this->declaration)->assertStatus(202)->assertJsonPath('consent.legal_basis', 'checkout_checkbox');
    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(ContactConsent::query()->where('status', 'granted')->count())->toBe(2);
        $evidence = ConsentEvent::query()->first()->evidence;
        expect($evidence['legal_basis'])->toBe('checkout_checkbox')->and($evidence['declared_source'])->toBe('Casilla del checkout de la tienda desde 2025');
    });
});

it('con una columna de consentimiento por fila solo cuenta el «sí»; sin declaración esa columna no vale', function () {
    $csv = "correo;acepta correo\nana@ejemplo.com;Sí\nluis@ejemplo.com;no\nmaria@ejemplo.com;\n";
    $mapping = ['correo' => 'email', 'acepta correo' => 'consent_email'];
    importCsv($this, $csv, $mapping, null)->assertStatus(422)->assertJsonValidationErrors('consent');

    importCsv($this, $csv, $mapping, ['channels' => ['email']] + $this->declaration)->assertStatus(202)->assertJsonPath('totals.consents', 1);
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $granted = ContactConsent::query()->where('status', 'granted')->pluck('contact_id')->all();
        $ana = Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'ana@ejemplo.com'))->value('id');
        expect($granted)->toBe([$ana])->and(Contact::query()->count())->toBe(3);
    });
});

it('una lista importada no le devuelve el consentimiento a quien lo revocó o se dio de baja', function () {
    $ana = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'email' => 'ana@ejemplo.com', 'email_bidx' => BlindIndex::email($this->ws->id, 'ana@ejemplo.com'), 'lifecycle_stage' => 'customer']));
    DB::table('contact_identities')->insert(['id' => strtoupper((string) str()->ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $ana->id, 'type' => 'email_hash', 'value' => $ana->email_bidx]);
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(RecordConsent::class)->handle($this->ws, $ana->id, 'email', 'marketing', 'revoked', 'unsubscribe', null, CarbonImmutable::parse('2025-01-10')));
    // Y alguien que nunca fue contacto pero cuyo correo está en las bajas.
    DB::table('suppressions')->insert(['workspace_id' => $this->ws->id, 'channel' => 'email', 'value_bidx' => BlindIndex::email($this->ws->id, 'baja@ejemplo.com'), 'reason' => 'unsubscribe', 'created_at' => now()]);
    importCsv($this, "correo\nana@ejemplo.com\nbaja@ejemplo.com\nnueva@ejemplo.com\n", ['correo' => 'email'], ['channels' => ['email']] + $this->declaration)->assertStatus(202)->assertJsonPath('totals.consents', 1);
    expect(DB::table('contact_consents')->where('contact_id', $ana->id)->value('status'))->toBe('revoked')
        ->and(DB::table('contact_consents')->where('workspace_id', $this->ws->id)->where('status', 'granted')->count())->toBe(1);
});

it('historial del plugin: sin declarar no hay consentimiento; al declarar solo lo reciben los contactos de ese historial', function () {
    $imported = fn (string $id, string $email, string $phone) => $this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot(['order_id' => $id, 'number' => $id, 'customer_ref' => null, 'consents' => ['email_marketing' => false, 'whatsapp_marketing' => false, 'text_version' => 'v1'],
        'billing' => ['email' => $email, 'phone' => $phone]])], ['source' => ['type' => 'import']]);
    $this->postSigned($this->site, $this->secret, [
        $imported('1', 'ana@ejemplo.com', '300 111 2233'),
        $imported('2', 'luis@ejemplo.com', ''),
        $imported('3', 'baja@ejemplo.com', '300 999 8877'),
        // Un cliente en vivo (no del historial) no entra en la declaración.
        $this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:9', 'first_name' => 'Vivo', 'last_name' => 'Nuevo', 'email' => 'vivo@ejemplo.com']),
    ])->assertStatus(202);
    $this->runWorker();
    expect(DB::table('contact_consents')->where('workspace_id', $this->ws->id)->where('status', 'granted')->count())->toBe(0);
    $baja = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'baja@ejemplo.com'))->firstOrFail());
    DB::table('suppressions')->insert(['workspace_id' => $this->ws->id, 'channel' => 'email', 'value_bidx' => $baja->email_bidx, 'reason' => 'unsubscribe', 'created_at' => now()]);

    // Otro espacio de trabajo con la misma persona no recibe nada.
    $other = $this->workspaceFor($this->userWith2fa(['email' => 'otra@agencia.co']), 'Otra');
    [$otherSite, $otherSecret] = $this->connectedSite($other, 'otra.co');
    $this->postSigned($otherSite, $otherSecret, [$this->envelope($otherSite, 'order.created', ['order' => $this->orderSnapshot(['order_id' => '1', 'customer_ref' => null, 'billing' => ['email' => 'ana@ejemplo.com']])], ['source' => ['type' => 'import']])]);
    $this->runWorker();

    $this->actingAs($this->owner)->postJson("{$this->url}/history", ['site' => $this->site->publicId(), 'consent' => ['legal_basis' => 'nada'] + $this->declaration])->assertStatus(422);
    $res = $this->actingAs($this->owner)->postJson("{$this->url}/history", ['site' => $this->site->publicId(), 'consent' => $this->declaration])->assertStatus(202)->assertJsonPath('kind', 'history');
    $show = $this->actingAs($this->owner)->getJson("{$this->url}/{$res->json('id')}")->assertOk();
    // Ana: correo y WhatsApp; Luis: solo correo (sin teléfono); la de la baja: solo WhatsApp.
    expect($show->json('status'))->toBe('completed')->and($show->json('totals'))->toMatchArray(['rows' => 3, 'consents' => 4, 'updated' => 3]);
    app(CurrentWorkspace::class)->run($this->ws, function () use ($baja) {
        $vivo = Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'vivo@ejemplo.com'))->value('id');
        expect(ContactConsent::query()->where('contact_id', $vivo)->count())->toBe(0)
            ->and(ContactConsent::query()->where('contact_id', $baja->id)->pluck('channel')->all())->toBe(['whatsapp'])
            ->and(ConsentEvent::query()->first()->evidence)->toMatchArray(['scope' => 'history', 'legal_basis' => 'checkout_checkbox']);
    });
    expect(DB::table('contact_consents')->where('workspace_id', $other->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'contacts.history_consent_declared')->exists())->toBeTrue();

    // Un sitio de otro espacio de trabajo no se puede usar.
    $this->actingAs($this->owner)->postJson("{$this->url}/history", ['site' => $otherSite->publicId(), 'consent' => $this->declaration])->assertStatus(422)->assertJsonValidationErrors('site');
});
