<?php

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->url = "/api/w/{$this->ws->publicId()}/contacts/imports";
    $this->csv = "\xEF\xBB\xBFCorreo;Teléfono;Nombre;Ciudad;Etiquetas\n"
        ."ana@ejemplo.com;300 123 4567;Ana;Manizales;feria,vip\n"
        ."luis@ejemplo.com;;Luis;Pereira;\n"
        ."sin-datos;123;Nadie;;\n";
});

function csvFile(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('clientes.csv', $content);
}

it('sugiere el mapeo con una vista previa', function () {
    $res = $this->actingAs($this->owner)->post("{$this->url}/preview", ['file' => csvFile($this->csv)], ['Accept' => 'application/json'])->assertOk();
    expect($res->json('headers'))->toBe(['Correo', 'Teléfono', 'Nombre', 'Ciudad', 'Etiquetas'])
        ->and($res->json('suggested'))->toBe(['Correo' => 'email', 'Teléfono' => 'phone', 'Nombre' => 'first_name', 'Ciudad' => 'city', 'Etiquetas' => 'tags'])
        ->and($res->json('rows'))->toHaveCount(3);
});

it('importa con origen de consentimiento declarado, normaliza teléfonos y borra el archivo', function () {
    Storage::fake('local');
    $mapping = json_encode(['Correo' => 'email', 'Teléfono' => 'phone', 'Nombre' => 'first_name', 'Ciudad' => 'city', 'Etiquetas' => 'tags']);
    $consent = json_encode(['channels' => ['email', 'whatsapp'], 'source' => 'Formulario de la feria de pintura 2026', 'date' => '2026-10-01']);
    $res = $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($this->csv), 'mapping' => $mapping, 'consent' => $consent], ['Accept' => 'application/json'])->assertStatus(202);

    $show = $this->actingAs($this->owner)->getJson("{$this->url}/{$res->json('id')}")->assertOk();
    expect($show->json('status'))->toBe('completed')
        ->and($show->json('totals'))->toMatchArray(['rows' => 3, 'created' => 2, 'invalid' => 1, 'consents' => 3])
        ->and($show->json('errors.0.row'))->toBe(4);
    expect(Storage::disk('local')->allFiles())->toBe([]);

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $ana = Contact::query()->where('first_name', '!=', null)->get()->first(fn ($c) => $c->first_name === 'Ana');
        expect($ana->phone)->toBe('+573001234567')->and($ana->getAttribute('tags'))->toBe(['feria', 'vip'])
            ->and($ana->getAttribute('lifecycle_stage'))->toBe('subscriber');
        // Luis no tiene teléfono: solo consentimiento de correo.
        expect(ContactConsent::query()->where('source', 'import')->count())->toBe(3)
            ->and(ContactConsent::query()->value('granted_at')->toDateString())->toBe('2026-10-01');
    });
    expect(DB::table('audit_logs')->where('action', 'contacts.imported')->exists())->toBeTrue();
});

it('sin declaración de origen no crea consentimiento y no pisa datos existentes', function () {
    $mapping = json_encode(['Correo' => 'email', 'Teléfono' => 'phone', 'Nombre' => 'first_name', 'Ciudad' => 'city', 'Etiquetas' => 'ignore']);
    $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($this->csv), 'mapping' => $mapping], ['Accept' => 'application/json'])->assertStatus(202);
    $again = "Correo,Nombre,Ciudad\nana@ejemplo.com,Ana María,Bogotá\n";
    $res = $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($again), 'mapping' => json_encode(['Correo' => 'email', 'Nombre' => 'first_name', 'Ciudad' => 'city'])], ['Accept' => 'application/json'])->assertStatus(202);

    expect($this->actingAs($this->owner)->getJson("{$this->url}/{$res->json('id')}")->json('totals.skipped'))->toBe(1);
    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(ContactConsent::query()->count())->toBe(0)
            ->and(Contact::query()->get()->first(fn ($c) => $c->email === 'ana@ejemplo.com')->getAttribute('city'))->toBe('Manizales');
    });
});

it('exige declarar bien el origen del consentimiento y al menos correo o teléfono', function () {
    $mapping = json_encode(['Correo' => 'email']);
    $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($this->csv), 'mapping' => $mapping, 'consent' => json_encode(['channels' => ['email'], 'source' => 'feria', 'date' => '2026-10-01'])], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('consent');
    $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($this->csv), 'mapping' => json_encode(['Nombre' => 'first_name'])], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('mapping');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->post("{$this->url}/preview", ['file' => csvFile($this->csv)], ['Accept' => 'application/json'])->assertForbidden();
});

it('avisa del correo o el teléfono inválido de una fila que sí se importa', function () {
    $csv = "Correo,Teléfono,Nombre\njulian.mtz@example,3001112233,Julián\nmarta@ejemplo.com,12,Marta\n";
    $mapping = json_encode(['Correo' => 'email', 'Teléfono' => 'phone', 'Nombre' => 'first_name']);
    $res = $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($csv), 'mapping' => $mapping], ['Accept' => 'application/json'])->assertStatus(202);

    $show = $this->actingAs($this->owner)->getJson("{$this->url}/{$res->json('id')}")->assertOk();
    expect($show->json('totals'))->toMatchArray(['rows' => 2, 'created' => 2, 'invalid' => 0])
        ->and($show->json('errors'))->toHaveCount(2)
        ->and($show->json('errors.0'))->toMatchArray(['row' => 2])
        ->and($show->json('errors.0.message'))->toContain('Correo inválido')
        ->and($show->json('errors.1'))->toMatchArray(['row' => 3])
        ->and($show->json('errors.1.message'))->toContain('Teléfono inválido')
        ->and((string) json_encode($show->json('errors')))->not->toContain('julian');
});

it('importa la fecha de renovación para el flujo 20', function () {
    $csv = "Correo;Renovación\nana@ejemplo.com;2027-03-15\nluis@ejemplo.com;no es fecha\n";
    $this->actingAs($this->owner)->post($this->url, ['file' => csvFile($csv), 'mapping' => json_encode(['Correo' => 'email', 'Renovación' => 'renewal_at'])], ['Accept' => 'application/json'])->assertStatus(202);
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $byEmail = Contact::query()->get()->keyBy(fn ($c) => $c->email);
        expect($byEmail['ana@ejemplo.com']->getAttribute('renewal_at')?->toDateString())->toBe('2027-03-15')
            ->and($byEmail['luis@ejemplo.com']->getAttribute('renewal_at'))->toBeNull();
    });
});
