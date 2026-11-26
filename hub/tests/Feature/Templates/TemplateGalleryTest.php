<?php

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Galería de plantillas por objetivo y vertical (rediseño v2 §11.5, modulos.md M08): se lista desde la biblioteca y
 * se instala con un clic (idempotente). Las de WhatsApp se envían a revisión de Meta si hay un número oficial y los
 * flujos no las usan hasta que Meta las aprueba.
 */

beforeEach(function () {
    config(['igr-messaging.meta.graph_url' => 'https://graph.test', 'igr-messaging.links_url' => 'https://go.test']);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['vertical' => 'health', 'industry' => 'salud_bienestar', 'country' => 'CO'])->save();
    $this->url = "/api/w/{$this->ws->publicId()}/templates";
});

function galleryWhatsapp($test): ChannelAccount
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => ChannelAccount::query()->create([
        'workspace_id' => $test->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp',
        'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true, 'external_id' => '1111', 'identity' => '+57 300 0000000',
        'config' => ['waba_id' => '2222', 'phone_number_id' => '1111', 'access_token' => 'EAAB-token-de-prueba'],
    ]));
}

it('lista la galería por objetivo, vertical y canal, con la copia de la vertical del espacio de trabajo', function () {
    $all = $this->actingAs($this->owner)->getJson("{$this->url}/gallery")->assertOk();
    expect($all->json('vertical'))->toBe('health')->and($all->json('industry'))->toBe('salud_bienestar')
        ->and($all->json('goals'))->toBe(TemplateLibrary::GOALS)
        ->and(collect($all->json('data'))->pluck('verticals')->every(fn ($v) => in_array('all', $v, true) || in_array('health', $v, true)))->toBeTrue()
        // Los de cursos o propiedades no son de salud.
        ->and(collect($all->json('data'))->pluck('key'))->not->toContain('enrollment_info')->not->toContain('property_interest');

    $reminders = $this->actingAs($this->owner)->getJson("{$this->url}/gallery?goal=reminder&channel=email")->assertOk();
    $gal = collect($reminders->json('data'))->firstWhere('key', 'gal_reminder');
    expect(collect($reminders->json('data'))->pluck('channel')->unique()->all())->toBe(['email'])
        ->and($gal['subject'])->not->toBeEmpty()->and($gal['installed'])->toBeNull();

    $whatsapp = collect($this->actingAs($this->owner)->getJson("{$this->url}/gallery?channel=whatsapp&vertical=all")->assertOk()->json('data'));
    expect($whatsapp->pluck('key'))->toContain('wa_gal_seasonal', 'wa_enrollment_info', 'wa_delivery_check')
        ->and($whatsapp->firstWhere('key', 'wa_gal_review')['whatsapp']['body'])->toContain('{{1}}')
        ->and($whatsapp->firstWhere('key', 'wa_gal_review')['whatsapp']['buttons'][0]['type'])->toBe('URL');

    $this->actingAs($this->owner)->getJson("{$this->url}/gallery?goal=no_existe")->assertUnprocessable();
    $preview = $this->actingAs($this->owner)->getJson("{$this->url}/library/wa_gal_review/preview")->assertOk();
    expect($preview->json('text'))->toContain('Clínica Sonrisa')->and($preview->json('whatsapp.category'))->toBe('marketing');
});

it('instalar un correo de la galería es de un clic e idempotente', function () {
    $first = $this->actingAs($this->owner)->postJson("{$this->url}/library/gal_reminder")->assertCreated();
    expect($first->json('status'))->toBe('ready')->and($first->json('whatsapp_submission'))->toBeNull()
        ->and($first->json('subject'))->toContain('próxima visita')
        // La copia de la industria de la vertical (salud).
        ->and(DB::table('templates')->where('workspace_id', $this->ws->id)->value('body_html'))->toContain('Es hora de tu control');
    $again = $this->actingAs($this->owner)->postJson("{$this->url}/library/gal_reminder")->assertOk();
    expect($again->json('id'))->toBe($first->json('id'));
    expect(collect($this->actingAs($this->owner)->getJson("{$this->url}/gallery?goal=reminder")->json('data'))->firstWhere('key', 'gal_reminder')['installed']['id'])->toBe($first->json('id'));
});

