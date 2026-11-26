<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Intelligence\Ai\Support\AiConnectionTest;
use App\Domain\Intelligence\Ai\Support\AiModels;
use App\Domain\Messaging\Support\SesEventSetup;
use App\Domain\Platform\Settings\PlatformSettings;
use App\Domain\Platform\Settings\PlatformSettingsPresenter;
use App\Domain\Platform\Settings\PlatformSettingsRegistry;
use App\Domain\Platform\Settings\PlatformSettingsRuntime;
use App\Domain\Platform\Settings\SettingDefinition;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Plataforma → Ajustes (F17, solo superadmin con 2FA): lo que antes se configuraba en el .env del servidor. Los
 * secretos nunca vuelven: solo si están configurados y, si son largos, sus últimos 4 caracteres. Cada cambio queda en
 * la auditoría con las claves, nunca con los valores, y llega a todos los procesos sin reiniciar nada.
 */
final class PlatformSettingsController
{
    public function __construct(
        private readonly PlatformSettings $store,
        private readonly PlatformSettingsRuntime $runtime,
        private readonly PlatformSettingsPresenter $presenter,
        private readonly RecordAuditLog $audit,
    ) {}

    /** GET /api/platform/settings */
    public function show(): JsonResponse
    {
        return $this->respond();
    }

    /**
     * PUT /api/platform/settings/{section} {values: {nombre: valor}}. Solo los que vienen cambian; null o vacío deja
     * el ajuste sin valor en el panel (vuelve al .env).
     */
    public function update(Request $request, string $section): JsonResponse
    {
        $definitions = $this->definitions($section);
        $input = $request->input('values');
        if (! is_array($input) || $input === []) {
            return response()->json(['message' => __('No hay cambios para guardar.'), 'errors' => ['values' => [__('No hay cambios para guardar.')]]], 422);
        }
        $unknown = array_diff(array_map('strval', array_keys($input)), array_keys($definitions));
        if ($unknown !== []) {
            return response()->json(['message' => __('Hay campos que no existen en esta sección.'), 'errors' => ['values' => [__('Hay campos que no existen en esta sección.')]]], 422);
        }

        $rules = [];
        $names = [];
        foreach ($input as $name => $_) {
            $definition = $definitions[(string) $name];
            $rules['values.'.$name] = ['nullable', ...self::rules($definition)];
            $names['values.'.$name] = __('el valor');
        }
        $data = Validator::make(['values' => array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $input)], $rules, [], $names)->validate();

        $values = [];
        foreach ((array) $data['values'] as $name => $value) {
            $definition = $definitions[(string) $name];
            $values[$definition->key] = $definition->cast($value);
        }
        if ($section === 'mail' && ($problem = self::smtpMismatch($values)) !== null) {
            return response()->json(['message' => $problem, 'errors' => ['values.smtp_encryption' => [$problem]]], 422);
        }
        if ($section === 'ai' && ($problem = $this->effortMismatch($values)) !== null) {
            return response()->json(['message' => $problem['message'], 'errors' => ["values.{$problem['field']}" => [$problem['message']]]], 422);
        }

