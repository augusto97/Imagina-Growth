<?php

use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], ['identity' => ['anonymous_id' => 'anon-ana']]),
    ]);
    $this->runWorker();
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
});

function bannerAdsView($test, array $consent, string $at): void
{
    $test->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $test->site->public_key, 'events' => [
        $test->envelope($test->site, 'page.viewed', ['page_type' => 'home'], ['schema_version' => 4, 'source' => ['type' => 'browser'], 'occurred_at' => $at,
            'identity' => ['anonymous_id' => 'anon-ana'], 'context' => ['page' => ['url' => 'https://tienda.co/', 'path' => '/'], 'consent' => $consent]]),
    ]]))->assertStatus(202);
    $test->runWorker();
}

function bannerAdsState($test): ?string
{
    return DB::table('contact_consents')->where('contact_id', $test->contact->id)->where('channel', 'ads')->value('status');
}

it('la decisión del banner sobre marketing de un visitante identificado es su consentimiento para anuncios', function () {
    bannerAdsView($this, ['analytics' => true, 'marketing' => true, 'source' => 'none'], now()->addMinute()->toIso8601String());
    expect(bannerAdsState($this))->toBeNull(); // sin banner real no hay decisión

    bannerAdsView($this, ['analytics' => true, 'marketing' => true, 'source' => 'complianz'], now()->addMinutes(2)->toIso8601String());
    expect(bannerAdsState($this))->toBe('granted')
        ->and(DB::table('contact_consents')->where('contact_id', $this->contact->id)->where('channel', 'ads')->value('source'))->toBe('banner:complianz');

    bannerAdsView($this, ['analytics' => true, 'marketing' => false, 'source' => 'complianz'], now()->addMinutes(3)->toIso8601String());
    expect(bannerAdsState($this))->toBe('revoked')
        ->and(DB::table('consent_events')->where('contact_id', $this->contact->id)->where('channel', 'ads')->count())->toBe(2);
});
