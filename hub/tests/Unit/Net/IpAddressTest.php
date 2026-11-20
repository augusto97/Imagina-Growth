<?php

use App\Support\Net\IpAddress;

/*
 * S5 (SSRF): qué IP se consideran públicas. Casos negativos de IPv4 e IPv6, incluidas las IPv6 que llevan una
 * IPv4 interna adentro.
 */

it('acepta IPv4 e IPv6 públicas', function (string $ip) {
    expect(IpAddress::isPublic($ip))->toBeTrue();
})->with([
    '8.8.8.8', '190.85.12.34', '1.1.1.1', '2606:4700:4700::1111', '2800:3f0:4005:800::200e', '[2001:4860:4860::8888]',
    '::ffff:8.8.8.8',            // mapeada a una IPv4 pública
    '64:ff9b::808:808',          // NAT64 de 8.8.8.8
]);

it('rechaza IPv4 privadas, locales, link-local, CGNAT y reservadas', function (string $ip) {
    expect(IpAddress::isPublic($ip))->toBeFalse();
})->with([
    '127.0.0.1', '127.255.255.254', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.10', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '192.0.2.10', '198.18.0.1', '203.0.113.7', '224.0.0.1', '240.0.0.1', '255.255.255.255',
]);

it('rechaza IPv6 locales, privadas, link-local y las que esconden una IPv4 interna', function (string $ip) {
    expect(IpAddress::isPublic($ip))->toBeFalse();
})->with([
    '::1', '::', '[::1]', 'fe80::1', 'fe80::1ff:fe23:4567:890a', 'fc00::1', 'fd12:3456:789a::1', 'fec0::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1', '::127.0.0.1',
    '64:ff9b::7f00:1', '64:ff9b::a00:1', '2002:7f00:1::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:db8::1', '100::1',
]);

it('rechaza lo que no es una IP', function (string $value) {
    expect(IpAddress::isPublic($value))->toBeFalse()->and(IpAddress::isIp($value))->toBeFalse();
})->with(['', 'localhost', 'example.com', '2130706433', '0x7f.0.0.1', '1.2.3', '::ffff:999.1.1.1']);
