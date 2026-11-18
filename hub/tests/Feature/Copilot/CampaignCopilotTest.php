<?php

use App\Domain\Campaigns\Jobs\SendCampaignBatch;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Campaigns\CampaignFixtures;

uses(CampaignFixtures::class);

beforeEach(function () {
    // Miércoles 18 de noviembre de 2026, 9:00 en Bogotá.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00', 'America/Bogota'));
    $this->campaignWorkspace();
    $this->vips = [];
    foreach (['Ana', 'Luis', 'Marta', 'Pedro'] as $name) {
        $this->vips[] = $this->contact($name, ['whatsapp', 'email'], ['lifecycle_stage' => 'vip']);
    }
    $this->vip = $this->segmentOf('Clientes VIP', $this->vips);
    $this->waTemplate = copilotWaTemplate($this, true);
});

/** Plantilla de WhatsApp de la tabla `templates` enlazada a una de Meta; devuelve el ID público. */
function copilotWaTemplate($test, bool $approved, string $name = 'Oferta VIP'): string
{
    $wa = strtoupper((string) Str::ulid());
    $account = (string) DB::table('channel_accounts')->where('workspace_id', $test->ws->id)->where('channel', 'whatsapp')->value('id');
    DB::table('wa_templates')->insert(['id' => $wa, 'workspace_id' => $test->ws->id, 'channel_account_id' => $account, 'name' => Str::slug($name, '_'), 'language' => 'es', 'category' => 'MARKETING',
        'status' => $approved ? 'APPROVED' : 'PENDING', 'components' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $id = strtoupper((string) Str::ulid());
    DB::table('templates')->insert(['id' => $id, 'workspace_id' => $test->ws->id, 'channel' => 'whatsapp', 'name' => $name, 'category' => 'marketing',
        'status' => 'ready', 'body_text' => 'Hola {{1}}, tenemos algo para ti', 'wa_template_id' => $wa, 'created_at' => now(), 'updated_at' => now()]);

    return 'tpl_'.$id;
}

/** Propuesta tal como la devuelve el modelo. */
function copilotProposal($test, array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Black Friday VIP',
        'segment' => ['mode' => 'existing', 'segment_id' => $test->vip->publicId(), 'name' => null, 'definition_json' => null],
        'channels' => [
            ['channel' => 'whatsapp', 'template_id' => $test->waTemplate, 'library_key' => null, 'subject' => null, 'preheader' => null, 'headline' => null, 'intro' => null, 'cta' => null],
            ['channel' => 'email', 'template_id' => null, 'library_key' => 'winback_2', 'subject' => 'Tu 20% de Black Friday', 'preheader' => 'Solo para clientes VIP', 'headline' => 'Black Friday VIP', 'intro' => 'Tienes 20% en toda la tienda.', 'cta' => 'Comprar ahora'],
        ],
        'schedule' => ['date' => '2026-11-27', 'hour' => 10, 'send_mode' => 'scheduled', 'reason' => 'Viernes de Black Friday a media mañana'],
        'coupon' => ['enabled' => true, 'kind' => 'percent', 'amount' => 20, 'expires_in_days' => 3, 'minimum_amount' => null],
        'holdout_pct' => 10,
    ], $overrides);
}

function copilotApi($test, string $path = ''): string
{
    return "/api/w/{$test->ws->publicId()}/copilot".$path;
}

it('propone una campaña completa con herramientas, revisión de costo y cumplimiento, y resumen validado; no crea ni envía nada', function () {
    Bus::fake([SendCampaignBatch::class]);
    $ai = FakeAiGateway::install()
        ->callTool('list_segments', [])
        ->callTool('list_templates', ['channel' => 'whatsapp'])
        ->callTool('check_compliance', ['channel' => 'whatsapp', 'segment_id' => $this->vip->publicId(), 'definition_json' => null, 'send_mode' => 'scheduled', 'date' => '2026-11-27', 'hour' => 10])
        ->replyJson(copilotProposal($this))
        ->reply('La campaña llega a los 4 clientes VIP por WhatsApp y correo con 20% de descuento.');

    $res = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Haz una campaña de Black Friday para VIP con 20%'])->assertCreated();
    $p = $res->json('proposal');
    expect($res->json('kind'))->toBe('campaign')->and($res->json('status'))->toBe('draft')
        ->and($p['segment'])->toMatchArray(['mode' => 'existing', 'segment_id' => $this->vip->publicId(), 'members' => 4])
        ->and(array_column($p['channels'], 'channel'))->toBe(['whatsapp', 'email'])
        ->and($p['channels'][0]['review']['audience'])->toBe(4)
        ->and($p['channels'][1]['template'])->toMatchArray(['mode' => 'library', 'library_key' => 'winback_2'])
        ->and($p['channels'][1]['template']['copy']['subject'])->toBe('Tu 20% de Black Friday')
        ->and($p['schedule'])->toMatchArray(['local' => '2026-11-27 10:00', 'send_mode' => 'scheduled', 'note' => null])
        ->and($p['coupon'])->toMatchArray(['enabled' => true, 'amount' => 20.0, 'expires_in_days' => 3])
        ->and($p['summary_source'])->toBe('ai')->and($p['summary'])->toContain('4 clientes');
    // La herramienta de cumplimiento corrió la misma revisión del asistente sin dejar rastro.
    $compliance = $ai->requests[0]['tools'];
    expect($compliance)->toContain('preview_segment', 'estimate_cost', 'check_compliance', 'commercial_calendar')
        ->and($this->inWs(fn () => Campaign::query()->count()))->toBe(0)
        ->and($this->inWs(fn () => Segment::query()->where('name', 'copiloto')->count()))->toBe(0)
        ->and(DB::table('messages')->count())->toBe(0)
        ->and((int) DB::table('ai_usage')->where('workspace_id', $this->ws->id)->where('feature', 'copilot')->sum('requests'))->toBeGreaterThanOrEqual(5);
    Bus::assertNotDispatched(SendCampaignBatch::class);
});

