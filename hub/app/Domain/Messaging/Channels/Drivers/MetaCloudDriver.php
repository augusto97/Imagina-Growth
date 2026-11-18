<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\WaRates;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API de Meta (M07, cumplimiento.md §5.1). El marketing sale por
 * la Marketing Messages API (`/marketing_messages`, entrega optimizada) y lo
 * demás por `/messages` **[verificar]** la ruta y la versión vigentes.
 */
final class MetaCloudDriver implements ChannelDriver
{
    /** Errores de Meta que se reintentan: límites de velocidad y fallas temporales. */
    private const RETRYABLE = ['4', '80007', '130429', '131000', '131016', '131056', '133004'];

    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $phoneId = $account->credential('phone_number_id');
        $token = $account->credential('access_token');
        if ($phoneId === null || $token === null) {
            return SendResult::failed(__('Falta el número o el token de WhatsApp en la cuenta.'), 'not_configured');
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($message->to, '+'),
        ];
        if ($message->waTemplateName !== null) {
            $body['type'] = 'template';
            $body['template'] = array_filter([
                'name' => $message->waTemplateName,
                'language' => ['code' => $message->waLanguage ?? 'es'],
                'components' => $message->waComponents,
            ], fn ($v) => $v !== []);
        } else {
            $body['type'] = 'text';
            $body['text'] = ['body' => (string) $message->text, 'preview_url' => true];
        }
        $path = $message->isMarketing && $message->waTemplateName !== null ? 'marketing_messages' : 'messages';

        try {
            $response = Http::withToken($token)->timeout(15)->acceptJson()->post($this->url($phoneId.'/'.$path), $body);
        } catch (ConnectionException $e) {
            return SendResult::failed(__('Sin conexión con Meta: :msg', ['msg' => $e->getMessage()]), 'connection', true);
        }
        if ($response->successful() && is_string($response->json('messages.0.id'))) {
            return SendResult::sent((string) $response->json('messages.0.id'));
        }

