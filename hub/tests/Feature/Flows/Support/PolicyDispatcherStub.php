<?php

namespace Tests\Feature\Flows\Support;

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Despachador de prueba con la semántica del real (hub/CLAUDE.md §8): misma
 * clave → `duplicate`; antes de encolar pregunta a la SendPolicy REAL (ventana
 * legal, festivos, frecuencia, consentimiento) y respeta lo que diga. Con
 * `crashAfterQueue` simula una caída después de que el mensaje quedó en cola.
 */
final class PolicyDispatcherStub implements MessageDispatcher
{
    /** @var array<string, array{id: string, message: OutboundMessage, at: CarbonImmutable}> */
    public array $sent = [];

    public int $crashAfterQueue = 0;

    public static function install(): self
    {
        $stub = new self;
        app()->instance(MessageDispatcher::class, $stub);

        return $stub;
    }

    public function dispatch(OutboundMessage $message): DispatchResult
    {
        $key = (string) $message->idempotencyKey;
        if (isset($this->sent[$key])) {
            return DispatchResult::duplicate($this->sent[$key]['id']);
        }
        $workspace = Workspace::query()->findOrFail($message->workspaceId);
        $contact = $message->contactId !== null ? Contact::query()->find($message->contactId) : null;
        $decision = app(SendPolicy::class)->checkAndReserve(new PolicyRequest(
            $workspace, $message->to !== null ? null : $contact, $message->channel, $message->kind, $message->sourceType,
            at: CarbonImmutable::now(), isTest: $message->isTest, hasExplicitRecipient: $message->to !== null,
        ));
        if ($decision->deferred()) {
            return DispatchResult::deferred($decision->until ?? CarbonImmutable::now()->addHour(), (string) $decision->reason);
        }
        if ($decision->denied()) {
            return DispatchResult::denied((string) $decision->reason);
        }
        $id = strtoupper((string) Str::ulid());
        $this->sent[$key] = ['id' => $id, 'message' => $message, 'at' => CarbonImmutable::now()];
        if ($this->crashAfterQueue > 0) {
            $this->crashAfterQueue--;

            throw new RuntimeException('Caída simulada después de encolar');
        }

        return DispatchResult::queued($id);
    }

    /** @return list<string> horas locales de Bogotá de cada envío */
    public function sentAtBogota(): array
    {
        return array_values(array_map(fn (array $s) => $s['at']->setTimezone('America/Bogota')->format('Y-m-d H:i'), $this->sent));
    }
}
