<?php

it('autoriza TLS solo para dominios de seguimiento de sitios conectados y desde la red interna', function () {
    $ws = $this->workspaceFor($this->userWith2fa());
    [$site] = $this->connectedSite($ws);
    $site->forceFill(['tracking_domain' => 't.tienda.co'])->save();
    $internal = ['REMOTE_ADDR' => '172.18.0.5'];

    $this->call('GET', '/internal/caddy/ask?domain=t.tienda.co', [], [], [], $internal)->assertOk();
    $this->call('GET', '/internal/caddy/ask?domain=otro.co', [], [], [], $internal)->assertNotFound();
    $this->call('GET', '/internal/caddy/ask?domain=t.tienda.co', [], [], [], ['REMOTE_ADDR' => '190.24.1.10'])->assertForbidden();
    $this->call('GET', '/internal/caddy/ask?domain=t.tienda.co', [], [], [], $internal + ['HTTP_X_FORWARDED_FOR' => '190.24.1.10'])->assertForbidden();

    $site->forceFill(['status' => 'disconnected'])->save();
    $this->call('GET', '/internal/caddy/ask?domain=t.tienda.co', [], [], [], $internal)->assertNotFound();
});
