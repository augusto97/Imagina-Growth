<?php

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Reviews\Support\ReviewToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reviews\Support\ReviewsFixtures;

/*
 * M15: formulario público /r/{token} (firmado, sin sesión), fotos y video sin
 * metadatos, recompensa idempotente y la reseña de Google ofrecida a todos.
 */

uses(ReviewsFixtures::class);

beforeEach(function () {
    $this->reviewsSetUp();
    Storage::fake('local');
    Http::fake(['tienda.co/*' => Http::response(['ok' => true, 'data' => ['comment_id' => '551']])]);
    $this->request = $this->reviewsSentRequest();
});

/** JPEG real con EXIF (GPS y orientación) armado a mano: GD no escribe EXIF. */
function reviewsJpegWithGps(int $w = 2400, int $h = 1800): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 30, 90, 200));
    ob_start();
    imagejpeg($img, null, 70);
    $jpeg = (string) ob_get_clean();
    // APP1 Exif con un IFD0 (Orientation = 1) y un GPS IFD con latitud: «N» y 5°.
    $tiff = 'MM'.pack('n', 42).pack('N', 8);
    $ifd0 = pack('n', 2)
        .pack('nnNnn', 0x0112, 3, 1, 1, 0)          // Orientation
        .pack('nnNN', 0x8825, 4, 1, 8 + 2 + 24 + 4)  // GPS IFD pointer
        .pack('N', 0);
    $gps = pack('n', 2)
        .pack('nnN', 0x0001, 2, 2).'N'."\0\0\0"      // GPSLatitudeRef = "N"
        .pack('nnNN', 0x0002, 5, 3, 8 + 2 + 24 + 4 + 2 + 24 + 4)
        .pack('N', 0)
        .pack('NNNNNN', 5, 1, 4, 1, 3600, 100);      // 5° 4' 36"
    $exif = "Exif\0\0".$tiff.$ifd0.$gps;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** MP4 mínimo (ftyp + moov con udta/©xyz de ubicación + mdat). */
function reviewsMp4WithLocation(): string
{
    $box = fn (string $type, string $data) => pack('N', 8 + strlen($data)).$type.$data;
    $xyz = $box("\xA9xyz", pack('nn', 18, 0).'+04.6097-074.0817/');
    $udta = $box('udta', $xyz);
    $mvhd = $box('mvhd', str_repeat("\0", 100));
    $trak = $box('trak', $box('tkhd', str_repeat("\0", 84)).$box('udta', $box("\xA9nam", 'iPhone de Ana')));

    return $box('ftyp', 'isom'.pack('N', 512).'isomiso2mp41').$box('moov', $mvhd.$trak.$udta).$box('mdat', str_repeat("\x11", 2048));
}

it('muestra el formulario con un token válido y responde 404 si está alterado y 410 si venció', function () {
    $path = $this->reviewsFormPath($this->request);
    $this->get($path.'?nota=5')->assertOk()->assertSee('Pintura Azul 1 galón')->assertSee('Rodillo profesional')
        ->assertSee('La tienda en general')->assertSee('value="5" checked', false)->assertSee('Ana R.')
        ->assertHeader('X-Robots-Tag', 'noindex');

    $tampered = substr($path, 0, -1).(str_ends_with($path, 'a') ? 'b' : 'a');
    $this->get($tampered)->assertNotFound()->assertSee('No encontramos este enlace');

    // El token de la encuesta no sirve para el formulario de reseña (propósito distinto).
    $survey = ReviewToken::make(ReviewToken::SURVEY, ['w' => $this->ws->id, 'r' => $this->request->id, 'o' => $this->request->order_id, 'c' => $this->request->contact_id], now()->addDay());
    $this->get('/r/'.$survey)->assertNotFound();

    $this->reviewsInWs(fn () => $this->request->forceFill(['expires_at' => CarbonImmutable::now()->subDay()])->save());
    $this->get($this->reviewsFormPath($this->request))->assertStatus(410)->assertSee('Este enlace ya venció');
});

