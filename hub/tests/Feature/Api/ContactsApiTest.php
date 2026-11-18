<?php

use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ApiHelpers.php';

// Contactos por la API pública (M19): búsqueda con índice ciego, enmascarado, alta con consentimiento e idempotencia.
beforeEach(function () {
    apiEntitlements();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    [$this->token] = apiToken($this->ws, ['contacts:read', 'contacts:write']);
    [$this->piiToken] = apiToken($this->ws, ['contacts:read', 'contacts:pii']);
});

it('crea el contacto (201), lo une por teléfono después (200) y enmascara sin contacts:pii', function () {
    $res = $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com', 'first_name' => 'Ana', 'tags' => ['crm']], apiHeaders($this->token))
        ->assertCreated()->assertJsonPath('created', true);
    $id = $res->json('data.id');
    expect($res->json('data.email'))->not->toBe('ana@ejemplo.com')->toContain('*')
        ->and($res->json('data.first_name'))->not->toBe('Ana')
        ->and($res->json('data.tags'))->toBe(['crm']);

    $this->postJson('/api/v1/contacts', ['email' => 'ANA@ejemplo.com', 'phone' => '300 123 4567', 'tags' => ['vip']], apiHeaders($this->token))
        ->assertOk()->assertJsonPath('created', false)->assertJsonPath('data.id', $id)->assertJsonPath('data.tags', ['crm', 'vip']);

    $this->getJson('/api/v1/contacts/'.$id, apiHeaders($this->piiToken))->assertOk()
        ->assertJsonPath('data.email', 'ana@ejemplo.com')->assertJsonPath('data.phone', '+573001234567')->assertJsonPath('data.first_name', 'Ana');
    // Sin alcance contacts:pii el correo nunca sale completo.
    expect(json_encode($this->getJson('/api/v1/contacts/'.$id, apiHeaders($this->token))->json()))->not->toContain('ana@ejemplo.com');
});

it('busca por correo y teléfono exactos con el índice ciego', function () {
    $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com'], apiHeaders($this->token))->assertCreated();
    $this->postJson('/api/v1/contacts', ['phone' => '3109998877'], apiHeaders($this->token))->assertCreated();

    expect($this->getJson('/api/v1/contacts?email=Ana@Ejemplo.com', apiHeaders($this->token))->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/contacts?phone=%2B57%20310%20999%208877', apiHeaders($this->token))->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/contacts?email=nadie@ejemplo.com', apiHeaders($this->token))->json('data'))->toBe([]);
});

it('pagina con cursor sin repetir ni saltar contactos', function () {
    app(CurrentWorkspace::class)->run($this->ws, function () {
        foreach (range(1, 5) as $i) {
            Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'C'.$i]);
        }
    });
    $first = $this->getJson('/api/v1/contacts?limit=2', apiHeaders($this->token))->assertOk();
    expect($first->json('data'))->toHaveCount(2)->and($first->json('next_cursor'))->toBeString();
    $seen = collect($first->json('data'))->pluck('id');
    $cursor = $first->json('next_cursor');
    while ($cursor !== null) {
        $page = $this->getJson('/api/v1/contacts?limit=2&cursor='.urlencode($cursor), apiHeaders($this->token))->assertOk();
        $seen = $seen->merge(collect($page->json('data'))->pluck('id'));
        $cursor = $page->json('next_cursor');
    }
    expect($seen->unique()->count())->toBe(5)->and($seen->count())->toBe(5);
});

it('registra consentimientos solo si vienen, con origen api y la versión del texto', function () {
    $res = $this->postJson('/api/v1/contacts', ['phone' => '3001112233', 'email' => 'beto@ejemplo.com', 'consents' => [
        ['channel' => 'whatsapp', 'action' => 'granted', 'legal_text_version' => 'v7', 'page_url' => 'https://crm.ejemplo.com/form'],
    ]], apiHeaders($this->token))->assertCreated();
    $contactId = Contact::withoutWorkspaceScope()->get()->first(fn ($c) => $c->publicId() === $res->json('data.id'))->id;

    $row = DB::table('contact_consents')->where('contact_id', $contactId)->get();
    expect($row)->toHaveCount(1)->and($row[0]->channel)->toBe('whatsapp')->and($row[0]->source)->toBe('api')
        ->and($row[0]->legal_text_version)->toBe('v7');
    expect($res->json('data.consents.0.status'))->toBe('granted');

    $this->postJson('/api/v1/contacts', ['email' => 'carla@ejemplo.com'], apiHeaders($this->token))->assertCreated();
    expect(DB::table('contact_consents')->count())->toBe(1);
});

it('Idempotency-Key repite la misma respuesta y rechaza otro cuerpo con 409', function () {
    $headers = apiHeaders($this->token, ['Idempotency-Key' => 'alta-123']);
    $a = $this->postJson('/api/v1/contacts', ['email' => 'dora@ejemplo.com'], $headers)->assertCreated();
    $b = $this->postJson('/api/v1/contacts', ['email' => 'dora@ejemplo.com'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($b->json('data.id'))->toBe($a->json('data.id'));
    $this->postJson('/api/v1/contacts', ['email' => 'otra@ejemplo.com'], $headers)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
});

it('sin un sitio conectado explica que falta conectar uno', function () {
    $ws = $this->workspaceFor($this->userWith2fa(), 'Sin sitio');
    [$token] = apiToken($ws, ['contacts:write']);
    $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com'], apiHeaders($token))->assertUnprocessable()->assertJsonPath('error.code', 'no_site');
});

it('guarda la fecha de renovación (flujo 20) y la borra con null', function () {
    $res = $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com', 'renewal_at' => '2027-03-15'], apiHeaders($this->token))
        ->assertCreated()->assertJsonPath('data.renewal_at', '2027-03-15');
    $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com', 'renewal_at' => '15/03/2027'], apiHeaders($this->token))->assertUnprocessable();
    $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com'], apiHeaders($this->token))->assertOk()->assertJsonPath('data.renewal_at', '2027-03-15');
    $this->postJson('/api/v1/contacts', ['email' => 'ana@ejemplo.com', 'renewal_at' => null], apiHeaders($this->token))->assertOk()->assertJsonPath('data.renewal_at', null);
    expect($res->json('data.id'))->toStartWith('ct_');
});
