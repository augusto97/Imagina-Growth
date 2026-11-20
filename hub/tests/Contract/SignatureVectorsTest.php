<?php

use App\Support\Hmac\RequestSignature;

/*
 * Contrato de la firma (docs/api-connector.md §2) con los vectores compartidos con el plugin
 * (tests/fixtures/signature/vectors.json, generados con una implementación independiente). El plugin corre los mismos
 * en connector/tests/Unit/SignatureVectorsTest.php: si un lado cambia, alguno de los dos falla.
 */

/** @return array<string, mixed> */
function signatureVectors(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/signature/vectors.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $case */
function vectorBody(array $case): string
{
    return isset($case['body_base64']) ? (string) base64_decode((string) $case['body_base64'], true) : (string) $case['body'];
}

it('usa la misma etiqueta que el plugin', function () {
    expect(RequestSignature::LABEL)->toBe(signatureVectors()['label']);
});

it('canoniza la ruta y la consulta igual que los vectores', function () {
    foreach (signatureVectors()['canonical'] as $case) {
        expect(RequestSignature::target($case['target']))->toBe([$case['path'], $case['query']], $case['target']);
    }
});

it('firma y verifica cada vector v2', function () {
    foreach (signatureVectors()['v2'] as $case) {
        $body = vectorBody($case);
        expect(hash('sha256', $body))->toBe($case['body_sha256'], $case['name'])
            ->and(RequestSignature::stringToSign($case['method'], $case['url'], $case['t'], $case['nonce'], $body))->toBe($case['string_to_sign'], $case['name'])
            ->and(RequestSignature::signV2($case['secret'], $case['method'], $case['url'], $body, $case['t'], $case['nonce']))->toBe($case['header'], $case['name']);

        $verified = RequestSignature::verify($case['header'], $case['method'], $case['url'], $body, [$case['secret']], false, 300, $case['t']);
        expect($verified?->version)->toBe(2, $case['name'])
            ->and($verified?->nonce)->toBe($case['nonce']);
    }
});

it('una firma v2 de un vector no sirve para la ruta, el método o el cuerpo de otro', function () {
    $cases = signatureVectors()['v2'];
    foreach ($cases as $signed) {
        foreach ($cases as $other) {
            $sameRequest = $other['method'] === $signed['method'] && RequestSignature::target($other['url']) === RequestSignature::target($signed['url'])
                && vectorBody($other) === vectorBody($signed);
            $verified = RequestSignature::verify($signed['header'], $other['method'], $other['url'], vectorBody($other), [$signed['secret']], true, 300, $signed['t']);
            expect($verified !== null)->toBe($sameRequest, $signed['name'].' → '.$other['name']);
        }
    }
});

it('verifica los vectores v1 solo mientras se acepte la v1', function () {
    foreach (signatureVectors()['v1'] as $case) {
        $body = vectorBody($case);
        expect(RequestSignature::sign(1, $case['secret'], 'POST', '/v1/s', $body, $case['t']))->toBe($case['header'])
            ->and(RequestSignature::verify($case['header'], 'POST', '/v1/s', $body, [$case['secret']], true, 300, $case['t'])?->version)->toBe(1)
            // v1 no cubre la ruta: por eso se apaga cuando todos los plugins firman con v2.
            ->and(RequestSignature::verify($case['header'], 'GET', '/v1/s/config', $body, [$case['secret']], true, 300, $case['t'])?->version)->toBe(1)
            ->and(RequestSignature::verify($case['header'], 'POST', '/v1/s', $body, [$case['secret']], false, 300, $case['t']))->toBeNull();
    }
});
