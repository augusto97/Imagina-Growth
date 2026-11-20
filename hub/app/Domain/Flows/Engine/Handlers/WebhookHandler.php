<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Support\FlowSecrets;
use App\Support\Hmac\Signature;
use App\Support\Net\SafeHttp;
use App\Support\Net\UnsafeUrl;
use Illuminate\Http\Client\ConnectionException;

/**
 * Webhook saliente firmado (`X-IGR-Signature: t=…,v1=…`, como api-connector.md
 * §2) con los datos del run, sin datos personales del contacto. `X-IGR-Delivery`
 * es la clave de idempotencia: el receptor puede descartar repetidos.
 */
final class WebhookHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout() || $state->isTest()) {
            return Outcome::next(record: $state->isHoldout() ? 'skipped_holdout' : 'continue', details: ['skipped' => true]);
        }
        $body = (string) json_encode([
            'flow_id' => $state->flow->publicId(),
            'run_id' => $state->run->publicId(),
            'node_id' => $node['id'],
            'contact_id' => $state->contact?->publicId(),
            'trigger' => $state->run->context['trigger'] ?? null,
            'data' => $state->run->context['props'] ?? (object) [],
            'sent_at' => $state->now->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url = (string) ($node['config']['url'] ?? '');
        try {
            // Solo HTTPS públicas, con el DNS resuelto al enviar y sin redirecciones (S5).
            $request = SafeHttp::for($url);
        } catch (UnsafeUrl $e) {
            return Outcome::next('failed', 'failed', ['reason' => $e->reason], fallback: true);
        }
        try {
            $response = $request->timeout(5)->withHeaders([
                'Content-Type' => 'application/json',
                'X-IGR-Signature' => Signature::sign(FlowSecrets::webhookSecret($state->flow), $body),
                'X-IGR-Delivery' => $state->idempotencyKey($node['id']),
            ])->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $e) {
            return Outcome::next('failed', 'failed', ['reason' => 'connection', 'error' => mb_substr($e->getMessage(), 0, 200)], fallback: true);
        }

        return $response->successful()
            ? Outcome::next(details: ['status' => $response->status()])
            : Outcome::next('failed', 'failed', ['reason' => 'http', 'status' => $response->status()], fallback: true);
    }
}
