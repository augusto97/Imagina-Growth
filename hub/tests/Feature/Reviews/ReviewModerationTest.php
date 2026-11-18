<?php

use App\Domain\Reviews\Models\Review;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reviews\Support\ReviewsFixtures;

/*
 * M15: cola de moderación, publicación en WooCommerce (review.publish con los
 * medios como URL pública), retiro (review.unpublish), respuesta del dueño
 * (review.reply), proyección de las nativas sin duplicar y permisos.
 */

uses(ReviewsFixtures::class);

beforeEach(function () {
    $this->reviewsSetUp();
    Storage::fake('local');
    $this->commands = [];
    Http::fake(['tienda.co/*' => function (Request $request) {
        $this->commands[] = ['command' => $request['command'], 'args' => $request['args']];

        return Http::response(['ok' => true, 'data' => match ($request['command']) {
            'review.publish' => ['comment_id' => '551'],
            'review.reply' => ['comment_id' => '552'],
            default => ['ok' => true],
        }]);
    }]);
    $this->request = $this->reviewsSentRequest();
    $img = imagecreatetruecolor(640, 480);
    ob_start();
    imagejpeg($img);
    $photo = UploadedFile::fake()->createWithContent('foto.jpg', (string) ob_get_clean());
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'title' => 'Excelente', 'body' => 'Cubre en una mano.', 'author_name' => 'Ana R.', 'media' => [$photo]])->assertStatus(303);
    $this->review = $this->reviewsInWs(fn () => Review::query()->firstOrFail());
    $this->api = "/api/w/{$this->ws->publicId()}/reviews";
});

function reviewsSiteCommands($test, string $command): array
{
    return array_values(array_filter($test->commands, fn ($c) => $c['command'] === $command));
}

it('lista la cola de pendientes y aprueba: publica en WooCommerce con comprador verificado y la foto pública', function () {
    $this->actingAs($this->owner)->getJson($this->api.'?status=pending')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->review->publicId())
        ->assertJsonPath('data.0.product.name', 'Pintura Azul 1 galón')->assertJsonPath('data.0.order.number', '1234')
        ->assertJsonPath('data.0.verified', true)->assertJsonCount(1, 'data.0.media');
    // La foto de una pendiente solo se ve con la URL firmada de la moderación.
    $signed = $this->actingAs($this->owner)->getJson($this->api.'/'.$this->review->publicId())->json('media.0.url');
    expect($signed)->toContain('signature=');
    $this->get($signed)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $key = $this->review->media[0]['key'];
    $this->get('/r/m/'.$this->review->id.'/'.$key)->assertNotFound();

    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertOk()
        ->assertJsonPath('status', 'published')->assertJsonPath('on_store', true)->assertJsonPath('sync_status', 'synced');

    $publish = reviewsSiteCommands($this, 'review.publish');
    expect($publish)->toHaveCount(1);
    expect($publish[0]['args'])->toMatchArray([
        'review_id' => $this->review->publicId(), 'product_id' => '88', 'rating' => 5, 'title' => 'Excelente', 'body' => 'Cubre en una mano.',
        'author_name' => 'Ana R.', 'author_email' => 'ana@ejemplo.com', 'verified' => true, 'order_id' => '1234',
    ])->and($publish[0]['args']['media'][0]['url'])->toBe(rtrim((string) config('app.url'), '/').'/r/m/'.$this->review->id.'/'.$key)
        ->and($publish[0]['args']['media'][0]['type'])->toBe('image');
    // Publicada, la foto ya es pública.
    $this->get('/r/m/'.$this->review->id.'/'.$key)->assertOk()->assertHeader('Cache-Control', 'max-age=3600, public');
    expect($this->review->fresh()->external_review_id)->toBe('551');

    $audit = DB::table('audit_logs')->where('action', 'review.approved')->first();
    expect($audit)->not->toBeNull()->and($audit->subject_id)->toBe($this->review->id);
});

it('el review.submitted del comentario que creó el hub no duplica la reseña', function () {
    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertOk();
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'review.submitted', ['product_id' => '88', 'rating' => 5, 'verified_owner' => true, 'review_id' => '551', 'status' => 'approved']),
        $this->envelope($this->site, 'review.submitted', ['product_id' => '91', 'rating' => 3, 'verified_owner' => false, 'review_id' => '600', 'status' => 'approved', 'created_at' => '2026-10-01T10:00:00-05:00']),
    ]);
    $this->runWorker();

    $reviews = $this->reviewsInWs(fn () => Review::query()->orderBy('created_at')->get());
    expect($reviews)->toHaveCount(2);
    $native = $reviews->firstWhere('external_review_id', '600');
    expect($native->source)->toBe('woocommerce')->and($native->status)->toBe('published')->and($native->rating)->toBe(3)
        ->and($native->body)->toBeNull()->and($native->created_at->toIso8601String())->toBe('2026-10-01T15:00:00+00:00');

    // La tienda la manda a la papelera: queda rechazada aquí también.
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'review.submitted', ['product_id' => '91', 'rating' => 3, 'review_id' => '600', 'status' => 'trash']),
    ]);
    $this->runWorker();
    expect($native->fresh()->status)->toBe('rejected');
});

