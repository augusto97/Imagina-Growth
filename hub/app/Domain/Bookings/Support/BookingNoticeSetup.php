<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Inbox\Push\WebPushNotifier;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Notices\Models\NoticePreference;
use App\Domain\Notices\Support\NoticePreferences;
use App\Domain\Notices\Support\PlatformMail;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;

/**
 * «Así avisamos» de la agenda (lote 0.4.0, docs/avisos.md §4): cómo le llega la confirmación al cliente y a quién del
 * equipo se le avisa de cada cita, con lo que falta y dónde se arregla. Solo estados y claves: los textos los pone la
 * SPA. Corre con el espacio de trabajo activo.
 */
final class BookingNoticeSetup
{
    /** @return array<string, mixed> */
    public static function for(Workspace $workspace): array
    {
        $settings = BookingSettings::for($workspace);
        $wa = ChannelAccount::defaultFor(Channel::WhatsApp);
        $own = ChannelAccount::defaultFor(Channel::Email);
        $templates = (array) ($settings['whatsapp_templates'] ?? []);
        $hasTemplate = is_string($templates['confirmation'] ?? null);

        $whatsapp = match (true) {
            $wa === null => 'none',
            $wa->isUnofficial() => 'unofficial',
            $hasTemplate => 'ready',
            default => 'no_template',
        };
        $email = match (true) {
            $own !== null => 'own',
            ! PlatformMail::available() => 'platform_off',
            ! PlatformSending::emailAllowed($workspace) => 'plan',
            default => 'platform',
        };
        $emailWorks = $email === 'own' || $email === 'platform';
        // El WhatsApp no oficial manda texto: la confirmación sale ahí sin plantilla (frente de WhatsApp, lote 0.4.0).
        $whatsappWorks = $whatsapp === 'ready' || $whatsapp === 'unofficial';

        $fixes = [];
        if (! $whatsappWorks && ! $emailWorks) {
            $fixes[] = 'nothing_reaches';
        }
        if ($whatsapp === 'none') {
            $fixes[] = 'connect_whatsapp';
        } elseif ($whatsapp === 'no_template') {
            $fixes[] = 'choose_template';
        }
        if ($email === 'platform_off') {
            $fixes[] = 'platform_mail';
        } elseif ($email === 'plan') {
            $fixes[] = 'connect_email';
        }
        if (! $whatsappWorks && $emailWorks && ! (bool) $settings['require_email']) {
            $fixes[] = 'require_email';
        }

        return [
            'customer' => [
                'whatsapp' => $whatsapp,
                'whatsapp_number' => $wa?->identity,
                'email' => $email,
                'email_from' => $own !== null ? ($own->identity ?? $own->display_name) : ($email === 'platform' ? PlatformMail::fromName($workspace).' <'.config('mail.from.address').'>' : null),
                'reply_to' => $own === null && $email === 'platform' ? PlatformMail::replyTo($workspace) : null,
                'ask_email' => (bool) $settings['ask_email'],
                'require_email' => (bool) $settings['require_email'],
                'reminders' => array_values(array_map('intval', (array) $settings['reminder_hours'])),
            ],
            'team' => [
                'platform_mail' => PlatformMail::available(),
                'push' => WebPushNotifier::configured(),
                'people' => self::people($workspace),
            ],
            'fixes' => $fixes,
        ];
    }

    /**
     * Quién del equipo recibe los avisos de las citas y por dónde (los de «de todo el negocio» y quienes atienden).
     *
     * @return list<array<string, mixed>>
     */
    private static function people(Workspace $workspace): array
    {
        $staffUsers = BookingStaff::query()->where('active', true)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $members = $workspace->users()->get(['users.id', 'users.name']);
        $saved = NoticePreference::query()->whereIn('user_id', $members->pluck('id'))->get()->keyBy('user_id');
        $out = [];
        foreach ($members as $user) {
            /** @var User $user */
            $role = WorkspaceRole::tryFrom((string) $user->pivot?->getAttribute('role'));
            $prefs = NoticePreferences::for($user->id, $role, $saved->get($user->id))['appointments'];
            $all = $prefs['scope'] === 'all';
            if (! $all && ! in_array($user->id, $staffUsers, true)) {
                continue;
            }
            $out[] = [
                'name' => (string) $user->name,
                'scope' => $all ? 'all' : 'mine',
                'channels' => array_values(array_filter(['in_app', 'email', 'push', 'whatsapp'], fn (string $c) => ($prefs[$c] ?? false) === true)),
            ];
        }
        foreach (BookingStaff::query()->where('active', true)->whereNull('user_id')->get(['id', 'name', 'email']) as $staff) {
            if (is_string($staff->email) && $staff->email !== '') {
                $out[] = ['name' => (string) $staff->name, 'scope' => 'mine', 'channels' => ['email']];
            }
        }

        return $out;
    }
}
