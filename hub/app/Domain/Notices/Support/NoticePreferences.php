<?php

declare(strict_types=1);

namespace App\Domain\Notices\Support;

use App\Domain\Notices\Models\NoticePreference;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;

/**
 * «Mis avisos» (lote 0.4.0, docs/avisos.md): qué avisos recibe cada persona del equipo y por dónde. Lo guardado en
 * `notice_preferences` se mezcla encima de lo de fábrica de su rol.
 *
 * - Grupos: `appointments` (cita nueva, movida, cancelada o confirmada por el cliente), `leads` (nuevo, caliente,
 *   calificado), `conversations` (te asignaron una conversación) y `connections` (una conexión vence o se cayó).
 * - Canales de cada grupo (`CHANNELS`): `in_app` (la campana), `email` (el correo de la plataforma), `push` (el
 *   navegador, si la plataforma tiene las llaves) y `whatsapp` (plantilla `team_alert` aprobada).
 * - `scope` (solo citas y leads): `all` = de todo el negocio (dueños y administradores), `mine` = solo lo asignado a
 *   la persona. Un rol que no es dueño ni administrador siempre recibe solo lo suyo.
 */
final class NoticePreferences
{
    public const GROUPS = ['appointments', 'leads', 'conversations', 'connections'];

    /** @var array<string, list<string>> canales que tiene cada grupo */
    public const CHANNELS = [
        'appointments' => ['in_app', 'email', 'push', 'whatsapp'],
        'leads' => ['in_app', 'push'],
        // El aviso del navegador de una conversación asignada ya lo manda la bandeja (AssignConversation).
        'conversations' => ['in_app'],
        'connections' => ['in_app'],
    ];

    /** Grupos que pueden ser de todo el negocio o solo de lo asignado. */
    public const SCOPED = ['appointments', 'leads'];

    /** @return array<string, array<string, bool|string>> */
    public static function defaults(?WorkspaceRole $role): array
    {
        $manager = self::manager($role);

        return [
            'appointments' => ['in_app' => true, 'email' => true, 'push' => true, 'whatsapp' => false, 'scope' => $manager ? 'all' : 'mine'],
            'leads' => ['in_app' => true, 'push' => false, 'scope' => $manager ? 'all' : 'mine'],
            'conversations' => ['in_app' => true],
            'connections' => ['in_app' => $manager],
        ];
    }

    /** ¿Dueño o administrador? (los únicos que reciben lo de todo el negocio y las conexiones). */
    public static function manager(?WorkspaceRole $role): bool
    {
        return $role === WorkspaceRole::Owner || $role === WorkspaceRole::Admin;
    }

    /**
     * Las preferencias de una persona: lo guardado encima de lo de su rol, solo con los canales de cada grupo.
     *
     * @return array<string, array<string, bool|string>>
     */
    public static function for(string $userId, ?WorkspaceRole $role, ?NoticePreference $saved = null): array
    {
        $saved ??= NoticePreference::query()->where('user_id', $userId)->first();

        return self::merge(self::defaults($role), (array) ($saved->settings ?? []), $role);
    }

    /**
     * @param  array<string, array<string, bool|string>>  $base
     * @param  array<mixed>  $values
     * @return array<string, array<string, bool|string>>
     */
    public static function merge(array $base, array $values, ?WorkspaceRole $role): array
    {
        foreach (self::GROUPS as $group) {
            $given = is_array($values[$group] ?? null) ? $values[$group] : [];
            foreach (self::CHANNELS[$group] as $channel) {
                if (array_key_exists($channel, $given)) {
                    $base[$group][$channel] = (bool) $given[$channel];
                }
            }
            if (in_array($group, self::SCOPED, true)) {
                $scope = $given['scope'] ?? $base[$group]['scope'];
                $base[$group]['scope'] = self::manager($role) && $scope === 'all' ? 'all' : 'mine';
            }
        }

        return $base;
    }

    /** El WhatsApp de la persona para los avisos: el suyo de «Mis avisos» o, si es vendedor, el de la ficha de vendedor. */
    public static function phone(string $userId, ?NoticePreference $saved = null): ?string
    {
        $saved ??= NoticePreference::query()->where('user_id', $userId)->first();
        $phone = $saved?->phone;
        if (is_string($phone) && trim($phone) !== '') {
            return trim($phone);
        }
        $seller = LeadSeller::query()->where('user_id', $userId)->where('active', true)->value('phone');

        return is_string($seller) && trim($seller) !== '' ? trim($seller) : null;
    }

    /**
     * Guarda «Mis avisos».
     *
     * @param  array<mixed>  $values
     */
    public static function save(Workspace $workspace, string $userId, ?WorkspaceRole $role, array $values, ?string $phone, bool $phoneGiven): NoticePreference
    {
        $row = NoticePreference::query()->firstOrNew(['user_id' => $userId], ['workspace_id' => $workspace->id]);
        $row->settings = self::merge(self::for($userId, $role, $row->exists ? $row : null), $values, $role);
        if ($phoneGiven) {
            $row->phone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;
        }
        $row->save();

        return $row;
    }
}
