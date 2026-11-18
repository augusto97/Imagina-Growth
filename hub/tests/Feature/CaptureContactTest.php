<?php

use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

// Captura en el sitio (M12, M15, M16): contacto y consentimiento con evidencia.
beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    $this->capture = fn (CaptureInput $in) => app(CurrentWorkspace::class)->run($this->ws, fn () => app(CaptureContact::class)->handle($this->ws, $this->site, $in));
});

it('crea el contacto con su navegador y registra solo los canales marcados, con evidencia', function () {
    $r = ($this->capture)(new CaptureInput('widget:wg_1', email: 'ana@ejemplo.com', phone: '300 123 4567', firstName: 'Ana', anonymousId: 'anon-pop',
        consents: ['whatsapp' => true, 'email' => false, 'sms' => true], pageUrl: 'https://tienda.co/', ip: '181.50.1.2', userAgent: 'Mozilla'));
    expect($r->wasKnown)->toBeFalse()->and($r->granted)->toBe(['whatsapp', 'sms'])->and($r->contact->first_name)->toBe('Ana');
    app(CurrentWorkspace::class)->run($this->ws, function () use ($r) {
        expect(ContactIdentity::query()->where('type', 'anonymous_id')->where('value', 'anon-pop')->value('contact_id'))->toBe($r->contact->id);
    });
    $event = DB::table('consent_events')->where('contact_id', $r->contact->id)->where('channel', 'whatsapp')->first();
    expect($event->source)->toBe('widget:wg_1')->and(json_decode($event->evidence, true))->toHaveKeys(['page_url', 'ip_hash', 'ua_hash'])
        ->and($event->evidence)->not->toContain('181.50.1.2');
});

it('reconoce al contacto que ya existía, no consiente un canal sin dirección y deja el correo pendiente con doble opt-in', function () {
    $first = ($this->capture)(new CaptureInput('widget:wg_1', phone: '3001234567', consents: ['whatsapp' => true]));
    $again = ($this->capture)(new CaptureInput('widget:wg_2', email: 'ana@ejemplo.com', phone: '+57 300 123 4567', consents: ['email' => true], doubleOptInEmail: true));
    expect($again->wasKnown)->toBeTrue()->and($again->contact->id)->toBe($first->contact->id)
        ->and($again->granted)->toBe([])->and($again->pendingDoubleOptIn)->toBe(['email']);
    expect(DB::table('contact_consents')->where('contact_id', $first->contact->id)->where('channel', 'email')->value('status'))->toBe('pending_double_optin');

    $noEmail = ($this->capture)(new CaptureInput('widget:wg_3', phone: '3109998877', consents: ['email' => true]));
    expect($noEmail->granted)->toBe([]);
    expect(fn () => ($this->capture)(new CaptureInput('widget:wg_4', email: 'no-es-correo')))->toThrow(InvalidArgumentException::class);
});
