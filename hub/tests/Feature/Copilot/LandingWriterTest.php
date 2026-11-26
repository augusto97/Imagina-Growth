<?php

use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use Tests\Feature\Campaigns\CampaignFixtures;

uses(CampaignFixtures::class);

/*
 * «Escribir con IA» de las páginas de captura (M12): los textos de la página con el tono de marca, recortados a sus
 * límites, sin cifras que no estén en el pedido, y el borrador en `ai_drafts` como historial.
 */

beforeEach(function () {
    $this->campaignWorkspace();
});

function landingTexts(array $overrides = []): array
{
    return $overrides + [
        'eyebrow' => 'Primera valoración sin costo',
        'title' => 'Una sonrisa sana empieza con una valoración',
        'body' => "Agenda tu valoración de ortodoncia y te explicamos tus opciones.\n\nTe escribimos por WhatsApp.",
        'benefits' => ['Te atiende un especialista', 'Sin costo la primera vez', '', 'Eliges el horario'],
        'faq' => [['q' => '¿La valoración tiene costo?', 'a' => 'La primera es sin costo.'], ['q' => '', 'a' => 'sin pregunta']],
        'button' => 'Quiero mi valoración',
    ];
}

it('escribe los textos de la página con el tono de marca y los deja en el historial', function () {
    $ai = FakeAiGateway::install()->replyJson(landingTexts());
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/landing", [
        'goal' => 'Valoración de ortodoncia para adultos', 'offer' => 'Primera valoración sin costo',
    ])->assertOk();
    expect($res->json('texts'))->toMatchArray([
        'eyebrow' => 'Primera valoración sin costo',
        'title' => 'Una sonrisa sana empieza con una valoración',
        'benefits' => ['Te atiende un especialista', 'Sin costo la primera vez', 'Eliges el horario'],
        'faq' => [['q' => '¿La valoración tiene costo?', 'a' => 'La primera es sin costo.']],
        'button' => 'Quiero mi valoración',
    ])->and($ai->requests[0]['request']->messages[0]['content'])->toContain('Valoración de ortodoncia')
        ->and($this->inWs(fn () => AiDraft::query()->where('kind', 'content')->count()))->toBe(1);
});

it('no entrega textos con cifras inventadas y sin IA lo dice en palabras', function () {
    $bad = landingTexts(['title' => 'Ahorra 40% en tu valoración']);
    FakeAiGateway::install()->replyJson($bad)->replyJson($bad);
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/landing", ['goal' => 'Valoración de ortodoncia'])->assertUnprocessable();

    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/landing", ['goal' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('goal');
});
