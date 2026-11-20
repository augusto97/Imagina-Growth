<?php

use App\Domain\Bookings\Google\BusyTimes;
use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Connections\Models\OAuthConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Connections\Support\ConnectionFixtures;
use Tests\Feature\Connections\Support\GoogleScopes;

require_once __DIR__.'/../Bookings/BookingFixtures.php';

/*
 * Google Calendar de la agenda (M18) en el módulo común de conexiones: con la app de Google de la plataforma,
 * cada persona autoriza con el mismo flujo (state de un solo uso y PKCE) y las conexiones de antes siguen
 * funcionando con su cliente propio.
 */

uses(ConnectionFixtures::class);

beforeEach(function () {
    $this->setUpConnections();
});

it('con la app de Google de la plataforma, la persona de la agenda conecta su calendario con el flujo común', function () {
    $laura = bookingStaff($this->ws);
    $url = (string) $this->actingAs($this->owner)->postJson("{$this->api}/bookings/staff/{$laura->publicId()}/google")->assertOk()->json('url');
    $q = $this->urlQuery($url);
    expect($q)->toMatchArray(['client_id' => 'google-client', 'redirect_uri' => 'https://growth.test/oauth/google/callback', 'code_challenge_method' => 'S256'])
        ->and($q['scope'])->toContain('calendar.events')->not->toContain('adwords');

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.cal', 'refresh_token' => 'rt-cal', 'expires_in' => 3599, 'scope' => GoogleScopes::CALENDAR, 'id_token' => $this->idToken('laura@pinturas.co')])]);
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => '4/cal']))
        ->assertRedirect("https://growth.test/w/{$this->ws->publicId()}/agenda?pestana=personas&google=ok");

    $connection = OAuthConnection::withoutWorkspaceScope()->where('subject', 'staff:'.$laura->id)->firstOrFail();
    $laura = bookingIn($this->ws, fn () => BookingStaff::query()->findOrFail($laura->id));
    expect($laura->calendarConnected())->toBeTrue()->and($laura->calendar)->toMatchArray(['connection_id' => $connection->id, 'email' => 'laura@pinturas.co'])
        ->and($laura->calendar)->not->toHaveKey('refresh_token');
    // No aparece como la conexión de Google del espacio de trabajo.
    $google = collect($this->actingAs($this->owner)->getJson("{$this->api}/connections")->json('providers'))->firstWhere('key', 'google');
    expect($google['connections'])->toBe([]);

    // Lo ocupado sale con el token de la conexión, renovado cuando vence.
    $this->travel(2)->hours();
    $this->refake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.cal2', 'expires_in' => 3599, 'scope' => GoogleScopes::CALENDAR]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => '2026-12-01T15:00:00Z', 'end' => '2026-12-01T16:00:00Z']]]]]),
        'oauth2.googleapis.com/revoke' => Http::response([]),
    ]);
    $busy = app(GoogleCalendar::class)->busy($laura, CarbonImmutable::parse('2026-12-01T00:00:00Z'), CarbonImmutable::parse('2026-12-02T00:00:00Z'));
    expect($busy)->toHaveCount(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'freeBusy') && $r->hasHeader('Authorization', 'Bearer ya29.cal2'));

    // Desconectar desde la agenda revoca la conexión.
    $this->actingAs($this->owner)->deleteJson("{$this->api}/bookings/staff/{$laura->publicId()}/google")->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === 'rt-cal');
    expect($connection->fresh()?->status)->toBe('revoked')
        ->and(bookingIn($this->ws, fn () => BookingStaff::query()->findOrFail($laura->id))->calendarConnected())->toBeFalse();
});

it('las conexiones de calendario de antes siguen funcionando con su cliente propio', function () {
    config(['igr-bookings.google.client_id' => 'cliente-viejo', 'igr-bookings.google.client_secret' => 'secreto-viejo']);
    $laura = bookingStaff($this->ws, ['calendar' => ['access_token' => 'viejo', 'refresh_token' => 'rt-viejo', 'expires_at' => now()->getTimestamp() - 10, 'calendar_id' => 'primary']]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'renovado', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
    ]);
    app(BusyTimes::class)->forStaff($laura, CarbonImmutable::parse('2026-12-01T00:00:00Z'), CarbonImmutable::parse('2026-12-02T00:00:00Z'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['client_id'] === 'cliente-viejo' && $r['refresh_token'] === 'rt-viejo');
    expect(OAuthConnection::withoutWorkspaceScope()->count())->toBe(0);
});

it('quien no administra la agenda no puede terminar la conexión del calendario', function () {
    $laura = bookingStaff($this->ws);
    $q = $this->urlQuery((string) $this->actingAs($this->owner)->postJson("{$this->api}/bookings/staff/{$laura->publicId()}/google")->json('url'));
    // La dueña pierde el acceso al espacio de trabajo antes de volver de Google.
    $this->ws->users()->detach($this->owner->id);
    Http::fake();
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'x']))
        ->assertRedirect("https://growth.test/w/{$this->ws->publicId()}/agenda?pestana=personas&google=error");
    Http::assertNothingSent();
});
