<?php

use App\Domain\Sites\Support\SiteKeys;

it('genera claves con los formatos de api-connector.md §1', function () {
    expect(SiteKeys::publicKey())->toMatch('/^pk_[0-9a-z]{32}$/')
        ->and(SiteKeys::secret())->toMatch('/^sk_[A-Za-z0-9_-]{43}$/')
        ->and(SiteKeys::connectCode())->toMatch('/^IGR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
});

it('normaliza el código que pega la persona', function () {
    $code = SiteKeys::connectCode();
    $sloppy = strtolower(str_replace('-', ' ', $code));
    expect(SiteKeys::normalizeCode($sloppy))->toBe($code)
        ->and(SiteKeys::normalizeCode(substr($code, 4)))->toBe($code)
        ->and(SiteKeys::normalizeCode('IGR-1234'))->toBeNull();
});

it('compara hosts sin www ni puerto', function () {
    expect(SiteKeys::host('https://www.Tienda.co:8443/x'))->toBe('tienda.co')
        ->and(SiteKeys::host('nada'))->toBeNull();
});