it('guarda la reseña pendiente de comprador verificado, completa la solicitud y emite review.collected sin el texto', function () {
    $response = $this->post($this->reviewsFormPath($this->request), [
        'product' => '88', 'rating' => 4, 'title' => 'Muy buena', 'body' => 'Cubre muy bien, la volvería a comprar.', 'author_name' => 'Ana R.',
    ]);
    $review = $this->reviewsInWs(fn () => Review::query()->firstOrFail());
    $response->assertStatus(303)->assertRedirect(ReviewToken::base().$this->reviewsFormPath($this->request).'/gracias?rv='.$review->publicId());

    expect($review->status)->toBe('pending')->and($review->verified_buyer)->toBeTrue()->and($review->source)->toBe('request')
        ->and($review->external_product_id)->toBe('88')->and($review->product_id)->not->toBeNull()
        ->and($review->contact_id)->toBe($this->request->contact_id)->and($review->order_id)->toBe($this->request->order_id)
        ->and($review->author_name)->toBe('Ana R.')->and($review->ai_flags['flags'])->toBe([]);
    $request = $this->reviewsInWs(fn () => ReviewRequest::query()->firstOrFail());
    expect($request->status)->toBe('completed')->and($request->completed_at)->not->toBeNull();

    $rows = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'review.collected'");
    expect($rows)->toHaveCount(1)->and($rows[0]['contact_id'])->toBe($review->contact_id);
    expect(json_decode($rows[0]['p'], true))->toMatchArray(['review_id' => $review->publicId(), 'product_id' => '88', 'order_id' => '1234', 'rating' => 4, 'has_media' => false, 'source' => 'request', 'status' => 'pending'])
        ->and($rows[0]['p'])->not->toContain('Cubre muy bien')->not->toContain('Muy buena');

    // Enviar otra vez el mismo producto no duplica; el producto ya no se ofrece como pendiente.
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 1, 'author_name' => 'Ana R.'])->assertStatus(303);
    expect($this->reviewsInWs(fn () => Review::query()->count()))->toBe(1);
    $this->get($this->reviewsFormPath($this->request))->assertSee('Ya la calificaste');
});

it('valida la calificación y que el producto sea del pedido', function () {
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'author_name' => 'Ana R.'])->assertStatus(422)->assertSee('Revisa los campos marcados');
    $this->post($this->reviewsFormPath($this->request), ['product' => '999', 'rating' => 5, 'author_name' => 'Ana R.'])->assertStatus(422)->assertSee('Ese producto no está en tu pedido.');
    expect($this->reviewsInWs(fn () => Review::query()->count()))->toBe(0);
});

it('ofrece la reseña de Google a todos, también con 1 estrella (sin filtrar reseñas)', function () {
    $this->reviewsEnable([], ['google_review_url' => 'https://g.page/r/pinturas-la-14/review']);
    foreach (['88' => 1, '91' => 5] as $product => $rating) {
        $location = $this->post($this->reviewsFormPath($this->request), ['product' => (string) $product, 'rating' => $rating, 'author_name' => 'Ana R.'])->headers->get('Location');
        $this->get((string) parse_url((string) $location, PHP_URL_PATH).'?'.parse_url((string) $location, PHP_URL_QUERY))
            ->assertOk()->assertSee('https://g.page/r/pinturas-la-14/review', false)->assertSee('Dejar una reseña en Google');
    }
});

it('reduce las fotos, les quita el EXIF y el GPS, crea la miniatura y borra la ubicación del video', function () {
    $photo = UploadedFile::fake()->createWithContent('foto.jpg', reviewsJpegWithGps());
    $raw = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($raw, reviewsJpegWithGps());
    expect(exif_read_data($raw))->toHaveKey('GPSLatitudeRef');   // la foto original sí trae GPS
    $video = UploadedFile::fake()->createWithContent('clip.mp4', reviewsMp4WithLocation());

    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'author_name' => 'Ana R.', 'media' => [$photo, $video]])->assertStatus(303);
    $review = $this->reviewsInWs(fn () => Review::query()->firstOrFail());
    expect($review->media)->toHaveCount(2)->and($review->media[0]['type'])->toBe('image')->and($review->media[1]['type'])->toBe('video');

    $dir = 'reviews/'.$this->ws->id.'/'.$review->id.'/';
    $stored = Storage::disk('local')->get($dir.$review->media[0]['key']);
    $path = tempnam(sys_get_temp_dir(), 'y');
    file_put_contents($path, $stored);
    $exif = @exif_read_data($path);
    expect(is_array($exif) ? array_keys($exif) : [])->not->toContain('GPSLatitudeRef')->not->toContain('Orientation');
    [$w, $h] = getimagesize($path);
    expect(max($w, $h))->toBe(1600);
    [$tw, $th] = getimagesize(Storage::disk('local')->path($dir.$review->media[0]['thumb_key']));
    expect([$tw, $th])->toBe([400, 400]);

    $mp4 = Storage::disk('local')->get($dir.$review->media[1]['key']);
    expect(strlen($mp4))->toBe(strlen(reviewsMp4WithLocation()))   // ningún byte se movió
        ->and($mp4)->not->toContain('+04.6097')->not->toContain('iPhone de Ana')->toContain('mdat');
});

