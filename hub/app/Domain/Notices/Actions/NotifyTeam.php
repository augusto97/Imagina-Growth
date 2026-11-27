<?php

declare(strict_types=1);

namespace App\Domain\Notices\Actions;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Inbox\Push\PushNotifier;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Notices\Data\TeamNotice;
use App\Domain\Notices\Mail\TeamNoticeMail;
use App\Domain\Notices\Models\Notice;
use App\Domain\Notices\Models\NoticePreference;
use App\Domain\Notices\Realtime\NoticeCreated;
use App\Domain\Notices\Support\NoticeLogger;
use App\Domain\Notices\Support\NoticePreferences;
use App\Domain\Notices\Support\PlatformMail;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\Pii;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa al equipo (lote 0.4.0, docs/avisos.md §2) por el centro de avisos de la app, el correo de la plataforma, el
 * navegador y WhatsApp, según «Mis avisos» de cada persona (`NoticePreferences`).
 *
 * A quién: las personas involucradas (`userIds`: la de la cita o el vendedor del lead) y, en citas y leads, los dueños y
 * administradores que eligieron «de todo el negocio»; las conexiones, a dueños y administradores. Nunca a quien hizo el
 * cambio en el panel (`excludeUserId`). Las personas sin usuario del panel (`emails`) solo por correo.
 *
 * Una persona recibe cada aviso una vez (`dedupe` en la caché, un día) aunque el trabajo se repita. El correo sale por
 * el correo de la plataforma: no depende de que el negocio haya conectado el suyo. Corre con el espacio de trabajo activo.
 */
