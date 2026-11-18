<?php

use App\Domain\Reviews\Actions\ImportReviews;
use App\Domain\Reviews\Actions\SubmitReview;
use App\Domain\Reviews\Support\HeuristicReviewClassifier;
use App\Domain\Reviews\Support\Mp4Metadata;

/*
 * M15: filtro de moderación por reglas (sin marcar quejas duras como ofensivas),
 * nombre publicado y limpieza de metadatos de video.
 */

it('marca lenguaje ofensivo, spam y datos personales, y deja pasar una queja dura', function (string $text, array $flags) {
    expect((new HeuristicReviewClassifier)->classify(null, $text)['flags'])->toBe($flags);
})->with([
    'queja dura' => ['Pésimo servicio, una estafa. Nunca más compro aquí.', []],
    'grosería' => ['Qué malparido producto, una mierda', ['offensive']],
    'grosería mx' => ['Está bien pinche caro, no manches', ['offensive']],
    'enlace' => ['Compra más barato en www.otratienda.co', ['spam']],
    'mayúsculas' => ['NO ME LLEGÓ NADA Y NADIE RESPONDE EL TELÉFONO', ['spam']],
    'teléfono' => ['Escríbanme al 300 123 4567 para el cambio', ['personal_data']],
    'correo' => ['mi correo es ana.ruiz@gmail.com', ['personal_data']],
    'dirección' => ['Me lo dejaron en la Calle 45 # 12-30 sin avisar', ['personal_data']],
    'marca HP' => ['La impresora HP imprime perfecto', []],
]);

it('detalla qué dato personal encontró sin copiar el dato', function () {
    $out = (new HeuristicReviewClassifier)->classify('Mi cédula', 'CC 1053456789, llámenme');
    expect($out['details'])->toBe(['personal_data' => ['document']])->and(json_encode($out))->not->toContain('1053456789');
});

it('publica el nombre como «Nombre I.»', function () {
    expect(ImportReviews::shortName('ana maría RUIZ gómez'))->toBe('Ana G.')
        ->and(ImportReviews::shortName('Pedro'))->toBe('Pedro')
        ->and(SubmitReview::defaultAuthor(null))->toBe('Cliente verificado');
});

it('rechaza un archivo que no es MP4 y no toca los bytes del video al limpiar', function () {
    $path = tempnam(sys_get_temp_dir(), 'v');
    file_put_contents($path, 'no soy un video');
    expect(Mp4Metadata::strip($path))->toBeFalse();
    $box = fn (string $type, string $data) => pack('N', 8 + strlen($data)).$type.$data;
    $video = $box('ftyp', 'qt  '.pack('N', 0)).$box('mdat', str_repeat('M', 64)).$box('moov', $box('mvhd', str_repeat("\0", 20)).$box('meta', 'com.apple.quicktime.location.ISO6709+04.6097'));
    file_put_contents($path, $video);
    expect(Mp4Metadata::strip($path))->toBeTrue();
    $out = (string) file_get_contents($path);
    expect(strlen($out))->toBe(strlen($video))->and($out)->not->toContain('ISO6709')->toContain(str_repeat('M', 64))->toContain('free');
});
