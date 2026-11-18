<?php

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\PublicReviewCache;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reviews\Support\ReviewsFixtures;

/*
 * M15: importación (CSV propio y Judge.me) sin duplicar, proyección de las
 * reseñas nativas importadas y los endpoints públicos de los widgets
 * (api-connector.md §8.3) sin datos personales.
 */

uses(ReviewsFixtures::class);

beforeEach(function () {
    $this->reviewsSetUp();
    Storage::fake('local');
    $this->order = $this->reviewsOrder();   // Ana compró la pintura (88)
    $this->api = "/api/w/{$this->ws->publicId()}/reviews";
});

function reviewsCsv(array $rows): UploadedFile
{
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    rewind($fh);

    return UploadedFile::fake()->createWithContent('resenas.csv', (string) stream_get_contents($fh));
}

it('importa un CSV propio: une por correo y marca comprador verificado, y no duplica al reimportar', function () {
    $csv = [
        ['producto', 'calificacion', 'titulo', 'comentario', 'nombre', 'correo', 'fecha', 'fotos'],
        ['88', '5', 'La mejor', 'Rinde muchísimo.', 'Ana María Ruiz', 'ANA@ejemplo.com', '2026-09-01 10:00', 'https://cdn.tienda.co/a.jpg'],
        ['ROD-01', '4', '', 'Buen rodillo', 'Pedro', 'pedro@ejemplo.com', '2026-09-02', ''],
        ['', '3', '', 'Atención correcta', 'Luz Elena', '', '2026-09-03', ''],
        ['999', '5', '', 'No existe', 'X', '', '', ''],
        ['88', '9', '', 'Nota inválida', 'Y', '', '', ''],
    ];
    $first = $this->actingAs($this->owner)->post($this->api.'/imports', ['file' => reviewsCsv($csv), 'format' => 'csv'], ['Accept' => 'application/json'])
        ->assertOk()->json();
    expect($first['errors'])->toHaveCount(2, json_encode($first['errors'], JSON_UNESCAPED_UNICODE))->and($first['created'])->toBe(3, json_encode($first['errors']));
    expect(array_column($first['errors'], 'row'))->toBe([5, 6]);

    $reviews = $this->reviewsInWs(fn () => Review::query()->orderBy('created_at')->get());
    expect($reviews)->toHaveCount(3);
    [$ana, $pedro, $store] = $reviews->all();
    expect($ana->contact_id)->toBe($this->order->contact_id)->and($ana->verified_buyer)->toBeTrue()->and($ana->author_name)->toBe('Ana R.')
        ->and($ana->source)->toBe('import')->and($ana->status)->toBe('published')->and($ana->media)->toBe([['url' => 'https://cdn.tienda.co/a.jpg', 'type' => 'image']])
        ->and($pedro->external_product_id)->toBe('91')->and($pedro->verified_buyer)->toBeFalse()
        ->and($store->external_product_id)->toBeNull();

    $this->actingAs($this->owner)->post($this->api.'/imports', ['file' => reviewsCsv($csv), 'format' => 'csv'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('created', 0)->assertJsonPath('duplicates', 3);
});

it('importa una exportación de Judge.me con su estado de moderación', function () {
    $csv = [
        ['title', 'body', 'rating', 'review_date', 'source', 'curated', 'reviewer_name', 'reviewer_email', 'product_id', 'product_handle', 'reply', 'reply_date', 'picture_urls', 'ip_address', 'location'],
        ['Bonita', 'Me encantó el color', '5', '2025-12-01 10:00:00 UTC', 'web', 'ok', 'Carla Gómez', 'carla@ejemplo.com', '', 'pintura-azul', 'Gracias Carla', '2025-12-02', '', '1.2.3.4', 'Cali'],
        ['Spam', 'Gana dinero rápido en www.casino.co', '5', '2025-12-03', 'web', 'spam', 'Bot', '', '88', '', '', '', '', '', ''],
        ['Ok', 'Normal', '3', '2025-12-04', 'web', 'not-yet', 'Juan', '', '91', '', '', '', '', '', ''],
    ];
    $this->actingAs($this->owner)->post($this->api.'/imports', ['file' => reviewsCsv($csv), 'format' => 'judgeme'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('created', 3);
    $reviews = $this->reviewsInWs(fn () => Review::query()->orderBy('created_at')->get()->values());
    expect($reviews->pluck('status')->all())->toBe(['published', 'rejected', 'pending'])
        ->and($reviews[0]->external_product_id)->toBe('88')->and($reviews[0]->owner_reply)->toBe('Gracias Carla')
        ->and($reviews[1]->ai_flags['flags'])->toContain('spam');
});

it('la importación es solo para marketing o administradores', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->post($this->api.'/imports', ['file' => reviewsCsv([['rating'], ['5']]), 'format' => 'csv'], ['Accept' => 'application/json'])->assertForbidden();
});

it('proyecta las reseñas nativas que llegaron con la importación histórica', function () {
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'review.submitted', ['product_id' => '88', 'rating' => 4, 'verified_owner' => true, 'review_id' => '700', 'status' => 'approved'], ['source' => ['type' => 'import']]),
    ]);
    $this->runWorker();
    expect($this->reviewsInWs(fn () => Review::query()->count()))->toBe(0);   // la importación no publica EventIngested

    $this->artisan('igr:reviews:project-store', ['--workspace' => [$this->ws->publicId()]])->assertSuccessful();
    $this->artisan('igr:reviews:project-store', ['--workspace' => [$this->ws->publicId()]])->assertSuccessful();
    $review = $this->reviewsInWs(fn () => Review::query()->sole());
    expect($review->source)->toBe('woocommerce')->and($review->external_review_id)->toBe('700')->and($review->rating)->toBe(4)->and($review->verified_buyer)->toBeTrue();
});

