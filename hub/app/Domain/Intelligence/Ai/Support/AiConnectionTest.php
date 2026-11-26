<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PaymentRequiredException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\TextBlock;
use Psr\Http\Client\ClientInterface;

/**
 * «Probar la IA» (Plataforma → Ajustes → IA): una pregunta corta al modelo elegido con la clave guardada, en el
 * momento y con 30 s de tiempo máximo, y en palabras qué pasó: respondió (con la latencia, el modelo que respondió y
 * lo que costó), o la clave no sirve, no hay saldo, el modelo no está disponible para esa cuenta, demasiadas
 * peticiones, el proveedor está saturado o no hay red. Nunca devuelve la clave, y no se cobra a ningún espacio de
 * trabajo (es de la plataforma).
 */
final class AiConnectionTest
{
    public const TIMEOUT = 30;

    /**
     * @return array{ok: bool, reason?: string, message: string, model: string, effort: string|null, latency_ms?: int, served_model?: string, reply?: string, cost_usd?: float, detail?: string}
     */
    public function run(?string $model = null, ?string $effort = null): array
    {
        $main = AiModels::resolve('assistant');
        $model = is_string($model) && $model !== '' ? $model : $main['model'];
        $effort = is_string($effort) && $effort !== '' ? $effort : ($model === $main['model'] ? $main['effort'] : null);
        $base = ['model' => $model, 'effort' => $effort];
        $key = (string) config('igr-ai.api_key');
        if ($key === '') {
            return $base + ['ok' => false, 'reason' => 'no_key', 'message' => self::text(__('Falta la clave de Anthropic: pégala en «Clave de Anthropic» y guarda antes de probar.'))];
        }
        if ($effort !== null && ! AiModels::supportsEffort($model, $effort)) {
            return $base + ['ok' => false, 'reason' => 'effort', 'message' => self::text(__('El modelo :model no admite el esfuerzo «:effort».', ['model' => $model, 'effort' => $effort]))];
        }

        $params = [
            'model' => $model,
            // Los modelos actuales piensan antes de responder: el tope deja espacio para eso y para la palabra.
            'maxTokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Responde solo con la palabra «listo».']],
        ];
        if ($effort !== null) {
            $params['outputConfig'] = ['effort' => $effort];
        }
        $started = hrtime(true);
        try {
            // @phpstan-ignore argument.type (mensajes en forma de arreglo, como documenta el SDK)
            $message = $this->client($key)->messages->create(...$params);
        } catch (APIException $e) {
            [$reason, $message] = self::explain($e, $model);

            return $base + ['ok' => false, 'reason' => $reason, 'message' => $message, 'detail' => self::clean($e->getMessage(), $key)];
        }
        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $reply = '';
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $reply .= $block->text;
            }
        }
        $u = $message->usage;
        $cost = AiMeter::cost($model, (int) $u->inputTokens, (int) $u->outputTokens, (int) ($u->cacheReadInputTokens ?? 0), (int) ($u->cacheCreationInputTokens ?? 0));
        if ($message->stopReason === 'refusal') {
            return $base + ['ok' => false, 'reason' => 'refusal', 'latency_ms' => $latency, 'served_model' => (string) $message->model,
                'message' => self::text(__('El modelo respondió, pero rechazó la prueba por sus reglas de seguridad. La clave y el modelo funcionan.'))];
        }

        return $base + [
            'ok' => true,
            'message' => self::text(__('La IA respondió en :seconds s con :model. Ya puedes usarla en toda la plataforma.', [
                'seconds' => number_format($latency / 1000, 1, ',', '.'), 'model' => (string) $message->model,
            ])),
            'latency_ms' => $latency,
            'served_model' => (string) $message->model,
            'reply' => mb_substr(trim($reply), 0, 80),
            'cost_usd' => $cost,
        ];
    }

    /**
     * El motivo en una palabra y lo que suele arreglarlo.
     *
     * @return array{0: string, 1: string}
     */
    public static function explain(APIException $e, string $model): array
    {
        $body = mb_strtolower($e->getMessage());

        return match (true) {
            $e instanceof AuthenticationException => ['auth', self::text(__('Anthropic no reconoce la clave. Créala otra vez en console.anthropic.com → Settings → API keys, pégala y guarda.'))],
            $e instanceof PaymentRequiredException,
            $e instanceof BadRequestException && (str_contains($body, 'credit balance') || str_contains($body, 'billing')) => ['billing', self::text(__('La cuenta de Anthropic no tiene saldo. Compra créditos en console.anthropic.com → Settings → Billing y prueba otra vez.'))],
            $e instanceof PermissionDeniedException => ['permission', self::text(__('La clave no tiene permiso para usar :model. Revisa el espacio de trabajo y los límites de la clave en console.anthropic.com.', ['model' => $model]))],
            $e instanceof NotFoundException,
            $e instanceof BadRequestException && str_contains($body, 'model') => ['model', self::text(__('El modelo :model no está disponible para esta cuenta. Elige otro modelo o pide acceso en console.anthropic.com.', ['model' => $model]))],
            $e instanceof RateLimitException => ['rate_limit', self::text(__('Anthropic recibió demasiadas peticiones de esta cuenta. Espera un minuto y prueba otra vez, o sube el límite de la cuenta.'))],
            $e instanceof InternalServerException => ['overloaded', self::text(__('Anthropic está saturado o con fallas ahora. Prueba en unos minutos; mientras tanto, todo sigue con plantillas sin IA.'))],
            $e instanceof APIConnectionException => ['network', self::text(__('No se pudo conectar con Anthropic (api.anthropic.com). Revisa la red del servidor o el cortafuegos.'))],
            $e instanceof APIStatusException => ['other', self::text(__('Anthropic rechazó la prueba (código :status).', ['status' => (string) $e->status]))],
            default => ['other', self::text(__('No se pudo probar la IA.'))],
        };
    }

    private function client(string $key): Client
    {
        $options = ['timeout' => (float) self::TIMEOUT, 'maxRetries' => 0];
        // Las pruebas inyectan un cliente HTTP (PSR-18) que responde como la API.
        if (app()->bound('igr.ai.transporter')) {
            $transporter = app('igr.ai.transporter');
            if ($transporter instanceof ClientInterface) {
                $options['transporter'] = $transporter;
            }
        }

        return new Client(apiKey: $key, baseUrl: (string) config('igr-ai.base_url'), requestOptions: $options);
    }

    /** El detalle técnico sin la clave (por si algún error la repite) y corto. */
    private static function clean(string $error, string $key): string
    {
        if (strlen($key) >= 8) {
            $error = str_replace($key, '***', $error);
        }

        return mb_substr(trim($error), 0, 400);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