        return $this->save($request, $values);
    }

    /**
     * El cifrado que no va con el puerto (lo que hace fallar el correo con «wrong version number»): SSL/TLS directo es
     * del 465; 25, 587 y 2525 hablan primero en claro y suben a STARTTLS. Mira lo que llega y, si no viene, lo guardado.
     *
     * @param  array<string, mixed>  $values
     */
    public static function smtpMismatch(array $values): ?string
    {
        $port = array_key_exists('mail.smtp_port', $values) ? $values['mail.smtp_port'] : config('mail.mailers.smtp.port');
        $encryption = array_key_exists('mail.smtp_encryption', $values)
            ? $values['mail.smtp_encryption']
            : (config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : (config('mail.mailers.smtp.auto_tls') === false ? 'none' : 'starttls'));
        if (! is_numeric($port) || ! is_string($encryption)) {
            return null;
        }
        $port = (int) $port;
        if ($encryption === 'ssl' && in_array($port, [25, 587, 2525], true)) {
            return __('Con el puerto :port el cifrado es STARTTLS: elige «STARTTLS» (o usa el puerto 465 con «SSL/TLS»).', ['port' => $port]);
        }
        if ($encryption === 'starttls' && $port === 465) {
            return __('Con el puerto 465 el cifrado es «SSL/TLS» (o usa el puerto 587 con «STARTTLS»).');
        }

        return null;
    }

    /**
     * POST /api/platform/settings/ai/test {model?, effort?}: una pregunta corta al modelo (por defecto el principal) con
     * la clave guardada, y en palabras qué pasó (AiConnectionTest). 200 si respondió, 422 si no.
     */
    public function aiTest(Request $request, AiConnectionTest $test): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9.-]+$/'],
            'effort' => ['nullable', 'string', Rule::in(AiModels::EFFORTS)],
        ]);
        $result = $test->run(isset($data['model']) ? (string) $data['model'] : null, isset($data['effort']) ? (string) $data['effort'] : null);
        $this->audit->handle('platform.ai_tested', null, ['model' => $result['model'], 'ok' => $result['ok'], 'reason' => $result['reason'] ?? null]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Un esfuerzo que el modelo elegido no admite (en un grupo o en una función): se dice cuáles admite. Mira lo que
     * llega y, si no viene, lo que rige ahora; vaciar el modelo vuelve al del .env.
     *
     * @param  array<string, mixed>  $values
     * @return array{field: string, message: string}|null
     */
    private function effortMismatch(array $values): ?array
    {
        $pairs = [['ai.main_model', 'ai.main_effort'], ['ai.fast_model', 'ai.fast_effort']];
        foreach (array_keys(AiModels::features()) as $feature) {
            $pairs[] = ["ai.feature_{$feature}_model", "ai.feature_{$feature}_effort"];
        }
        foreach ($pairs as [$modelKey, $effortKey]) {
            if (! array_key_exists($modelKey, $values) && ! array_key_exists($effortKey, $values)) {
                continue;
            }
            $model = $this->valueAfterSave($modelKey, $values);
            $effort = $this->valueAfterSave($effortKey, $values);
            if (! is_string($model) || ! is_string($effort) || AiModels::supportsEffort($model, $effort)) {
                continue;
            }
            $allowed = AiModels::catalog()[$model]['efforts'] ?? [];
            $message = $allowed === []
                ? __('El modelo :model no admite elegir el esfuerzo: deja «Esfuerzo» vacío.', ['model' => $model])
                : __('El modelo :model no admite el esfuerzo «:effort». Elige uno de estos: :allowed.', ['model' => $model, 'effort' => $effort, 'allowed' => implode(', ', $allowed)]);

            return ['field' => explode('.', $effortKey, 2)[1], 'message' => is_string($message) ? $message : ''];
        }

        return null;
    }

    /**
     * El valor de un ajuste tras guardar: el que llega, el del .env si se vacía o el que rige ahora si no viene.
     *
     * @param  array<string, mixed>  $values
     */
    private function valueAfterSave(string $key, array $values): mixed
    {
        $definition = PlatformSettingsRegistry::find($key);
        if ($definition === null) {
            return null;
        }
        if (array_key_exists($key, $values)) {
            return $values[$key] ?? $this->runtime->fallback($definition);
        }

        return config($definition->targets[0]);
    }

    /**
     * POST /api/platform/settings/push/vapid: crea el par de llaves de los avisos push y las guarda. La privada no se
     * muestra nunca; la pública es pública (la usan los navegadores).
     */
    public function vapid(Request $request): JsonResponse
    {
        $keys = VAPID::createVapidKeys();

        return $this->save($request, ['push.vapid_public_key' => $keys['publicKey'], 'push.vapid_private_key' => $keys['privateKey']], 'platform.settings_generated');
    }

    /** POST /api/platform/settings/ses/setup: deja listo el configuration set de SES y su destino de eventos (igr:ses:setup). */
    public function sesSetup(SesEventSetup $setup): JsonResponse
    {
        $result = $setup->ensure();
        $this->audit->handle('platform.ses_setup', null, ['status' => $result['status'], 'changed' => $result['changed']]);

        return response()->json(['result' => $result] + $this->presenter->present(), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $values */
    private function save(Request $request, array $values, string $action = 'platform.settings_updated'): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();
        $changes = $this->store->save($values, is_string($userId) ? $userId : null);
        if ($changes['set'] !== [] || $changes['cleared'] !== []) {
            // Solo las claves: nunca los valores (tampoco enmascarados).
            $this->audit->handle($action, null, ['set' => $changes['set'], 'cleared' => $changes['cleared']]);
            $this->runtime->refresh();
            $this->afterChange([...$changes['set'], ...$changes['cleared']]);
        }

        return $this->respond($changes);
    }

    /** @param list<string> $keys */
    private function afterChange(array $keys): void
    {
        // Con otro plan por defecto, los módulos guardados de las cuentas sin plan se recalculan (igr:plans:sync --refresh).
        if (in_array('security.default_plan', $keys, true)) {
            try {
                app(PlanResolver::class)->refreshAll();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param array{set: list<string>, cleared: list<string>}|null $changes */
    private function respond(?array $changes = null): JsonResponse
    {
        return response()->json($this->presenter->present() + ($changes === null ? [] : ['changed' => $changes]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, SettingDefinition> nombre dentro de la sección => definición */
    private function definitions(string $section): array
    {
        abort_unless(in_array($section, PlatformSettingsRegistry::SECTIONS, true), 404);
        $out = [];
        foreach (PlatformSettingsRegistry::section($section) as $definition) {
            $out[$definition->name()] = $definition;
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function rules(SettingDefinition $definition): array
    {
        $base = match ($definition->type) {
            'bool' => ['boolean'],
            'int' => ['integer'],
            'number' => ['numeric'],
            'enum' => $definition->options === [] ? ['string'] : ['string', Rule::in($definition->options)],
            'email' => ['email:rfc', 'max:255'],
            'url' => ['url:https', 'max:500'],
            'secret' => ['string', 'max:4096'],
            default => ['string', 'max:255'],
        };
        if ($definition->min !== null) {
            $base[] = 'min:'.$definition->min;
        }
        if ($definition->max !== null) {
            $base[] = 'max:'.$definition->max;
        }

        return [...$base, ...$definition->rules];
    }
}