it('los endpoints públicos dan solo las publicadas, con el autor abreviado y sin datos del contacto', function () {
    $mk = fn (array $a) => $this->reviewsInWs(fn () => Review::query()->create($a + [
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'contact_id' => $this->order->contact_id, 'source' => 'request', 'verified_buyer' => true,
    ]));
    $mk(['external_product_id' => '88', 'rating' => 5, 'title' => 'Top', 'body' => 'Excelente', 'author_name' => 'Ana R.', 'status' => 'published', 'published_at' => now()->subDay(), 'owner_reply' => '¡Gracias!']);
    $mk(['external_product_id' => '88', 'rating' => 3, 'body' => 'Regular', 'author_name' => 'Luis P.', 'status' => 'published', 'published_at' => now()]);
    $mk(['external_product_id' => '88', 'rating' => 1, 'body' => 'Pendiente de moderar', 'author_name' => 'X', 'status' => 'pending']);
    $mk(['external_product_id' => '88', 'rating' => 4, 'body' => null, 'author_name' => null, 'status' => 'published', 'published_at' => now(), 'source' => 'woocommerce', 'external_review_id' => '901']);
    $mk(['external_product_id' => null, 'rating' => 5, 'body' => 'Buena atención', 'author_name' => 'Ana R.', 'status' => 'published', 'published_at' => now()]);
    $key = $this->site->public_key;

    $res = $this->getJson("/p/{$key}/reviews?product_id=88&per_page=1", ['Origin' => 'https://tienda.co'])->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://tienda.co')->assertHeader('Cache-Control', 'max-age=300, public');
    expect($res->json('summary'))->toEqual(['avg' => 4.0, 'count' => 3, 'distribution' => ['5' => 1, '4' => 1, '3' => 1, '2' => 0, '1' => 0]])
        ->and($res->json('reviews'))->toHaveCount(1)->and($res->json('reviews.0.author'))->toBe('Luis P.')->and($res->json('next_page'))->toBe(2);
    $page2 = $this->getJson("/p/{$key}/reviews?product_id=88&per_page=1&page=2")->json();
    expect($page2['reviews'][0])->toMatchArray(['rating' => 5, 'title' => 'Top', 'body' => 'Excelente', 'author' => 'Ana R.', 'verified' => true, 'reply' => '¡Gracias!'])
        ->and($page2['next_page'])->toBeNull();   // la nativa sin texto cuenta en el promedio pero no en la lista
    $json = json_encode($page2).json_encode($res->json());
    expect($json)->not->toContain('ana@ejemplo.com')->not->toContain('Ruiz')->not->toContain('contact')->not->toContain('Pendiente de moderar');

    $this->getJson("/p/{$key}/reviews")->assertOk()->assertJsonPath('summary.count', 1)->assertJsonPath('reviews.0.body', 'Buena atención');
    $this->getJson("/p/{$key}/reviews?scope=all")->assertOk()->assertJsonPath('summary.count', 4);
    $this->getJson("/p/{$key}/reviews/badges?product_ids=88,91,x y")->assertOk()->assertExactJson(['88' => ['avg' => 4.0, 'count' => 3]]);
    $this->getJson('/p/pk_'.str_repeat('0', 32).'/reviews')->assertNotFound();
});

it('la caché pública se renueva al moderar', function () {
    $review = $this->reviewsInWs(fn () => Review::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_product_id' => '88', 'rating' => 5, 'body' => 'Muy buena', 'author_name' => 'Ana R.', 'status' => 'pending', 'source' => 'request',
    ]));
    $key = $this->site->public_key;
    $this->getJson("/p/{$key}/reviews/badges?product_ids=88")->assertExactJson([]);
    config(['igr-reviews.public_url' => 'https://growth.test']);
    Http::fake(['tienda.co/*' => Http::response(['ok' => true, 'data' => ['comment_id' => '1']])]);
    $this->actingAs($this->owner)->postJson($this->api.'/'.$review->publicId().'/approve')->assertOk();
    $this->getJson("/p/{$key}/reviews/badges?product_ids=88")->assertExactJson(['88' => ['avg' => 5.0, 'count' => 1]]);
    expect(PublicReviewCache::version($this->site->id))->not->toBe('0');
});
