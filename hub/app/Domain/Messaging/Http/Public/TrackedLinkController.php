<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Public;

use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\TrackedLink;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /c/{code}: registra el clic y redirige al destino con `utm_*` y `_igr`
 * (hub/CLAUDE.md §8). Las vistas previas de WhatsApp, Facebook y los
 * escáneres de correo redirigen igual pero no cuentan como clic.
 */
final class TrackedLinkController
{
    private const BOTS = '/bot|crawl|spider|preview|facebookexternalhit|whatsapp|slackbot|telegrambot|skypeuripreview|barracuda|proofpoint|mimecast|safelinks|python-requests|curl\//i';

    public function __invoke(Request $request, string $code, CurrentWorkspace $current, MessageEvents $events): Response
    {
        $link = TrackedLink::withoutWorkspaceScope()->find($code);
        $workspace = $link !== null ? Workspace::query()->find($link->workspace_id) : null;
        if ($link === null || $workspace === null) {
            return response()->view('messaging.link-missing', [], 404);
        }
        $message = Message::withoutWorkspaceScope()->find($link->message_id);
        $human = ! $request->isMethod('HEAD') && preg_match(self::BOTS, (string) $request->userAgent()) !== 1;
        if ($human) {
            $current->run($workspace, function () use ($link, $message, $workspace, $events): void {
                TrackedLink::query()->whereKey($link->code)->update([
                    'clicks' => DB::raw('clicks + 1'),
                    'first_clicked_at' => $link->first_clicked_at ?? now(),
                ]);
                if ($message !== null && Cache::add('ws:'.$workspace->id.':click:'.$link->code, 1, 10)) {
                    if ($message->clicked_at === null) {
                        $message->forceFill(['clicked_at' => now()])->save();
                    }
                    $events->emit($workspace, $message, 'message.clicked', ['url' => mb_substr($link->url, 0, 2000), 'link_code' => $link->code]);
                }
            });
        }

        return redirect()->away(self::destination($link, $message))->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /** La URL de destino con utm_source=igr, utm_medium={canal}, utm_campaign y _igr={code}. */
    public static function destination(TrackedLink $link, ?Message $message): string
    {
        $parts = parse_url($link->url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $link->url;
        }
        parse_str($parts['query'] ?? '', $query);
        $campaign = null;
        if ($message?->flow_id !== null) {
            $campaign = DB::table('flows')->where('id', $message->flow_id)->value('name');
        } elseif ($message?->campaign_id !== null) {
            $campaign = DB::table('campaigns')->where('id', $message->campaign_id)->value('name');
        }
        $query += [
            'utm_source' => 'igr',
            'utm_medium' => $message?->channel->value ?? 'message',
            'utm_campaign' => is_string($campaign) ? Str::slug($campaign) : ($message->source_type ?? 'mensaje'),
        ];
        $query['_igr'] = $link->code;

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/').'?'.http_build_query($query).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
