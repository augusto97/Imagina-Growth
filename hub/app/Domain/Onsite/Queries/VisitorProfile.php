<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Queries;

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Onsite\Actions\ConfirmDoubleOptIn;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `visitor.json` (api-connector.md §8.3): lo que el navegador necesita para evaluar la
 * segmentación de los widgets que dependen de la persona, sin datos personales:
 * conocido, recurrente, suscrito por canal, país (de la petición, sin guardarlo) y
 * los segmentos que usa algún widget activo. Debe correr dentro del contexto del
 * espacio de trabajo.
 */
final class VisitorProfile
{
    /**
     * @param  Collection<int, OnsiteWidget>  $live  widgets activos del sitio
     * @return array{known: bool, returning: bool, subscribed: array{email: bool, whatsapp: bool}, country: string|null, segments: list<string>}
     */
    public function for(Workspace $workspace, ?string $anonymousId, ?string $requestCountry, Collection $live): array
    {
        $contact = $this->contact($anonymousId);
        $subscribed = ['email' => false, 'whatsapp' => false];
        $segments = [];
        if ($contact !== null) {
            $granted = DB::table('contact_consents')->where('workspace_id', $workspace->id)->where('contact_id', $contact->id)
                ->where('purpose', 'marketing')->where('status', 'granted')->pluck('channel')->all();
            $subscribed = ['email' => in_array('email', $granted, true), 'whatsapp' => in_array('whatsapp', $granted, true)];

            $used = $live->flatMap(fn (OnsiteWidget $w) => (array) ($w->targeting['segments'] ?? []))->unique()
                ->map(fn ($id) => PrefixedId::decode(Segment::ID_PREFIX, is_string($id) ? $id : null))->filter()->values()->all();
            if ($used !== []) {
                $segments = DB::table('segment_memberships')->where('workspace_id', $workspace->id)->where('contact_id', $contact->id)
                    ->whereIn('segment_id', $used)->pluck('segment_id')->map(fn ($id) => PrefixedId::encode(Segment::ID_PREFIX, (string) $id))->all();
                $segments = array_values($segments);
            }
        }

        return [
            'known' => $contact !== null,
            'returning' => $this->returning($workspace, $contact, $anonymousId),
            'subscribed' => $subscribed,
            'country' => $requestCountry ?? (is_string($contact?->getAttribute('country')) ? $contact->getAttribute('country') : null),
            'segments' => $segments,
        ];
    }

    private function contact(?string $anonymousId): ?Contact
    {
        if ($anonymousId === null || $anonymousId === '') {
            return null;
        }
        $id = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $anonymousId)->value('contact_id');

        return is_string($id) ? ConfirmDoubleOptIn::canonical($id) : null;
    }

    /** Recurrente: ya tenía una sesión anterior (más de 30 min) o ya compró. */
    private function returning(Workspace $workspace, ?Contact $contact, ?string $anonymousId): bool
    {
        if ($contact !== null && ((int) $contact->getAttribute('orders_count') > 0
            || ($contact->first_seen_at !== null && $contact->first_seen_at->lessThan(now()->subMinutes(30))))) {
            return true;
        }
        if ($anonymousId === null || $anonymousId === '') {
            return false;
        }

        return (bool) Cache::remember('igr-onsite:returning:'.$workspace->id.':'.sha1($anonymousId), 600, function () use ($workspace, $anonymousId): bool {
            try {
                return (int) Ch::forWorkspace($workspace)->value(
                    'SELECT count() FROM sessions WHERE workspace_id = {workspace_id:UInt32} AND anonymous_id = {aid:String}
                       AND started_at > now() - INTERVAL 180 DAY AND started_at < now() - INTERVAL 30 MINUTE',
                    ['aid' => $anonymousId],
                ) > 0;
            } catch (Throwable) {
                return false;
            }
        });
    }
}
