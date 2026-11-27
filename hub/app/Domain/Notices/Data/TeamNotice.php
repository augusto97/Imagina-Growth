<?php

declare(strict_types=1);

namespace App\Domain\Notices\Data;

/**
 * Un aviso al equipo (lote 0.4.0, `NotifyTeam`): qué pasó, a quién le toca y dónde verlo. Viaja cifrado en la cola
 * (puede llevar el nombre del contacto).
 */
final class TeamNotice
{
    /**
     * @param  string  $kind  appointment.booked | appointment.rescheduled | appointment.cancelled | appointment.confirmed | lead.new | lead.hot | lead.qualified | conversation.assigned | connection.alert
     * @param  string  $group  appointments | leads | conversations | connections (NoticePreferences::GROUPS)
     * @param  string|null  $path  ruta dentro del espacio de trabajo (sin `/w/ws_…/`), p. ej. `agenda?fecha=2026-11-27&cita=ap_…`
     * @param  string  $dedupe  clave única del aviso: una persona lo recibe una sola vez aunque el trabajo se repita
     * @param  string|null  $logEvent  evento de `notice_log` (null: no se registra en la sección «Avisos»)
     * @param  list<string>  $userIds  personas a las que les toca por asignación (siempre se consideran)
     * @param  list<array{name: string, email: string}>  $emails  personas sin usuario del panel (solo correo)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $group,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $path,
        public readonly string $dedupe,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $logEvent = null,
        public readonly array $userIds = [],
        public readonly array $emails = [],
        public readonly ?string $excludeUserId = null,
        public readonly ?string $contactId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            kind: (string) $a['kind'], group: (string) $a['group'], title: (string) $a['title'],
            body: isset($a['body']) ? (string) $a['body'] : null, path: isset($a['path']) ? (string) $a['path'] : null,
            dedupe: (string) $a['dedupe'], subjectType: isset($a['subjectType']) ? (string) $a['subjectType'] : null,
            subjectId: isset($a['subjectId']) ? (string) $a['subjectId'] : null, logEvent: isset($a['logEvent']) ? (string) $a['logEvent'] : null,
            userIds: array_values(array_map('strval', (array) ($a['userIds'] ?? []))),
            emails: array_values(array_filter(array_map(
                fn ($e) => is_array($e) && isset($e['email']) ? ['name' => (string) ($e['name'] ?? ''), 'email' => (string) $e['email']] : null,
                (array) ($a['emails'] ?? []),
            ))),
            excludeUserId: isset($a['excludeUserId']) ? (string) $a['excludeUserId'] : null,
            contactId: isset($a['contactId']) ? (string) $a['contactId'] : null,
        );
    }
}