it('rechaza archivos que no son fotos ni videos, demasiados archivos y fotos muy pesadas', function () {
    $fake = UploadedFile::fake()->createWithContent('virus.jpg', 'esto no es una imagen');
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'author_name' => 'Ana R.', 'media' => [$fake]])
        ->assertStatus(422)->assertSee('Solo se aceptan fotos');

    $many = array_map(fn ($i) => UploadedFile::fake()->createWithContent("f{$i}.jpg", reviewsJpegWithGps(40, 40)), range(1, 6));
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'author_name' => 'Ana R.', 'media' => $many])->assertStatus(422);

    config(['igr-reviews.uploads.image_max_kb' => 1]);
    $big = UploadedFile::fake()->createWithContent('grande.jpg', reviewsJpegWithGps(800, 800));
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'author_name' => 'Ana R.', 'media' => [$big]])
        ->assertStatus(422)->assertSee('La foto pesa más de');
    expect($this->reviewsInWs(fn () => Review::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles('reviews'))->toBe([]);
});

it('da los puntos pendientes al recibirla (más por la foto) y los confirma una sola vez al publicarla', function () {
    $ledger = $this->reviewsLedgerSpy(rules: ['review' => 50, 'review_media' => 150]);
    $this->get($this->reviewsFormPath($this->request))->assertSee('Te damos 50 puntos por tu reseña')->assertSee('150 más si subes una foto o video');
    $photo = UploadedFile::fake()->createWithContent('foto.jpg', reviewsJpegWithGps(300, 200));
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 5, 'author_name' => 'Ana R.', 'media' => [$photo]])->assertStatus(303);
    $review = $this->reviewsInWs(fn () => Review::query()->firstOrFail());

    expect($ledger->earned)->toBe([
        'review:'.$review->id => ['points' => 50, 'reason' => 'review', 'pending' => true, 'ref' => $review->publicId()],
        'review_media:'.$review->id => ['points' => 150, 'reason' => 'review_media', 'pending' => true, 'ref' => $review->publicId()],
    ]);
    $thanks = $this->get($this->reviewsFormPath($this->request).'/gracias?rv='.$review->publicId());
    $thanks->assertOk()->assertSee('Tienes 200 puntos en camino');

    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/reviews/{$review->publicId()}/approve")->assertOk();
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/reviews/{$review->publicId()}/approve")->assertOk();
    expect($ledger->earned)->toHaveCount(2)->and($ledger->settled)->toBe([['ref' => $review->publicId(), 'confirm' => true]]);
});

it('con moderación automática publica lo que no tiene banderas y deja en la cola lo que trae datos personales', function () {
    $this->reviewsEnable([], ['moderation' => ['mode' => 'auto']]);
    $this->post($this->reviewsFormPath($this->request), ['product' => '88', 'rating' => 2, 'body' => 'No me cubrió bien la pared.', 'author_name' => 'Ana R.'])->assertStatus(303);
    $this->post($this->reviewsFormPath($this->request), ['product' => '91', 'rating' => 5, 'body' => 'Escríbanme al 300 123 4567 o a ana@correo.com', 'author_name' => 'Ana R.'])->assertStatus(303);
    $reviews = $this->reviewsInWs(fn () => Review::query()->orderBy('external_product_id')->get()->keyBy('external_product_id'));

    // Una de 2 estrellas se publica igual que una de 5: la moderación es por contenido, no por la nota.
    expect($reviews['88']->status)->toBe('published')->and($reviews['88']->published_at)->not->toBeNull()
        ->and($reviews['91']->status)->toBe('pending')->and($reviews['91']->ai_flags['flags'])->toBe(['personal_data'])
        ->and($reviews['91']->ai_flags['details']['personal_data'])->toContain('email');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'tienda.co') && $r['command'] === 'review.publish' && $r['args']['review_id'] === $reviews['88']->publicId());
    expect(ReviewSettings::for($this->ws)['moderation']['mode'])->toBe('auto');
});
