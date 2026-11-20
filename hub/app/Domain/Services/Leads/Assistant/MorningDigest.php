<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Assistant;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * El resumen matutino de «Hoy» para cada vendedor (M18, opcional en Ajustes de leads):
 * de lunes a sábado desde las 7:00 en la zona del negocio, una vez al día, cuántos
 * clientes esperan respuesta, cuántos calientes sin contactar, tareas, próximos pasos y
 * quietos, con el enlace a «Hoy». Sale por WhatsApp al teléfono del vendedor con la
 * plantilla de utilidad de avisos al equipo (`team_alert`) o, si no se puede, por correo.
 * Es un aviso interno: no lleva datos del cliente. Sin vendedores, va a los dueños.
 */
final class MorningDigest
{
    public const HOUR = 7;

    public function __construct(private readonly TodayList $list, private readonly MessageDispatcher $dispatcher, private readonly CurrentWorkspace $current) {}

    /** @return int cuántos resúmenes salieron */
    public function handle(Workspace $workspace, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone($workspace->timezone ?: 'America/Bogota');
        if ($local->isSunday() || $local->hour < self::HOUR) {
            return 0;
        }

        return $this->current->run($workspace, function () use ($workspace, $now, $local): int {
            if (! LeadSettings::for($workspace->id)->assistantRules()['digest']) {
                return 0;
            }
            $sellers = LeadSeller::query()->where('active', true)->get()->keyBy('user_id');
            $userIds = $sellers->isNotEmpty() ? $sellers->keys()->all()
                : $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('users.id')->all();
            $sent = 0;
            foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
                if (! Cache::add(RedisKey::for($workspace->id, 'leads:digest:'.$local->toDateString().':'.$user->id), 1, now()->addHours(30))) {
                    continue;
                }
                $seller = $sellers->get($user->id);
                $today = $this->list->handle($workspace, $sellers->isNotEmpty() ? $user->id : null, $now);
                $text = self::text($user, $today['counts']);
                if ($text === null) {
                    continue;
                }
                $sent += $this->send($workspace, $user, $seller instanceof LeadSeller ? $seller->phone : null, $text, $local->toDateString()) ? 1 : 0;
            }

            return $sent;
        });
    }

    /** @param array<string, int> $counts */
    public static function text(User $user, array $counts): ?string
    {
        $parts = array_filter([
            $counts['reply'] > 0 ? trans_choice('{1} 1 cliente espera tu respuesta|[2,*] :n clientes esperan tu respuesta', $counts['reply'], ['n' => $counts['reply']]) : null,
            $counts['first_contact'] > 0 ? trans_choice('{1} 1 lead caliente sin contactar|[2,*] :n leads calientes sin contactar', $counts['first_contact'], ['n' => $counts['first_contact']]) : null,
            $counts['task'] > 0 ? trans_choice('{1} 1 tarea o llamada|[2,*] :n tareas o llamadas', $counts['task'], ['n' => $counts['task']]) : null,
            $counts['next_step'] > 0 ? trans_choice('{1} 1 próximo paso|[2,*] :n próximos pasos', $counts['next_step'], ['n' => $counts['next_step']]) : null,
            $counts['stale'] > 0 ? trans_choice('{1} 1 lead quieto|[2,*] :n leads quietos', $counts['stale'], ['n' => $counts['stale']]) : null,
        ]);
        if ($parts === []) {
            return null;
        }
        $first = trim(explode(' ', trim((string) $user->name))[0]);

        return __('Buenos días:name. Para hoy: :list.', ['name' => $first !== '' ? ', '.$first : '', 'list' => implode(', ', $parts)]);
    }

    private function send(Workspace $workspace, User $user, ?string $phone, string $text, string $date): bool
    {
        $context = ['aviso' => [
            'titulo' => __('Tu lista de hoy'), 'resumen' => $text, 'detalle' => $text, 'texto' => $text,
            'url' => rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/hoy',
        ]];
        if (is_string($phone) && $phone !== '') {
            $template = TemplateRef::resolve($workspace->id, 'whatsapp', null, TemplateRef::TEAM_ALERT);
            if ($template !== null && $this->dispatch($workspace, Channel::WhatsApp, $template, $context, $phone, "leads_digest:{$user->id}:{$date}:wa")) {
                return true;
            }
        }
        $template = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
        if ($template === null) {
            TemplateLibrary::install($workspace, TemplateRef::TEAM_ALERT, copy: true);
            $template = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
        }

        return $template !== null && is_string($user->email) && $this->dispatch($workspace, Channel::Email, $template, $context, $user->email, "leads_digest:{$user->id}:{$date}:mail");
    }

    /** @param array<string, mixed> $context */
    private function dispatch(Workspace $workspace, Channel $channel, string $template, array $context, string $to, string $key): bool
    {
        try {
            return $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id, contactId: null, channel: $channel, kind: MessageKind::Utility, sourceType: 'system',
                templateId: $template, context: $context, idempotencyKey: $key, to: $to,
            ))->sent();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