        return $this->error($response);
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = (array) ($change['value'] ?? []);
                $phoneId = isset($value['metadata']['phone_number_id']) ? (string) $value['metadata']['phone_number_id'] : null;
                if ($field === 'messages') {
                    $this->parseMessages($batch, $value, $phoneId);
                } elseif ($field === 'message_template_status_update') {
                    $batch->templates[] = [
                        'account' => null,
                        'meta_template_id' => isset($value['message_template_id']) ? (string) $value['message_template_id'] : null,
                        'name' => isset($value['message_template_name']) ? (string) $value['message_template_name'] : null,
                        'language' => isset($value['message_template_language']) ? (string) $value['message_template_language'] : null,
                        'status' => strtoupper((string) ($value['event'] ?? '')),
                        'reason' => isset($value['reason']) && $value['reason'] !== 'NONE' ? (string) $value['reason'] : null,
                    ];
                } elseif ($field === 'phone_number_quality_update') {
                    $batch->quality[] = [
                        'account' => null,
                        'quality' => match (strtoupper((string) ($value['event'] ?? ''))) {
                            'FLAGGED' => 'YELLOW', 'DOWNGRADE' => 'RED', 'UNFLAGGED', 'UPGRADE' => 'GREEN', default => null,
                        },
                        'tier' => isset($value['current_limit']) ? (string) $value['current_limit'] : null,
                        'display_phone_number' => isset($value['display_phone_number']) ? (string) $value['display_phone_number'] : null,
                    ];
                }
            }
        }

        return $batch;
    }

    /** @param array<string, mixed> $value */
    private function parseMessages(WebhookBatch $batch, array $value, ?string $phoneId): void
    {
        foreach ((array) ($value['statuses'] ?? []) as $status) {
            $error = $status['errors'][0] ?? null;
            $batch->statuses[] = ['account' => $phoneId, 'update' => new StatusUpdate(
                (string) ($status['id'] ?? ''),
                (string) ($status['status'] ?? ''),
                CarbonImmutable::createFromTimestamp((int) ($status['timestamp'] ?? time())),
                is_array($error) ? (string) ($error['title'] ?? $error['message'] ?? '') : null,
                is_array($error) && isset($error['code']) ? (string) $error['code'] : null,
            )];
        }
        $names = [];
        foreach ((array) ($value['contacts'] ?? []) as $c) {
            $names[(string) ($c['wa_id'] ?? '')] = $c['profile']['name'] ?? null;
        }
        foreach ((array) ($value['messages'] ?? []) as $m) {
            $type = (string) ($m['type'] ?? 'other');
            $text = match ($type) {
                'text' => $m['text']['body'] ?? null,
                'button' => $m['button']['text'] ?? null,
                'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
                default => null,
            };
            $payload = match ($type) {
                'button' => $m['button']['payload'] ?? null,
                'interactive' => $m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? null,
                default => null,
            };
            $from = (string) ($m['from'] ?? '');
            $referral = is_array($m['referral'] ?? null) ? $m['referral'] : [];
            $media = in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true) && is_array($m[$type] ?? null) ? $m[$type] : [];
            $batch->inbound[] = ['account' => $phoneId, 'message' => new InboundMessage(
                '+'.ltrim($from, '+'),
                (string) ($m['id'] ?? ''),
                in_array($type, ['text', 'button', 'interactive', 'image', 'audio', 'video', 'document', 'sticker', 'location', 'contacts', 'reaction'], true) ? $type : 'other',
                CarbonImmutable::createFromTimestamp((int) ($m['timestamp'] ?? time())),
                is_string($text) ? $text : null,
                is_string($payload) ? $payload : null,
                isset($m['context']['id']) ? (string) $m['context']['id'] : null,
                isset($referral['ctwa_clid']) ? (string) $referral['ctwa_clid'] : null,
                isset($referral['source_type']) ? (string) $referral['source_type'] : null,
                isset($referral['source_id']) ? (string) $referral['source_id'] : null,
                is_string($names[$from] ?? null) ? $names[$from] : null,
                $phoneId,
                isset($media['id']) ? (string) $media['id'] : null,
                isset($media['mime_type']) ? (string) $media['mime_type'] : null,
                isset($media['caption']) ? (string) $media['caption'] : null,
            )];
        }
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        $waba = $account->credential('waba_id');
        $token = $account->credential('access_token');
        if ($waba === null || $token === null) {
            return 0;
        }
        $url = $this->url($waba.'/message_templates').'?'.http_build_query(['fields' => 'id,name,language,category,status,components,quality_score,rejected_reason', 'limit' => 100]);
        $count = 0;
        for ($page = 0; $page < 20 && $url !== null; $page++) {
            $response = Http::withToken($token)->timeout(20)->acceptJson()->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException($this->errorMessage($response));
            }
            foreach ((array) $response->json('data', []) as $tpl) {
                WaTemplate::query()->updateOrCreate(
                    ['channel_account_id' => $account->id, 'name' => (string) $tpl['name'], 'language' => (string) $tpl['language']],
                    [
                        'workspace_id' => $account->workspace_id,
                        'category' => strtoupper((string) ($tpl['category'] ?? 'MARKETING')),
                        'status' => strtoupper((string) ($tpl['status'] ?? 'PENDING')),
                        'quality' => isset($tpl['quality_score']['score']) ? (string) $tpl['quality_score']['score'] : null,
                        'components' => array_values((array) ($tpl['components'] ?? [])),
                        'meta_template_id' => isset($tpl['id']) ? (string) $tpl['id'] : null,
                        'rejected_reason' => isset($tpl['rejected_reason']) && $tpl['rejected_reason'] !== 'NONE' ? (string) $tpl['rejected_reason'] : null,
                        'synced_at' => now(),
                    ],
                );
                $count++;
            }
            $next = $response->json('paging.next');
            $url = is_string($next) ? $next : null;
        }

        return $count;
    }

    /**
     * Envía una plantilla a revisión de Meta. Devuelve [meta_template_id, status].
     *
     * @return array{0: string|null, 1: string}
     */
    public function submitTemplate(ChannelAccount $account, WaTemplate $template): array
    {
        $response = Http::withToken((string) $account->credential('access_token'))->timeout(20)->acceptJson()
            ->post($this->url($account->credential('waba_id').'/message_templates'), [
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category,
                'components' => $template->components,
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        return [is_scalar($response->json('id')) ? (string) $response->json('id') : null, strtoupper((string) ($response->json('status') ?? 'PENDING'))];
    }

    /**
     * Edita una plantilla ya enviada (vuelve a revisión).
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function editTemplate(ChannelAccount $account, WaTemplate $template, array $components): void
    {
        if ($template->meta_template_id === null) {
            throw new \RuntimeException(__('La plantilla no tiene ID de Meta: sincroniza el número primero.'));
        }
        $response = Http::withToken((string) $account->credential('access_token'))->timeout(20)->acceptJson()
            ->post($this->url($template->meta_template_id), ['components' => $components]);
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }
    }

    public function deleteTemplate(ChannelAccount $account, string $name): void
    {
        Http::withToken((string) $account->credential('access_token'))->timeout(15)
            ->delete($this->url($account->credential('waba_id').'/message_templates').'?'.http_build_query(['name' => $name]));
    }

    /**
     * Número, nombre verificado, calidad y nivel de límite del número.
     *
     * @return array{display_phone_number: string|null, verified_name: string|null, quality_rating: string|null, messaging_limit_tier: string|null}
     */
    public function phoneInfo(ChannelAccount $account): array
    {
        $response = Http::withToken((string) $account->credential('access_token'))->timeout(15)->acceptJson()
            ->get($this->url((string) $account->credential('phone_number_id')), ['fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier']);
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }
        $str = fn (string $k) => is_scalar($response->json($k)) ? (string) $response->json($k) : null;

        return [
            'display_phone_number' => $str('display_phone_number'),
            'verified_name' => $str('verified_name'),
            'quality_rating' => $str('quality_rating'),
            'messaging_limit_tier' => $str('messaging_limit_tier'),
        ];
    }

    /**
     * Canje del código de Embedded Signup por el token del negocio, suscripción de la
     * app a la WABA y registro del número **[verificar]** con la app real de Meta.
     */
    public function exchangeEmbeddedSignup(string $code): string
    {
        $response = Http::timeout(15)->acceptJson()->get($this->url('oauth/access_token'), [
            'client_id' => config('igr-messaging.meta.app_id'),
            'client_secret' => config('igr-messaging.meta.app_secret'),
            'code' => $code,
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        return $token;
    }

    public function subscribeApp(string $wabaId, string $token): void
    {
        $response = Http::withToken($token)->timeout(15)->post($this->url($wabaId.'/subscribed_apps'));
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): ?float
    {
        return WaRates::rate($country, $kind);
    }

    public function capabilities(): array
    {
        return ['marketing' => true, 'templates' => true, 'free_text' => true, 'media' => true, 'buttons' => true, 'rate_per_second' => (int) config('igr-messaging.meta.rate_per_second', 50)];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('igr-messaging.meta.graph_url'), '/').'/'.config('igr-messaging.meta.graph_version').'/'.ltrim($path, '/');
    }

    private function error(Response $response): SendResult
    {
        $code = $response->json('error.code');
        $code = is_scalar($code) ? (string) $code : (string) $response->status();
        $retryable = $response->serverError() || $response->status() === 429 || in_array($code, self::RETRYABLE, true);

        return SendResult::failed($this->errorMessage($response), $code, $retryable);
    }

    private function errorMessage(Response $response): string
    {
        $details = $response->json('error.error_data.details');
        $message = $response->json('error.message');

        return trim((is_string($message) ? $message : __('Meta respondió :status', ['status' => $response->status()])).(is_string($details) ? ' — '.$details : ''));
    }
}
