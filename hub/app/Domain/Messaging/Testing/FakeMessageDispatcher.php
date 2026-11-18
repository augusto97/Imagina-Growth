<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Testing;

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use Closure;
use Illuminate\Support\Str;

/**
 * Doble de pruebas de MessageDispatcher: guarda lo despachado y respeta la
 * idempotencia. `respondWith` permite simular aplazamientos y negaciones.
 *
 *     $fake = FakeMessageDispatcher::install();
 *     $fake->respondWith(fn (OutboundMessage $m) => DispatchResult::deferred(now()->addHour()->toImmutable(), 'quiet_hours'));
 */
final class FakeMessageDispatcher implements MessageDispatcher
{
    /** @var list<OutboundMessage> */
    public array $dispatched = [];

    /** @var array<string, string> */
    private array $byKey = [];

    private ?Closure $responder = null;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(MessageDispatcher::class, $fake);

        return $fake;
    }

    public function respondWith(?Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function dispatch(OutboundMessage $message): DispatchResult
    {
        if ($message->idempotencyKey !== null && isset($this->byKey[$message->idempotencyKey])) {
            return DispatchResult::duplicate($this->byKey[$message->idempotencyKey]);
        }
        $result = $this->responder !== null ? ($this->responder)($message) : null;
        if ($result instanceof DispatchResult && $result->status !== DispatchResult::QUEUED) {
            return $result;
        }
        $this->dispatched[] = $message;
        $id = $result instanceof DispatchResult && $result->messageId !== null ? $result->messageId : strtoupper((string) Str::ulid());
        if ($message->idempotencyKey !== null) {
            $this->byKey[$message->idempotencyKey] = $id;
        }

        return DispatchResult::queued($id);
    }

    /** @return list<OutboundMessage> */
    public function to(string $contactId): array
    {
        return array_values(array_filter($this->dispatched, fn (OutboundMessage $m) => $m->contactId === $contactId));
    }
}
