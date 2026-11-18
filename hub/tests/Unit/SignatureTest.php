<?php

use App\Support\Hmac\Signature;

it('firma y verifica t + "." + cuerpo como dice api-connector.md §2', function () {
    $header = Signature::sign('sk_secreto', 'cuerpo', 1763420700);
    expect($header)->toBe('t=1763420700,v1='.hash_hmac('sha256', '1763420700.cuerpo', 'sk_secreto'))
        ->and(Signature::verify($header, 'cuerpo', ['sk_secreto'], 300, 1763420700))->toBeTrue();
});

it('rechaza firmas fuera de la ventana de ±300 s', function () {
    $header = Signature::sign('sk_s', 'x', 1000);
    expect(Signature::verify($header, 'x', ['sk_s'], 300, 1301))->toBeFalse()
        ->and(Signature::verify($header, 'x', ['sk_s'], 300, 699))->toBeFalse()
        ->and(Signature::verify($header, 'x', ['sk_s'], 300, 1300))->toBeTrue();
});

it('rechaza cuerpo alterado, secreto equivocado y cabeceras mal formadas', function () {
    $header = Signature::sign('sk_s', 'x', 1000);
    expect(Signature::verify($header, 'y', ['sk_s'], 300, 1000))->toBeFalse()
        ->and(Signature::verify($header, 'x', ['sk_otro'], 300, 1000))->toBeFalse()
        ->and(Signature::verify('t=abc,v1=zz', 'x', ['sk_s'], 300, 1000))->toBeFalse()
        ->and(Signature::verify(null, 'x', ['sk_s']))->toBeFalse()
        ->and(Signature::verify($header, 'x', [''], 300, 1000))->toBeFalse();
});

it('acepta el secreto anterior durante la rotación', function () {
    $header = Signature::sign('sk_viejo', 'x', 1000);
    expect(Signature::verify($header, 'x', ['sk_nuevo', 'sk_viejo'], 300, 1000))->toBeTrue();
});