final class NotifyTeam
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly MessageDispatcher $dispatcher,
        private readonly Branding $branding,
    ) {}

    /** @return int personas avisadas */
    public function handle(Workspace $workspace, TeamNotice $notice): int
    {
        $channels = NoticePreferences::CHANNELS[$notice->group] ?? ['in_app'];
        $members = $workspace->users()->get(['users.id', 'users.name', 'users.email']);
        $saved = NoticePreference::query()->whereIn('user_id', $members->pluck('id'))->get()->keyBy('user_id');
        $url = $this->url($workspace, $notice->path);
        $count = 0;

        foreach ($members as $user) {
            /** @var User $user */
            $role = WorkspaceRole::tryFrom((string) $user->pivot?->getAttribute('role'));
            if ($user->id === $notice->excludeUserId || ! $this->wanted($notice, $user->id, $role, $saved->get($user->id))) {
                continue;
            }
            if (! Cache::add('ws:'.$workspace->id.':notice:'.sha1($notice->dedupe).':'.$user->id, 1, 86400)) {
                continue;
            }
            $prefs = NoticePreferences::for($user->id, $role, $saved->get($user->id))[$notice->group];
            $label = (string) $user->name;
            $reached = false;
            if (($prefs['in_app'] ?? false) === true) {
                $this->inApp($workspace, $notice, $user->id, $label);
                $reached = true;
            }
            if (in_array('email', $channels, true) && ($prefs['email'] ?? false) === true) {
                $reached = $this->email($workspace, $notice, (string) $user->email, $label, $user->id, $url) || $reached;
            }
            if (in_array('push', $channels, true) && ($prefs['push'] ?? false) === true) {
                $reached = $this->pushTo($workspace, $notice, $user->id, $label, $url) || $reached;
            }
            if (in_array('whatsapp', $channels, true) && ($prefs['whatsapp'] ?? false) === true) {
                $reached = $this->whatsapp($workspace, $notice, NoticePreferences::phone($user->id, $saved->get($user->id)), $label, $user->id, $url) || $reached;
            }
            $count += $reached ? 1 : 0;
        }

        if (in_array('email', $channels, true)) {
            foreach ($notice->emails as $person) {
                if (Cache::add('ws:'.$workspace->id.':notice:'.sha1($notice->dedupe).':'.sha1(mb_strtolower($person['email'])), 1, 86400)) {
                    $count += $this->email($workspace, $notice, $person['email'], $person['name'] !== '' ? $person['name'] : Pii::maskEmail($person['email']), null, $url) ? 1 : 0;
                }
            }
        }

        return $count;
    }

    private function wanted(TeamNotice $notice, string $userId, ?WorkspaceRole $role, ?NoticePreference $saved): bool
    {
        if ($role === null) {
            return false;
        }
        if (in_array($userId, $notice->userIds, true)) {
            return true;
        }

        return match ($notice->group) {
            'conversations' => false,
            'connections' => NoticePreferences::manager($role),
            default => (NoticePreferences::for($userId, $role, $saved)[$notice->group]['scope'] ?? 'mine') === 'all',
        };
    }

    private function inApp(Workspace $workspace, TeamNotice $notice, string $userId, string $label): void
    {
        $row = Notice::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $userId, 'kind' => $notice->kind, 'title' => $notice->title, 'body' => $notice->body,
            'url' => $notice->path !== null ? '/w/'.$workspace->publicId().'/'.ltrim($notice->path, '/') : null,
            'subject_type' => $notice->subjectType, 'subject_id' => $notice->subjectId,
        ]);
        event(new NoticeCreated($workspace->id, $userId, $row->publicId()));
        $this->log($workspace, $notice, 'in_app', 'sent', null, null, $label, $userId);
    }

    private function email(Workspace $workspace, TeamNotice $notice, string $to, string $label, ?string $userId, string $url): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->log($workspace, $notice, 'email', 'skipped', 'no_email', null, $label, $userId);

            return false;
        }
        if (! PlatformMail::available()) {
            $this->log($workspace, $notice, 'email', 'skipped', 'platform_mail_off', null, $label, $userId);

            return false;
        }
        try {
            Mail::to($to)->send(new TeamNoticeMail($workspace->name, $notice->title, $notice->body, $url, $this->branding->for($workspace)));
        } catch (Throwable $e) {
            report($e);
            $this->log($workspace, $notice, 'email', 'failed', 'platform_mail', null, $label, $userId);

            return false;
        }
        $this->log($workspace, $notice, 'email', 'sent', null, null, $label, $userId);

        return true;
    }

    private function pushTo(Workspace $workspace, TeamNotice $notice, string $userId, string $label, string $url): bool
    {
        try {
            $sent = $this->push->notify($workspace->id, $userId, $notice->title, $url);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        // Sin suscripciones del navegador no se registra nada: no es un fallo, la persona no activó los avisos ahí.
        if ($sent > 0) {
            $this->log($workspace, $notice, 'push', 'sent', null, null, $label, $userId);
        }

        return $sent > 0;
    }

    private function whatsapp(Workspace $workspace, TeamNotice $notice, ?string $phone, string $label, string $userId, string $url): bool
    {
        if ($phone === null) {
            $this->log($workspace, $notice, 'whatsapp', 'skipped', 'no_phone', null, $label, $userId);

            return false;
        }
        $templateId = TemplateRef::resolve($workspace->id, 'whatsapp', null, TemplateRef::TEAM_ALERT);
        if ($templateId === null) {
            $this->log($workspace, $notice, 'whatsapp', 'skipped', 'no_whatsapp_template', null, $label, $userId);

            return false;
        }
        try {
            $result = $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id, contactId: $notice->contactId, channel: Channel::WhatsApp, kind: MessageKind::Utility,
                sourceType: 'system', sourceId: $notice->subjectId, templateId: $templateId,
                context: ['aviso' => ['titulo' => $notice->title, 'resumen' => (string) $notice->body, 'detalle' => (string) $notice->body, 'texto' => (string) $notice->body, 'url' => $url]],
                idempotencyKey: 'notice:'.sha1($notice->dedupe).':'.$userId.':wa', to: $phone,
            ));
        } catch (Throwable $e) {
            report($e);
            $this->log($workspace, $notice, 'whatsapp', 'failed', 'driver_error', null, $label, $userId);

            return false;
        }
        $status = NoticeLogger::status($result);
        if ($status !== null) {
            $this->log($workspace, $notice, 'whatsapp', $status, $result->reason, $result->messageId, $label, $userId);
        }

        return $result->sent();
    }

    private function log(Workspace $workspace, TeamNotice $notice, string $channel, string $status, ?string $reason, ?string $messageId, string $label, ?string $userId): void
    {
        if ($notice->logEvent === null || $notice->subjectType === null || $notice->subjectId === null) {
            return;
        }
        NoticeLogger::record($workspace->id, $notice->subjectType, $notice->subjectId, 'team', $notice->logEvent, $channel, $status, $reason, $messageId, $label, $userId);
    }

    private function url(Workspace $workspace, ?string $path): string
    {
        $base = rtrim((string) ($this->branding->for($workspace)->panelUrl ?: config('app.url')), '/');

        return $base.'/w/'.$workspace->publicId().($path !== null ? '/'.ltrim($path, '/') : '');
    }
}
