<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('igr:plans:sync')->assertSuccessful();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
});

it('con la cuenta suspendida el despachador frena solo el marketing', function () {
    ($this->in)(fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'smtp', 'display_name' => 'Correo', 'status' => 'active', 'is_default' => true]));
    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'email' => 'ana@ejemplo.com']));
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['billing_status' => BillingStatus::SUSPENDED]);

    $send = fn (MessageKind $kind, string $key) => ($this->in)(fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        workspaceId: $this->ws->id, contactId: $contact->id, channel: Channel::Email, kind: $kind, sourceType: 'system', idempotencyKey: $key,
        context: ['subject' => 'Hola', 'text' => 'Hola'],
    )));
    $marketing = $send(MessageKind::Marketing, 'billing:test:mkt');
    expect($marketing->status)->toBe('denied')->and($marketing->reason)->toBe('billing_suspended');
    expect($send(MessageKind::Transactional, 'billing:test:tx')->reason)->not->toBe('billing_suspended');
});

it('con la cuenta suspendida no se importan contactos', function () {
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['billing_status' => BillingStatus::SUSPENDED]);
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/contacts/imports", [])
        ->assertStatus(423)->assertJsonPath('error.code', 'billing_suspended');
});

it('el tope de IA sale del plan, y sin el módulo de IA es cero', function () {
    expect(app(AiMeter::class)->budget($this->ws->id))->toBe((float) config('igr-ai.monthly_budget_usd'));
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_inicio']);
    app(PlanResolver::class)->refresh($this->ws->fresh());
    $limit = app(Entitlements::class)->limit($this->ws->fresh(), 'ai_budget_usd');
    expect(app(AiMeter::class)->budget($this->ws->id))->toBe((float) $limit);
});
