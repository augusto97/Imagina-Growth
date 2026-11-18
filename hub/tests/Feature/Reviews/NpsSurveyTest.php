<?php

use App\Domain\Contacts\Models\ContactNote;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Surveys\Actions\NpsSurvey;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Tests\Feature\Reviews\Support\ReviewsFixtures;

/*
 * M15 y flujo 17: encuesta NPS (y CSAT) por pedido, página pública de una
 * pregunta, nps.answered, alerta de detractores al dueño con una tarea en el
 * perfil, y el tablero.
 */

uses(ReviewsFixtures::class);

beforeEach(function () {
    $this->reviewsSetUp();
    $this->fake = FakeMessageDispatcher::install();
    $this->api = "/api/w/{$this->ws->publicId()}/reviews/surveys";
    $this->actingAs($this->owner)->putJson($this->api.'/nps', [
        'status' => 'active', 'question' => '¿Nos recomendarías?', 'comment_label' => '¿Por qué?', 'thanks' => '¡Gracias por responder!',
        'after' => 'completed', 'days' => 0, 'channels' => ['email'], 'alert_threshold' => 6, 'alert_emails' => ['soporte@pinturas.co'],
    ])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('scale.max', 10);
    $this->order = $this->reviewsOrder();
    $this->artisan('igr:reviews:send', ['--at' => CarbonImmutable::now()->addMinute()->toIso8601String()])->assertSuccessful();
    $this->req = $this->reviewsInWs(fn () => SurveyRequest::query()->sole());
    $this->path = '/r/nps/'.ReviewToken::forSurvey($this->req);
});

it('programa la encuesta al entregarse el pedido y la envía con la plantilla NPS de la biblioteca', function () {
    expect($this->req->status)->toBe('sent')->and($this->req->channel)->toBe('email')->and($this->req->order_id)->toBe($this->order->id);
    expect($this->fake->dispatched)->toHaveCount(1);
    $msg = $this->fake->dispatched[0];
    expect($msg->channel)->toBe(Channel::Email)->and($msg->kind)->toBe(MessageKind::Marketing)
        ->and($msg->idempotencyKey)->toBe('survey_request:'.$this->req->id.':email')
        ->and($msg->context['enlaces']['nps'])->toBe(ReviewToken::surveyUrl($this->req).'?nota=');
    expect($this->reviewsInWs(fn () => Template::query()->whereKey($msg->templateId)->value('library_key')))->toBe('nps_survey');
});

it('abrir el enlace no responde (los antivirus lo abren); enviar sí, con alerta y tarea si es detractor', function () {
    $this->get($this->path.'?nota=3')->assertOk()->assertSee('¿Nos recomendarías?')->assertSee('value="3" checked', false);
    expect($this->reviewsInWs(fn () => SurveyResponse::query()->count()))->toBe(0);

    $this->post($this->path, ['score' => 3, 'comment' => 'El pedido llegó tarde y nadie me respondió.'])->assertOk()->assertSee('¡Gracias por responder!');
    $response = $this->reviewsInWs(fn () => SurveyResponse::query()->sole());
    expect($response->score)->toBe(3)->and($response->comment)->toBe('El pedido llegó tarde y nadie me respondió.')
        ->and($response->contact_id)->toBe($this->order->contact_id)->and($response->external_order_id)->toBe('1234')
        ->and($this->req->fresh()->status)->toBe('answered');

    $rows = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'nps.answered'");
    expect($rows)->toHaveCount(1)->and($rows[0]['contact_id'])->toBe($this->order->contact_id)
        ->and(json_decode($rows[0]['p'], true))->toMatchArray(['kind' => 'nps', 'score' => 3, 'category' => 'detractor', 'order_id' => '1234'])
        ->and($rows[0]['p'])->not->toContain('tarde');

    $alerts = array_values(array_filter($this->fake->dispatched, fn ($m) => $m->to !== null));
    expect(array_map(fn ($m) => $m->to, $alerts))->toBe([$this->owner->email, 'soporte@pinturas.co'])
        ->and($alerts[0]->kind)->toBe(MessageKind::Utility)->and($alerts[0]->context['aviso']['titulo'])->toBe('Detractor en la encuesta NPS: nota 3')
        ->and($alerts[0]->context['aviso']['resumen'])->toBe('Ana R. respondió 3 en la encuesta NPS del pedido #1234.')
        ->and($alerts[0]->context['aviso']['detalle'])->toBe('El pedido llegó tarde y nadie me respondió.');
    $note = $this->reviewsInWs(fn () => ContactNote::query()->where('contact_id', $this->order->contact_id)->sole());
    expect($note->body)->toStartWith('Tarea: contactar a este cliente')->toContain('«El pedido llegó tarde');

    // Si vuelve a enviar, se actualiza sin repetir el evento ni la alerta.
    $count = count($this->fake->dispatched);
    $this->post($this->path, ['score' => 4])->assertOk();
    expect($response->fresh()->score)->toBe(4)->and($response->fresh()->comment)->toBe('El pedido llegó tarde y nadie me respondió.')
        ->and($this->fake->dispatched)->toHaveCount($count);
    expect($this->chRows($this->ws, "SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'nps.answered'")[0]['c'])->toBe(1);
});