it('nunca propone domingos, festivos ni fuera de horario en Colombia: mueve al siguiente momento permitido', function () {
    // Domingo 22 de noviembre a las 10:00 → lunes 23 a las 7:00.
    FakeAiGateway::install()->replyJson(copilotProposal($this, ['schedule' => ['date' => '2026-11-22', 'hour' => 10]]));
    $p = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña VIP para el domingo'])->assertCreated()->json('proposal.schedule');
    expect($p['local'])->toBe('2026-11-23 07:00')->and($p['note'])->toContain('domingo');

    // Martes 8 de diciembre (Inmaculada Concepción, festivo) → miércoles 9 a las 7:00; 21:00 → 7:00 del día siguiente.
    FakeAiGateway::install()->replyJson(copilotProposal($this, ['schedule' => ['date' => '2026-12-08', 'hour' => 11]]));
    expect($this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña VIP de diciembre'])->json('proposal.schedule.local'))->toBe('2026-12-09 07:00');
    FakeAiGateway::install()->replyJson(copilotProposal($this, ['schedule' => ['date' => '2026-11-24', 'hour' => 21]]));
    expect($this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña VIP de noche'])->json('proposal.schedule.local'))->toBe('2026-11-25 07:00');
});

it('un segmento nuevo inválido se corrige una vez; si sigue inválido, error claro', function () {
    $bad = ['mode' => 'new', 'segment_id' => null, 'name' => 'VIP de Bogotá', 'definition_json' => json_encode(['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'signo_zodiacal', 'operator' => 'eq', 'value' => 'leo']]])];
    $good = ['mode' => 'new', 'segment_id' => null, 'name' => 'VIP de Bogotá', 'definition_json' => json_encode(['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'lifecycle_stage', 'operator' => 'eq', 'value' => 'vip']]])];
    $ai = FakeAiGateway::install()->replyJson(copilotProposal($this, ['segment' => $bad]))->replyJson(copilotProposal($this, ['segment' => $good]));
    $res = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña para VIP de Bogotá'])->assertCreated();
    expect($res->json('proposal.segment'))->toMatchArray(['mode' => 'new', 'name' => 'VIP de Bogotá', 'members' => 4])
        ->and($ai->requests[1]['request']->messages[2]['content'])->toContain('Atributo desconocido');

    FakeAiGateway::install()->replyJson(copilotProposal($this, ['segment' => $bad]))->replyJson(copilotProposal($this, ['segment' => $bad]));
    $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña para VIP de Bogotá'])->assertUnprocessable()
        ->assertJsonPath('errors.segment', fn ($m) => str_contains($m, 'no es válido'));
    expect($this->inWs(fn () => AiDraft::query()->count()))->toBe(1);
});

it('WhatsApp solo con plantilla aprobada por Meta y textos sin cifras inventadas', function () {
    DB::table('wa_templates')->update(['status' => 'PENDING']);
    // Sin ninguna aprobada, WhatsApp se quita con un aviso; el correo inventa un 35% y se corrige.
    FakeAiGateway::install()
        ->replyJson(copilotProposal($this, ['channels' => [1 => ['intro' => 'Hoy 35% en toda la tienda.']]]))
        ->replyJson(copilotProposal($this));
    $p = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP con 20%'])->assertCreated()->json('proposal');
    expect(array_column($p['channels'], 'channel'))->toBe(['email'])
        ->and(implode(' ', $p['warnings']))->toContain('no hay una plantilla aprobada')
        ->and($p['channels'][0]['template']['copy']['intro'])->toBe('Tienes 20% en toda la tienda.');

    // Si insiste con la cifra inventada, ese texto se quita (queda el del diseño) con un aviso.
    FakeAiGateway::install()
        ->replyJson(copilotProposal($this, ['channels' => [1 => ['intro' => 'Hoy 35% en toda la tienda.']]]))
        ->replyJson(copilotProposal($this, ['channels' => [1 => ['intro' => 'Hoy 35% en toda la tienda.']]]));
    $p = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP con 20%'])->assertCreated()->json('proposal');
    expect($p['channels'][0]['template']['copy'])->not->toHaveKey('intro')->and(implode(' ', $p['warnings']))->toContain('35%');
});

it('aplicar crea el segmento, la plantilla y campañas en borrador por canal; nunca programa ni envía', function () {
    Bus::fake([SendCampaignBatch::class]);
    $good = ['mode' => 'new', 'segment_id' => null, 'name' => 'VIP activos', 'definition_json' => json_encode(['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'lifecycle_stage', 'operator' => 'eq', 'value' => 'vip']]])];
    FakeAiGateway::install()->replyJson(copilotProposal($this, ['segment' => $good]));
    $id = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Haz una campaña de Black Friday para VIP con 20%'])->json('id');

    $res = $this->actingAs($this->owner)->postJson(copilotApi($this, "/drafts/{$id}/apply"))->assertOk();
    expect($res->json('status'))->toBe('applied')->and($res->json('applied'))->toHaveCount(2);
    $this->inWs(function () {
        $campaigns = Campaign::query()->orderBy('channel')->get();
        expect($campaigns)->toHaveCount(2)
            ->and($campaigns->pluck('status')->unique()->all())->toBe([Campaign::DRAFT])
            ->and($campaigns->pluck('name')->all())->toBe(['Black Friday VIP · Correo', 'Black Friday VIP · WhatsApp'])
            ->and($campaigns[0]->send_mode)->toBe('scheduled')->and($campaigns[0]->holdout_pct)->toBe(10)
            ->and($campaigns[0]->send_at?->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-27 10:00');
        $segment = Segment::query()->where('name', 'VIP activos')->firstOrFail();
        expect($campaigns[0]->audience['segments_include'])->toBe([$segment->publicId()]);
        $template = Template::query()->findOrFail($campaigns[0]->template_id);
        expect($template->subject)->toBe('Tu 20% de Black Friday')->and($template->library_key)->toBeNull()->and($template->body_html)->toContain('Tienes 20% en toda la tienda.');
    });
    expect(DB::table('messages')->count())->toBe(0);
    Bus::assertNotDispatched(SendCampaignBatch::class);
    $this->actingAs($this->owner)->postJson(copilotApi($this, "/drafts/{$id}/apply"))->assertUnprocessable();
});

it('la persona edita la propuesta antes de aplicarla: las mismas reglas, sin IA', function () {
    FakeAiGateway::install()->replyJson(copilotProposal($this));
    $id = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP con 20%'])->json('id');
    $ai = FakeAiGateway::install();

    $res = $this->actingAs($this->owner)->patchJson(copilotApi($this, "/drafts/{$id}"), [
        'name' => 'Black Friday VIP 2026', 'schedule' => ['hour' => 20], 'channels' => [1 => ['subject' => 'Gana 50% hoy']],
    ])->assertOk();
    expect($res->json('proposal.name'))->toBe('Black Friday VIP 2026')
        ->and($res->json('proposal.schedule.local'))->toBe('2026-11-28 08:00') // sábado: de 8 a 15
        ->and($res->json('proposal.channels.1.template.copy'))->not->toHaveKey('subject')
        ->and($res->json('proposal.summary_source'))->toBe('template')
        ->and($ai->requests)->toBe([]);
});

it('sin IA el copiloto lo dice y el asistente sigue funcionando; permisos y aislamiento', function () {
    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    $this->actingAs($this->owner)->getJson(copilotApi($this, '/status'))->assertOk()->assertJsonPath('available', false);
    $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP'])->assertStatus(503)
        ->assertJsonPath('reason', 'disabled')->assertJsonPath('message', fn ($m) => str_contains($m, 'a mano'));
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/campaigns", ['name' => 'A mano', 'channel' => 'email'])->assertCreated();

    $ai->enabled = true;
    $ai->replyJson(copilotProposal($this));
    $id = $this->actingAs($this->owner)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP'])->json('id');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer, true);
    $this->actingAs($viewer)->postJson(copilotApi($this, '/campaigns'), ['prompt' => 'Campaña de Black Friday para VIP'])->assertForbidden();
    $this->actingAs($viewer)->postJson(copilotApi($this, "/drafts/{$id}/apply"))->assertForbidden();

    $other = $this->userWith2fa();
    $ws2 = $this->workspaceFor($other);
    $this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/copilot/drafts/{$id}")->assertNotFound();
    expect($this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/copilot/drafts")->json('data'))->toBe([]);
});