it('una de WhatsApp sin número oficial queda en borrador; con número se envía a Meta y se enlaza una sola vez', function () {
    $draft = $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_welcome")->assertCreated();
    expect($draft->json('status'))->toBe('draft')->and($draft->json('whatsapp_submission.status'))->toBe('not_connected');

    Http::fake(['graph.test/*/2222/message_templates*' => Http::response(['id' => '999', 'status' => 'PENDING', 'category' => 'MARKETING'])]);
    $wa = galleryWhatsapp($this);
    $sent = $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_welcome")->assertOk();
    expect($sent->json('id'))->toBe($draft->json('id'))->and($sent->json('whatsapp_submission.status'))->toBe('submitted')
        ->and($sent->json('whatsapp_submission.wa_status'))->toBe('PENDING');
    $request = array_values(array_filter(Http::recorded()->all(), fn ($p) => str_contains($p[0]->url(), 'message_templates')))[0][0];
    expect($request->data()['name'])->toBe('igr_gal_welcome')->and($request->data()['category'])->toBe('MARKETING')->and($request->data()['language'])->toBe('es')
        ->and($request->data()['components'][0]['text'])->toBe('Hola {{1}}, bienvenido a {{2}}. Por aquí te contaremos novedades y beneficios. Si necesitas algo, escríbenos cuando quieras.')
        ->and($request->data()['components'][0]['example']['body_text'][0])->toHaveCount(2);
    $template = app(CurrentWorkspace::class)->run($this->ws, fn () => Template::query()->where('library_key', 'wa_gal_welcome')->sole());
    expect($template->wa_template_id)->not->toBeNull()->and($template->variables['body'][1])->toBe("{{ contacto.nombre | default: 'hola' }}")
        ->and(DB::table('audit_logs')->where('action', 'wa_template.submitted')->count())->toBe(1);

    // Otra vez: ya está enlazada, no vuelve a Meta.
    $again = $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_welcome")->assertOk();
    expect($again->json('whatsapp_submission.status'))->toBe('linked')
        ->and(count(array_filter(Http::recorded()->all(), fn ($p) => str_contains($p[0]->url(), 'message_templates'))))->toBe(1);

    // Si el número ya tiene una con ese nombre, la enlaza sin enviar otra.
    app(CurrentWorkspace::class)->run($this->ws, fn () => WaTemplate::query()->create([
        'workspace_id' => $this->ws->id, 'channel_account_id' => $wa->id, 'name' => 'igr_gal_review', 'language' => 'es', 'category' => 'MARKETING',
        'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
    ]));
    $review = $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_review")->assertCreated();
    expect($review->json('whatsapp_submission'))->toMatchArray(['status' => 'linked', 'wa_status' => 'APPROVED'])
        ->and(count(array_filter(Http::recorded()->all(), fn ($p) => str_contains($p[0]->url(), 'message_templates'))))->toBe(1);
});

it('los flujos no usan una de WhatsApp de la galería hasta que Meta la aprueba', function () {
    Http::fake(['graph.test/*/2222/message_templates*' => Http::response(['id' => '321', 'status' => 'PENDING'])]);
    galleryWhatsapp($this);
    $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_quote_followup_1")->assertCreated();
    expect(TemplateRef::resolve($this->ws->id, 'whatsapp', null, 'wa_quote_followup_1'))->toBeNull()
        ->and(TemplateRef::usableKeys($this->ws->id))->not->toHaveKey('wa_quote_followup_1');
    $library = collect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/flows/library")->json('data'))->firstWhere('key', 'quote_followup');
    expect($library['missing_templates'])->toContain('wa_quote_followup_1');

    DB::table('wa_templates')->where('workspace_id', $this->ws->id)->update(['status' => 'APPROVED']);
    expect(TemplateRef::resolve($this->ws->id, 'whatsapp', null, 'wa_quote_followup_1'))->not->toBeNull()
        ->and(TemplateRef::usableKeys($this->ws->id))->toHaveKey('wa_quote_followup_1');
});

it('un rechazo de Meta deja la plantilla en borrador con el motivo', function () {
    Http::fake(['graph.test/*/2222/message_templates*' => Http::response(['error' => ['message' => 'Template name already exists']], 400)]);
    galleryWhatsapp($this);
    $res = $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_promotion")->assertCreated();
    expect($res->json('status'))->toBe('draft')->and($res->json('whatsapp_submission.status'))->toBe('failed')
        ->and($res->json('whatsapp_submission.message'))->toContain('Meta no aceptó');
    $this->actingAs($this->owner)->postJson("{$this->url}/library/wa_gal_promotion", ['account' => 'cha_01NOEXISTE00000000000000000'])->assertUnprocessable();
});

it('permisos y aislamiento: un lector ve la galería pero no instala; otro espacio de trabajo no existe', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->url}/gallery")->assertOk();
    $this->actingAs($viewer)->postJson("{$this->url}/library/gal_welcome")->assertForbidden();
    $stranger = $this->userWith2fa();
    $this->workspaceFor($stranger, 'Otra clínica');
    $this->actingAs($stranger)->getJson("{$this->url}/gallery")->assertNotFound();
    $this->actingAs($stranger)->postJson("{$this->url}/library/gal_welcome")->assertNotFound();
    expect(DB::table('templates')->where('workspace_id', $this->ws->id)->count())->toBe(0);
});
