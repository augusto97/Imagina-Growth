<?php

declare(strict_types=1);

namespace App\Domain\Backups\Http;

use App\Domain\Backups\Support\BackupRules;
use App\Domain\Backups\Support\BackupSchedule;
use App\Domain\Backups\Support\BackupState;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plataforma → Copias de seguridad (docs/despliegue.md §6). Solo superadmin con 2FA (EnsurePlatformAdmin).
 *
 * El panel **no** toca el servidor: deja un pedido en `shared/state/backup-request.json` y lo atiende
 * `growth-backup-dispatch` (root), que vuelve a validar todo, guarda el destino en `/etc/growth` (0600) y publica el
 * estado sin secretos. La contraseña del Storage Box y la frase de cifrado solo pasan por el pedido: nunca se
 * guardan del lado de la app ni salen en las respuestas o en la auditoría.
 */
final class BackupController
{
    public function __construct(
        private readonly BackupState $state,
        private readonly BackupSchedule $schedule,
    ) {}

    /** GET /api/platform/backups */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** PUT /api/platform/backups/settings: destino, retención, heartbeats y secretos; pide la contraseña del panel. */
    public function saveSettings(Request $request, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:'.BackupRules::HOST],
            'port' => ['required', 'integer', 'between:1,65535'],
            'user' => ['required', 'string', 'max:64', 'regex:'.BackupRules::USER],
            'path' => ['required', 'string', 'max:330', 'regex:'.BackupRules::PATH],
            'auth' => ['required', 'string', 'in:key,password'],
            'keep_days' => ['required', 'integer', 'between:1,365'],
            'heartbeats' => ['nullable', 'array:mariadb,clickhouse,verify'],
            'heartbeats.*' => ['nullable', 'string', 'max:300', 'regex:'.BackupRules::HEARTBEAT],
            'clear_heartbeats' => ['nullable', 'array'],
            'clear_heartbeats.*' => ['string', 'in:'.implode(',', BackupState::JOBS)],
            'current_password' => ['required', 'string', 'max:200'],
        ]);
        // Los secretos se leen del cuerpo tal cual llegó: el recorte de espacios de Laravel cambiaría la frase de
        // cifrado sin avisar, y sin la frase exacta las copias no se pueden leer.
        $password = $this->rawSecret($request, 'password');
        $encryption = $this->rawSecret($request, 'encryption_password');
        $this->validateSecret('password', $password, 1);
        $this->validateSecret('encryption_password', $encryption, BackupRules::ENCRYPTION_MIN);

        $user = $this->confirmedAdmin($request, $data['current_password']);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $stored = $this->state->status()['secrets'] ?? [];
        if ($data['auth'] === 'password' && $password === null && ! (bool) ($stored['password'] ?? false)) {
            throw ValidationException::withMessages(['password' => __('Escribe la contraseña del Storage Box.')]);
        }
        if ($encryption === null && ! (bool) ($stored['encryption'] ?? false)) {
            throw ValidationException::withMessages(['encryption_password' => __('Escribe una frase de cifrado para las copias y guárdala en un lugar seguro.')]);
        }
        if ($this->state->busy()) {
            return $this->busyResponse();
        }

        // Heartbeats: una URL la pone, "" la quita (clear_heartbeats) y null deja la que había.
        $heartbeats = [];
        $clear = (array) ($data['clear_heartbeats'] ?? []);
        foreach (BackupState::JOBS as $job) {
            $url = $data['heartbeats'][$job] ?? null;
            $heartbeats[$job] = in_array($job, $clear, true) ? '' : (is_string($url) && $url !== '' ? $url : null);
        }

        $id = $this->send($user, 'configure', [
            'config' => [
                'host' => $data['host'],
                'port' => (int) $data['port'],
                'user' => $data['user'],
                'path' => $data['path'],
                'auth' => $data['auth'],
                'keep_days' => (int) $data['keep_days'],
                'heartbeats' => $heartbeats,
            ],
            'secrets' => [
                'password' => $data['auth'] === 'password' ? $password : null,
                'encryption_password' => $encryption,
            ],
        ]);
        $audit->handle('platform.backups_configured', null, [
            'request' => $id,
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'user' => $data['user'],
            'path' => $data['path'],
            'auth' => $data['auth'],
            'keep_days' => (int) $data['keep_days'],
            'password_changed' => $data['auth'] === 'password' && $password !== null,
            'encryption_changed' => $encryption !== null,
            'heartbeats' => array_map(fn (?string $v): string => $v === null ? 'keep' : ($v === '' ? 'removed' : 'set'), $heartbeats),
        ], null, null, (string) $user->getKey());

        return response()->json($this->payload(), 202);
    }

    /** POST /api/platform/backups/test */
    public function test(Request $request, RecordAuditLog $audit): JsonResponse
    {
        return $this->simple($request, $audit, 'test', 'platform.backups_test_requested');
    }

    /** POST /api/platform/backups/run: una copia ahora (MariaDB y después ClickHouse). */
    public function run(Request $request, RecordAuditLog $audit): JsonResponse
    {
        return $this->simple($request, $audit, 'backup_now', 'platform.backups_run_requested');
    }

    /** POST /api/platform/backups/refresh: que root vuelva a publicar el estado. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        if ($this->state->busy()) {
            return $this->busyResponse();
        }
        $this->send($user, 'refresh');

        return response()->json($this->payload(), 202);
    }

    /** POST /api/platform/backups/install-key {password, current_password}: la contraseña del Storage Box, una vez. */
    public function installKey(Request $request, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:200']]);
        $password = $this->rawSecret($request, 'password');
        if ($password === null) {
            throw ValidationException::withMessages(['password' => __('Escribe la contraseña del Storage Box.')]);
        }
        $this->validateSecret('password', $password, 1);
        $user = $this->confirmedAdmin($request, $data['current_password']);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $destination = $this->state->status()['destination'] ?? null;
        if (! is_array($destination)) {
            return response()->json(['error' => 'not_configured', 'message' => __('Primero guarda el destino de las copias.')], 422);
        }
        if (($destination['auth'] ?? null) !== 'key') {
            return response()->json(['error' => 'auth_not_key', 'message' => __('El destino entra con contraseña: cámbialo a llave SSH para instalarla.')], 422);
        }
        if ($this->state->busy()) {
            return $this->busyResponse();
        }
        $id = $this->send($user, 'install_key', ['secrets' => ['password' => $password]]);
        $audit->handle('platform.backups_key_install_requested', null, ['request' => $id], null, null, (string) $user->getKey());

        return response()->json($this->payload(), 202);
    }

    private function simple(Request $request, RecordAuditLog $audit, string $action, string $auditAction): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        if (! (bool) ($this->state->status()['configured'] ?? false)) {
            return response()->json(['error' => 'not_configured', 'message' => __('Primero guarda el destino de las copias.')], 422);
        }
        if ($this->state->busy()) {
            return $this->busyResponse();
        }
        $id = $this->send($user, $action);
        $audit->handle($auditAction, null, ['request' => $id], null, null, (string) $user->getKey());

        return response()->json($this->payload(), 202);
    }

    /** @param  array<string, mixed>  $extra */
    private function send(User $user, string $action, array $extra = []): string
    {
        $id = 'bkp_'.Str::random(24);
        $this->state->writeRequest([
            'id' => $id,
            'action' => $action,
            'requested_by' => $user->publicId(),
            'requested_at' => now()->toIso8601String(),
            ...$extra,
        ]);

        return $id;
    }

    private function confirmedAdmin(Request $request, string $password): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! Hash::check($password, (string) $user->getAuthPassword())) {
            return response()->json(['error' => 'invalid_password', 'message' => __('La contraseña no es correcta.')], 422);
        }

        return $user;
    }

    private function busyResponse(): JsonResponse
    {
        return response()->json(['error' => 'backup_request_in_progress', 'message' => __('El servidor está atendiendo otro pedido de las copias. Espera unos segundos.')], 409);
    }

    /** El secreto como llegó en el JSON (sin recortes); null si no vino o vino vacío. */
    private function rawSecret(Request $request, string $key): ?string
    {
        $body = json_decode((string) $request->getContent(), true);
        $value = is_array($body) ? ($body[$key] ?? null) : $request->input($key);
        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages([$key => __('validation.string', ['attribute' => __("validation.attributes.{$key}")])]);
        }

        return $value === null || $value === '' ? null : $value;
    }

    private function validateSecret(string $key, ?string $value, int $min): void
    {
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) < $min || mb_strlen($value) > BackupRules::SECRET_MAX) {
            throw ValidationException::withMessages([$key => $key === 'encryption_password'
                ? __('La frase de cifrado debe tener entre :min y :max caracteres.', ['min' => $min, 'max' => BackupRules::SECRET_MAX])
                : __('La contraseña debe tener como mucho :max caracteres.', ['max' => BackupRules::SECRET_MAX])]);
        }
        if (preg_match(BackupRules::NO_CONTROL, $value) !== 1) {
            throw ValidationException::withMessages([$key => __('No uses saltos de línea ni tabuladores.')]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $status = $this->state->status();
        $jobs = [];
        foreach (BackupState::JOBS as $job) {
            $jobs[$job] = $this->job($this->state->job($job));
        }

        return [
            'answered' => $status !== null,
            'updated_at' => $this->str($status['updated_at'] ?? null),
            'configured' => (bool) ($status['configured'] ?? false),
            'managed' => (bool) ($status['managed'] ?? false),
            'manual_remote' => $this->str($status['manual_remote'] ?? null),
            'destination' => $this->destination($status['destination'] ?? null),
            'secrets' => [
                'password' => (bool) ($status['secrets']['password'] ?? false),
                'encryption' => (bool) ($status['secrets']['encryption'] ?? false),
            ],
            'heartbeats' => array_combine(BackupState::JOBS, array_map(
                fn (string $job): bool => (bool) ($status['heartbeats'][$job] ?? false),
                BackupState::JOBS,
            )),
            'timers_enabled' => isset($status['timers_enabled']) ? (bool) $status['timers_enabled'] : null,
            'ssh_key' => is_array($status['ssh_key'] ?? null) && is_string($status['ssh_key']['public'] ?? null) ? [
                'public' => $status['ssh_key']['public'],
                'fingerprint' => $this->str($status['ssh_key']['fingerprint'] ?? null),
                'created_at' => $this->str($status['ssh_key']['created_at'] ?? null),
            ] : null,
            'host_key' => is_array($status['host_key'] ?? null) ? ['fingerprint' => $this->str($status['host_key']['fingerprint'] ?? null)] : null,
            'last_request' => $this->pick($status['last_request'] ?? null, ['id', 'action', 'requested_by', 'state', 'code', 'message', 'started_at', 'finished_at']),
            'last_test' => $this->lastTest($status['last_test'] ?? null),
            'jobs' => $jobs,
            'schedule' => $this->schedule->next(),
            'pending' => $this->state->pending(),
            'busy' => $this->state->busy(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function destination(mixed $d): ?array
    {
        if (! is_array($d)) {
            return null;
        }

        return [
            'host' => $this->str($d['host'] ?? null),
            'port' => is_int($d['port'] ?? null) ? $d['port'] : null,
            'user' => $this->str($d['user'] ?? null),
            'path' => $this->str($d['path'] ?? null),
            'auth' => ($d['auth'] ?? null) === 'password' ? 'password' : 'key',
            'keep_days' => is_int($d['keep_days'] ?? null) ? $d['keep_days'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastTest(mixed $t): ?array
    {
        if (! is_array($t)) {
            return null;
        }

        return [
            'ok' => (bool) ($t['ok'] ?? false),
            'code' => $this->str($t['code'] ?? null),
            'message' => $this->str($t['message'] ?? null),
            'at' => $this->str($t['at'] ?? null),
            'free_bytes' => is_int($t['free_bytes'] ?? null) ? $t['free_bytes'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $j
     * @return array<string, mixed>|null
     */
    private function job(?array $j): ?array
    {
        if ($j === null) {
            return null;
        }
        $last = is_array($j['last_success'] ?? null) ? $j['last_success'] : null;

        return [
            'state' => in_array($j['state'] ?? null, ['queued', 'running', 'succeeded', 'failed'], true) ? $j['state'] : null,
            'started_at' => $this->str($j['started_at'] ?? null),
            'finished_at' => $this->str($j['finished_at'] ?? null),
            'name' => $this->str($j['name'] ?? null),
            'size_bytes' => is_int($j['size_bytes'] ?? null) ? $j['size_bytes'] : null,
            'code' => $this->str($j['code'] ?? null),
            'message' => $this->str($j['message'] ?? null),
            'last_success' => $last === null ? null : [
                'finished_at' => $this->str($last['finished_at'] ?? null),
                'name' => $this->str($last['name'] ?? null),
                'size_bytes' => is_int($last['size_bytes'] ?? null) ? $last['size_bytes'] : null,
            ],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>|null
     */
    private function pick(mixed $value, array $keys): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->str($value[$key] ?? null);
        }

        return $out;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 2000) : null;
    }
}
