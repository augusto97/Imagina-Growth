<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Identity\SiteToken;
use App\Domain\Surveys\Jobs\AttachSurveyResponse;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function orderToken($test, string $orderId, int $ttl = 600): string
{
    return SiteToken::sign($test->secret, ['order' => $orderId, 'site' => $test->site->publicId(), 'exp' => time() + $ttl]);
}

function answerSurvey($test, string $surveyId, array $body)
{
    return $test->call('POST', "/p/{$test->site->public_key}/surveys/{$surveyId}/answers", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode($body));
}

it('ofrece la encuesta solo en la página de gracias, cacheable y con CORS del sitio', function () {
    $res = $this->call('GET', "/p/{$this->site->public_key}/widgets.json?page_type=thankyou", [], [], [], ['HTTP_ORIGIN' => 'https://www.tienda.co'])->assertOk();
    expect($res->json('widgets.0.kind'))->toBe('hdyhau_survey')
        ->and($res->json('widgets.0.question'))->toBe('¿Cómo nos conociste?')
        ->and($res->json('widgets.0.options'))->toHaveCount(10)
        ->and($res->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://www.tienda.co');
    $this->getJson("/p/{$this->site->public_key}/widgets.json?page_type=product")->assertOk()->assertJsonCount(0, 'widgets');
    $this->getJson('/p/pk_'.str_repeat('a', 32).'/widgets.json')->assertNotFound();
});

it('guarda la respuesta con el token del pedido, la vincula al contacto y no acepta duplicados ni tokens falsos', function () {
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();
    $surveyId = $this->getJson("/p/{$this->site->public_key}/widgets.json?page_type=thankyou")->json('widgets.0.id');

    answerSurvey($this, $surveyId, ['order_token' => SiteToken::sign('sk_falso', ['order' => '1234', 'site' => $this->site->publicId(), 'exp' => time() + 60]), 'answer' => 'instagram'])->assertStatus(422);
    answerSurvey($this, $surveyId, ['order_token' => orderToken($this, '1234'), 'answer' => 'no-existe'])->assertStatus(422);
    answerSurvey($this, $surveyId, ['order_token' => orderToken($this, '1234'), 'answer' => 'instagram'])->assertStatus(202);
    answerSurvey($this, $surveyId, ['order_token' => orderToken($this, '1234'), 'answer' => 'google'])->assertStatus(409);

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $response = SurveyResponse::query()->firstOrFail();
        $contact = Contact::query()->firstOrFail();
        expect($response->contact_id)->toBe($contact->id)
            ->and($response->order_id)->not->toBeNull()
            ->and($contact->getAttribute('hdyhau'))->toBe('instagram');
    });
    $events = $this->chRows($this->ws, "SELECT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'survey.answered'");
    expect($events)->toHaveCount(1)->and($events[0]['contact_id'])->not->toBe('');
});

it('vincula después la respuesta que llegó antes que el pedido', function () {
    $surveyId = $this->getJson("/p/{$this->site->public_key}/widgets.json?page_type=thankyou")->json('widgets.0.id');
    answerSurvey($this, $surveyId, ['order_token' => orderToken($this, '1234'), 'answer' => 'otro', 'other' => 'Una valla en la autopista'])->assertStatus(202);
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $response = SurveyResponse::query()->firstOrFail();
        expect($response->order_id)->toBeNull();
        (new AttachSurveyResponse($this->ws->id, $response->id))->handle();
        expect($response->fresh()->order_id)->not->toBeNull()
            ->and($response->fresh()->answers['other'])->toBe('Una valla en la autopista')
            ->and(Contact::query()->value('hdyhau'))->toBe('otro');
    });
});

it('el panel configura las opciones sin romper las claves existentes', function () {
    $url = "/api/w/{$this->ws->publicId()}/surveys/hdyhau";
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('status', 'active');
    $this->actingAs($this->owner)->putJson($url, [
        'status' => 'active', 'title' => 'Ayúdanos', 'question' => '¿Por dónde nos encontraste?', 'thanks' => 'Gracias',
        'options' => [['key' => 'instagram', 'label' => 'Insta'], ['label' => 'Feria de Manizales']], 'allow_other' => false,
    ])->assertOk()->assertJsonPath('questions.options.1.key', 'feria_de_manizales')->assertJsonPath('questions.options.0.key', 'instagram');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->putJson($url, [])->assertForbidden();
});
