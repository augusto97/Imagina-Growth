<?php

use App\Support\Hmac\RequestSignature;
use App\Support\Hmac\Signature;

/*
 * Firma v2 entre el plugin y el hub (docs/api-connector.md §2, S14): método, ruta, consulta canónica, hora, nonce y
 * hash del cuerpo. v1 sigue valiendo para los plugins anteriores a la 0.1.4 mientras se acepte.
 */

beforeEach(function () {
    $this->secret = 'sk_Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE';
});

it('arma la cabecera t, n y v2 sobre la cadena canónica', function () {
    $header = RequestSignature::signV2($this->secret, 'get', 'https://growth.imagina.cloud/v1/s/catalog/hashes?type=product&cursor=&limit=1000', '', 1000, 'abcdefghijklmnop');
    $message = "IGR-HMAC-SHA256-V2\nGET\n/v1/s/catalog/hashes\ncursor=&limit=1000&type=product\n1000\nabcdefghijklmnop\n".hash('sha256', '');

    expect($header)->toBe('t=1000,n=abcdefghijklmnop,v2='.hash_hmac('sha256', $message, $this->secret))
        ->and(RequestSignature::verify($header, 'GET', '/v1/s/catalog/hashes?limit=1000&type=product&cursor=', '', [$this->secret], true, 300, 1000)?->version)->toBe(2);
});

it('una firma v2 no sirve para otra ruta, otro método, otra consulta u otro cuerpo', function () {
    $header = RequestSignature::signV2($this->secret, 'GET', '/v1/s/config', '', 1000, str_repeat('a', 32));
    $verify = fn (string $method, string $target, string $body = '') => RequestSignature::verify($header, $method, $target, $body, [$this->secret], true, 300, 1000);

    expect($verify('GET', '/v1/s/config'))->not->toBeNull()
        ->and($verify('GET', '/v1/s/catalog/hashes'))->toBeNull()
        ->and($verify('POST', '/v1/s/config'))->toBeNull()
        ->and($verify('GET', '/v1/s/config?plugin_version=0.1.4'))->toBeNull()
        ->and($verify('GET', '/v1/s/config', 'x'))->toBeNull()
        ->and($verify('GET', '/v1/s/config/'))->toBeNull();
});

it('el host, el esquema y el fragmento no cuentan; la codificación sí se normaliza', function () {
    $header = RequestSignature::signV2($this->secret, 'POST', 'https://tienda.co/index.php?rest_route=/imagina-growth/v1/commands', '{}', 1000, str_repeat('b', 32));

    expect(RequestSignature::verify($header, 'POST', '/index.php?rest_route=%2Fimagina-growth%2Fv1%2Fcommands', '{}', [$this->secret], true, 300, 1000))->not->toBeNull()
        ->and(RequestSignature::verify($header, 'POST', 'http://otro.co/index.php?rest_route=%2fimagina-growth%2fv1%2fcommands#x', '{}', [$this->secret], true, 300, 1000))->not->toBeNull()
        ->and(RequestSignature::verify($header, 'POST', '/wp-json/imagina-growth/v1/commands', '{}', [$this->secret], true, 300, 1000))->toBeNull();
});

it('ordena la consulta por bytes, no por valor numérico', function () {
    expect(RequestSignature::canonicalQuery('n=9&n=10&b&a=%41&a=1&+=x'))->toBe('%20=x&a=1&a=A&b=&n=10&n=9')
        ->and(RequestSignature::canonicalPath(''))->toBe('/')
        ->and(RequestSignature::canonicalPath('/a%2fb/c d'))->toBe('/a%2Fb/c%20d');
});

it('rechaza hora fuera de la ventana, secreto equivocado y nonce o firma mal formados', function () {
    $header = RequestSignature::signV2($this->secret, 'POST', '/v1/s', 'x', 1000, str_repeat('c', 32));
    $verify = fn (?string $h, ?array $secrets = null, int $now = 1000) => RequestSignature::verify($h, 'POST', '/v1/s', 'x', $secrets ?? [$this->secret], true, 300, $now);

    expect($verify($header, now: 1300))->not->toBeNull()
        ->and($verify($header, now: 1301))->toBeNull()
        ->and($verify($header, now: 699))->toBeNull()
        ->and($verify($header, ['sk_otro']))->toBeNull()
        ->and($verify($header, ['']))->toBeNull()
        ->and($verify($header, ['sk_nuevo', $this->secret]))->not->toBeNull()
        ->and($verify(str_replace(',n=cccccccccccccccccccccccccccccccc', ',n=corto', $header)))->toBeNull()
        ->and($verify(str_replace(',n=cccccccccccccccccccccccccccccccc', '', $header)))->toBeNull()
        ->and($verify(str_replace(',n=cccccccccccccccccccccccccccccccc', ',n=dddddddddddddddddddddddddddddddd', $header)))->toBeNull()
        ->and($verify('t=1000,n='.str_repeat('c', 32).',v2=zz'))->toBeNull()
        ->and($verify(null))->toBeNull()
        ->and($verify(''))->toBeNull();
});

it('con v2 en la cabecera no se cae a v1', function () {
    $v1 = Signature::sign($this->secret, 'x', 1000);
    $bad = 't=1000,n='.str_repeat('e', 32).',v2='.str_repeat('0', 64).','.substr($v1, strpos($v1, 'v1='));

    expect(RequestSignature::verify($v1, 'POST', '/v1/s', 'x', [$this->secret], true, 300, 1000)?->version)->toBe(1)
        ->and(RequestSignature::verify($bad, 'POST', '/v1/s', 'x', [$this->secret], true, 300, 1000))->toBeNull();
});

it('v1 solo vale mientras se acepte, y sign() elige la versión', function () {
    $v1 = RequestSignature::sign(1, $this->secret, 'POST', '/v1/s', 'x', 1000);
    $v2 = RequestSignature::sign(2, $this->secret, 'POST', '/v1/s', 'x', 1000, str_repeat('f', 32));

    expect($v1)->toBe(Signature::sign($this->secret, 'x', 1000))
        ->and($v2)->toStartWith('t=1000,n='.str_repeat('f', 32).',v2=')
        ->and(RequestSignature::verify($v1, 'POST', '/v1/s', 'x', [$this->secret], false, 300, 1000))->toBeNull()
        ->and(RequestSignature::verify($v2, 'POST', '/v1/s', 'x', [$this->secret], false, 300, 1000)?->version)->toBe(2);
});

it('genera nonces válidos y distintos', function () {
    $a = RequestSignature::newNonce();

    expect($a)->toMatch(RequestSignature::NONCE_PATTERN)
        ->and(RequestSignature::newNonce())->not->toBe($a);
});
