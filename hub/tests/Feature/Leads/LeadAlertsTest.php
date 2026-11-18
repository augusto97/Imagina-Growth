<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->ai->enabled = false;
    $this->messages = FakeMessageDispatcher::install();
    $this->seller = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->seller->forceFill(['name' => 'Carlos Vendedor', 'email' => 'carlos@negocio.co'])->save();
});

function leadsSeller($test, $user, ?string $phone = '+573001112233', array $services = []): LeadSeller
{
    return leadsRun($test, fn () => LeadSeller::query()->create(['workspace_id' => $test->ws->id, 'user_id' => $user->id, 'phone' => $phone, 'services' => $services, 'active' => true]));
}

function leadsWaTemplate($test): Template
{
    return leadsRun($test, fn () => Template::query()->create([
        'workspace_id' => $test->ws->id, 'channel' => 'whatsapp', 'name' => 'Aviso al equipo', 'category' => 'utility', 'status' => 'ready', 'library_key' => 'team_alert',
    ]));
}

it('avisa al instante por WhatsApp al vendedor asignado cuando el lead llega caliente, con hechos y enlace al lead', function () {
    leadsSettings($this, ['scoring' => ['thresholds' => ['warm' => 20, 'hot' => 40]]]);
    leadsSeller($this, $this->seller);
    $tpl = leadsWaTemplate($this);
    $contact = leadsContact($this);
    foreach ([3, 2, 1] as $d) {
        leadsChVisit($this, $contact, 'page.viewed', '/precios/', now()->subDays($d)->toIso8601String());
    }
    leadsForm($this, $contact);

    $lead = leadsOne($this);
    expect($lead->owner_id)->toBe($this->seller->id)->and($lead->temperature)->toBe('hot');
    $sent = array_values(array_filter($this->messages->dispatched, fn (OutboundMessage $m) => $m->sourceId === $lead->id));
    expect($sent)->toHaveCount(1);
    $m = $sent[0];
    expect($m->channel)->toBe(Channel::WhatsApp)->and($m->kind)->toBe(MessageKind::Utility)
        ->and($m->to)->toBe('+573001112233')->and($m->templateId)->toBe($tpl->id)
        ->and($m->context['aviso']['resumen'])->toBe("Lead caliente (puntaje {$lead->score}): Ana Ruiz, Bogotá, le interesa Asesoría laboral. Vino de Google Ads, vio Precios 3 veces")
        ->and($m->context['aviso']['url'])->toEndWith('/w/'.$this->ws->publicId().'/leads?lead='.$lead->publicId());
    expect(leadsActivities($this, $lead)->firstWhere('kind', 'alert')->data)->toMatchArray(['reason' => 'hot', 'channel' => 'whatsapp']);

    // Más señales no repiten el aviso.
    leadsBrowse($this, $contact, 'cta.clicked', '/', ['kind' => 'whatsapp', 'target' => 'https://wa.me/57300']);
    expect(array_filter($this->messages->dispatched, fn (OutboundMessage $m) => $m->sourceId === $lead->id))->toHaveCount(1);
});

it('sin plantilla de WhatsApp, sin teléfono o si el WhatsApp no sale, avisa por correo', function () {
    leadsSettings($this, ['scoring' => ['thresholds' => ['warm' => 20, 'hot' => 40]]]);
    leadsSeller($this, $this->seller, null);
    leadsWaTemplate($this);
    leadsForm($this, leadsContact($this));
    $mail = array_values(array_filter($this->messages->dispatched, fn (OutboundMessage $m) => $m->channel === Channel::Email));
    expect($mail)->toHaveCount(1)->and($mail[0]->to)->toBe('carlos@negocio.co')->and($mail[0]->kind)->toBe(MessageKind::Utility);

    // Con teléfono, pero el envío de WhatsApp se niega.
    leadsRun($this, fn () => LeadSeller::query()->first()->forceFill(['phone' => '+573001112233'])->save());
    $this->messages->respondWith(fn (OutboundMessage $m) => $m->channel === Channel::WhatsApp ? DispatchResult::denied('no_window') : null);
    leadsForm($this, leadsContact($this, ['email' => 'luis@constructora.co', 'first_name' => 'Luis']));
    $last = end($this->messages->dispatched);
    expect($last->channel)->toBe(Channel::Email)->and($last->to)->toBe('carlos@negocio.co');
});

it('sin vendedor avisa a los dueños; un lead tibio no avisa salvo que se pida aviso con cada lead nuevo', function () {
    leadsForm($this, leadsContact($this));
    expect($this->messages->dispatched)->toHaveCount(0); // 40 puntos: tibio

    leadsSettings($this, ['alerts' => ['on_new' => true]]);
    leadsForm($this, leadsContact($this, ['email' => 'luis@constructora.co', 'first_name' => 'Luis']));
    expect($this->messages->dispatched)->toHaveCount(1)
        ->and($this->messages->dispatched[0]->to)->toBe('duena@negocio.co')
        ->and($this->messages->dispatched[0]->context['aviso']['resumen'])->toStartWith('Lead nuevo (puntaje 40): Luis Ruiz');
});

it('asigna por turnos entre los vendedores activos del equipo, o por servicio con reglas', function () {
    $other = $this->addMember($this->ws, WorkspaceRole::Agent);
    $outsider = User::factory()->create();
    leadsSeller($this, $this->seller);
    leadsSeller($this, $other, null, ['pensión', 'jubilación']);
    leadsRun($this, fn () => LeadSeller::query()->create(['workspace_id' => $this->ws->id, 'user_id' => $outsider->id, 'active' => true]));

    $owners = [];
    foreach (['a@uno.co', 'b@dos.co', 'c@tres.co'] as $email) {
        leadsForm($this, leadsContact($this, ['email' => $email]));
        $owners[] = leadsOne($this)->owner_id;
    }
    expect($owners)->toBe([$this->seller->id, $other->id, $this->seller->id]);

    leadsSettings($this, ['assignment' => ['mode' => 'rules']]);
    leadsForm($this, leadsContact($this, ['email' => 'd@cuatro.co']), ['service' => 'Trámite de pensión']);
    expect(leadsOne($this)->owner_id)->toBe($other->id);

    leadsSettings($this, ['assignment' => ['mode' => 'none']]);
    leadsForm($this, leadsContact($this, ['email' => 'e@cinco.co']));
    expect(leadsOne($this)->owner_id)->toBeNull();
});
