<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Public;

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Messaging\Models\ContactPreference;
use App\Domain\Messaging\Sending\UnsubscribeService;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centro de preferencias del contacto (M07): canales, temas y frecuencia, o la
 * baja de todo. Sin sesión: el token firmado identifica al contacto. Los
 * cambios se aplican al instante (consentimiento y contact_preferences).
 */
final class PreferenceCenterController
{
    public function show(string $token, CurrentWorkspace $current): Response
    {
        $ctx = ContactLinkContext::fromToken($token);
        if ($ctx === null) {
            return response()->view('messaging.link-missing', [], 404);
        }

        return $current->run($ctx->workspace, fn () => response()->view('messaging.preferences', $this->data($ctx) + ['saved' => false])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']));
    }

    public function update(Request $request, string $token, CurrentWorkspace $current, UnsubscribeService $unsubscribe, RecordConsent $consents, ConsentTexts $texts): Response
    {
        $ctx = ContactLinkContext::fromToken($token);
        if ($ctx === null) {
            return response()->view('messaging.link-missing', [], 404);
        }
        $topics = array_keys((array) config('igr-messaging.preference_topics'));
        $data = $request->validate([
            'channels' => ['array'],
            'channels.*' => [Rule::in(['email', 'whatsapp', 'sms'])],
            'topics' => ['array'],
            'topics.*' => [Rule::in($topics)],
            'frequency' => ['nullable', Rule::in(ContactPreference::FREQUENCIES)],
            'all' => ['nullable', 'boolean'],
        ]);
        $evidence = ['ip_hash' => $request->ip() !== null ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) : null, 'page_url' => 'preference_center'];

        return $current->run($ctx->workspace, function () use ($ctx, $data, $unsubscribe, $consents, $texts, $evidence) {
            if (! empty($data['all'])) {
                $unsubscribe->unsubscribe($ctx->workspace, $ctx->contact->id, $ctx->channel, 'all', 'preference_center', $ctx->message, $evidence);
            } else {
                $wanted = (array) ($data['channels'] ?? []);
                foreach ($this->available($ctx) as $channel) {
                    $granted = $this->granted($ctx->contact->id, $channel);
                    if ($granted && ! in_array($channel, $wanted, true)) {
                        $unsubscribe->unsubscribe($ctx->workspace, $ctx->contact->id, $channel, 'channel', 'preference_center', $ctx->channel === $channel ? $ctx->message : null, $evidence);
                    } elseif (! $granted && in_array($channel, $wanted, true)) {
                        $consents->handle($ctx->workspace, $ctx->contact->id, $channel, 'marketing', 'granted', 'preference_center', $texts->latest($ctx->workspace, $channel)?->version, CarbonImmutable::now(), $evidence, true);
                    }
                }
                ContactPreference::query()->updateOrCreate(
                    ['contact_id' => $ctx->contact->id],
                    ['workspace_id' => $ctx->workspace->id, 'topics' => array_values((array) ($data['topics'] ?? [])), 'frequency' => $data['frequency'] ?? 'normal'],
                );
            }

            return response()->view('messaging.preferences', $this->data($ctx) + ['saved' => true])
                ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
        });
    }

    /** @return array<string, mixed> */
    private function data(ContactLinkContext $ctx): array
    {
        $pref = ContactPreference::query()->find($ctx->contact->id);
        $channels = [];
        foreach ($this->available($ctx) as $channel) {
            $channels[$channel] = $this->granted($ctx->contact->id, $channel);
        }

        return [
            'brand' => $ctx->workspace->name,
            'channels' => $channels,
            'topics' => (array) config('igr-messaging.preference_topics'),
            'chosenTopics' => $pref->topics ?? array_keys((array) config('igr-messaging.preference_topics')),
            'frequency' => $pref->frequency ?? 'normal',
            'action' => url()->current(),
        ];
    }

    /** @return list<string> canales en los que el contacto tiene dirección */
    private function available(ContactLinkContext $ctx): array
    {
        $out = [];
        if ($ctx->contact->email_bidx !== null) {
            $out[] = 'email';
        }
        if ($ctx->contact->phone_bidx !== null) {
            $out[] = 'whatsapp';
            $out[] = 'sms';
        }

        return $out;
    }

    private function granted(string $contactId, string $channel): bool
    {
        return ContactConsent::query()->where('contact_id', $contactId)->where('channel', $channel)->where('purpose', 'marketing')->where('status', 'granted')->exists();
    }
}