it('rechaza con un motivo de contenido, revierte los puntos pendientes y la retira de la tienda si ya estaba publicada', function () {
    $ledger = $this->reviewsLedgerSpy();
    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/reject', ['reason' => 'low_rating'])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertOk();
    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/reject', ['reason' => 'off_topic'])->assertOk()
        ->assertJsonPath('status', 'rejected')->assertJsonPath('reject_reason', 'off_topic');

    expect(reviewsSiteCommands($this, 'review.unpublish'))->toBe([['command' => 'review.unpublish', 'args' => ['comment_id' => '551']]])
        ->and($ledger->settled)->toBe([['ref' => $this->review->publicId(), 'confirm' => true], ['ref' => $this->review->publicId(), 'confirm' => false]]);
    $this->get('/r/m/'.$this->review->id.'/'.$this->review->media[0]['key'])->assertNotFound();
});

it('responde como dueño: queda en el hub y se publica como comentario hijo en WooCommerce', function () {
    $this->actingAs($this->owner)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertOk();
    $this->actingAs($this->owner)->putJson($this->api.'/'.$this->review->publicId().'/reply', ['text' => '¡Gracias, Ana! Nos alegra que te sirviera.'])->assertOk()
        ->assertJsonPath('reply', '¡Gracias, Ana! Nos alegra que te sirviera.');
    expect(reviewsSiteCommands($this, 'review.reply'))->toBe([['command' => 'review.reply', 'args' => ['comment_id' => '551', 'body' => '¡Gracias, Ana! Nos alegra que te sirviera.', 'author_name' => 'Pinturas La 14']]])
        ->and($this->review->fresh()->external_reply_id)->toBe('552');
});

it('aprueba en lote y resume la cola, las calificaciones y las solicitudes', function () {
    $this->actingAs($this->owner)->postJson($this->api.'/bulk', ['action' => 'approve', 'ids' => [$this->review->publicId()]])->assertOk()->assertJsonPath('updated', 1);
    $this->actingAs($this->owner)->getJson($this->api.'/summary')->assertOk()
        ->assertJsonPath('reviews.published', 1)->assertJsonPath('reviews.pending', 0)->assertJsonPath('reviews.avg_rating', 5)
        ->assertJsonPath('reviews.with_media', 1)->assertJsonPath('reviews.distribution.5', 1)
        ->assertJsonPath('requests.completed', 1)->assertJsonPath('requests.completion_rate', 1);
    $this->actingAs($this->owner)->getJson($this->api.'/requests')->assertOk()->assertJsonPath('data.0.status', 'completed')->assertJsonPath('data.0.order.number', '1234');
});

it('permisos: viewer ve la lista (con el texto con datos personales enmascarado) pero no modera; agent no entra', function () {
    $this->reviewsInWs(fn () => $this->review->forceFill(['body' => 'Llámame al 300 123 4567', 'ai_flags' => ['flags' => ['personal_data'], 'details' => ['personal_data' => ['phone']], 'engine' => 'heuristic']])->save());
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);

    $body = $this->actingAs($viewer)->getJson($this->api)->assertOk()->json('data.0.body');
    expect($body)->not->toContain('300 123 4567');
    $this->actingAs($viewer)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertForbidden();
    $this->actingAs($viewer)->putJson($this->api.'/settings', [])->assertForbidden();
    $this->actingAs($agent)->getJson($this->api)->assertForbidden();
    expect($this->actingAs($marketing)->getJson($this->api)->assertOk()->json('data.0.body'))->toBe('Llámame al 300 123 4567');
    $this->actingAs($marketing)->postJson($this->api.'/'.$this->review->publicId().'/approve')->assertOk();
});

it('no deja ver ni moderar reseñas de otro espacio de trabajo', function () {
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra tienda');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/reviews")->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/reviews/{$this->review->publicId()}")->assertNotFound();
    $this->actingAs($other)->postJson("/api/w/{$otherWs->publicId()}/reviews/{$this->review->publicId()}/approve")->assertNotFound();
    $this->actingAs($other)->getJson("/api/w/{$this->ws->publicId()}/reviews")->assertNotFound();
    expect($this->review->fresh()->status)->toBe('pending');
});