it('un promotor no genera alerta; la nota fuera de escala se rechaza', function () {
    $this->post($this->path, ['score' => 11])->assertStatus(422)->assertSee('Elige una nota del 0 al 10.');
    $this->post($this->path, ['score' => 10])->assertOk();
    expect(array_filter($this->fake->dispatched, fn ($m) => $m->to !== null))->toBe([])
        ->and($this->reviewsInWs(fn () => ContactNote::query()->count()))->toBe(0);
    $rows = $this->chRows($this->ws, "SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'nps.answered'");
    expect(json_decode($rows[0]['p'], true)['category'])->toBe('promoter');
});

it('la encuesta CSAT va de 1 a 5 y el token de una no sirve en el formulario de reseña', function () {
    $csat = $this->reviewsInWs(fn () => app(NpsSurvey::class)->for($this->ws, 'csat'));
    $req = $this->reviewsInWs(fn () => SurveyRequest::query()->create([
        'workspace_id' => $this->ws->id, 'survey_id' => $csat->id, 'site_id' => $this->site->id, 'contact_id' => $this->order->contact_id, 'status' => 'sent', 'expires_at' => now()->addDays(5),
    ]));
    $path = '/r/nps/'.ReviewToken::forSurvey($req);
    $this->get($path)->assertOk()->assertSee('Muy satisfecho');
    $this->post($path, ['score' => 0])->assertStatus(422);
    $this->post($path, ['score' => 2])->assertOk();
    $rows = $this->chRows($this->ws, "SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'nps.answered'");
    expect(json_decode($rows[0]['p'], true))->toMatchArray(['kind' => 'csat', 'score' => 2, 'category' => 'unsatisfied']);

    $this->get('/r/'.ReviewToken::forSurvey($req))->assertNotFound();
});

it('el tablero calcula el NPS, la distribución y la evolución; los comentarios solo para quien ve contactos', function () {
    $this->post($this->path, ['score' => 9, 'comment' => 'Todo excelente'])->assertOk();
    $report = $this->actingAs($this->owner)->getJson($this->api.'/nps/report?days=30')->assertOk();
    $report->assertJsonPath('responses', 1)->assertJsonPath('sent', 1)->assertJsonPath('response_rate', 1)
        ->assertJsonPath('distribution.9', 1)->assertJsonPath('categories.promoter', 1)->assertJsonPath('recent.0.comment', 'Todo excelente');
    expect($report->json('score'))->toEqual(100)->and($report->json('trend'))->toHaveCount(1);

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($this->api.'/nps/report')->assertOk()->assertJsonPath('recent.0.comment', null);
    $this->actingAs($viewer)->putJson($this->api.'/nps', [])->assertForbidden();
});
