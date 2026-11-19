<?php

use App\Domain\Agency\Domains\CustomDomains;
use App\Domain\Agency\Domains\DnsResolver;
use App\Domain\Agency\Models\AccountBranding;
use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Workspaces\Contracts\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Agency\AgencyWorld;

/** DNS de mentira: lo que la prueba diga. */
final class AgencyFakeDns implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<string>> */
    public array $cname = [];

    public function txt(string $name): array
    {
        return $this->txt[$name] ?? [];
    }

    public function cname(string $name): array
    {
        return $this->cname[$name] ?? [];
    }
}

beforeEach(function () {
    $this->w = AgencyWorld::make($this);
    $this->dns = new AgencyFakeDns;
    $this->app->instance(DnsResolver::class, $this->dns);
    // Un servidor que separa el destino del CNAME (por defecto es el dominio del panel, ver la prueba siguiente).
    config(['igr.custom_domains.cname_target' => 'domains.growth.imagina.cloud']);
});

it('sin configuración, los dominios propios apuntan con CNAME al dominio del panel (APP_URL)', function () {
    config(['igr.custom_domains.cname_target' => parse_url((string) config('app.url'), PHP_URL_HOST)]);
    expect(CustomDomains::cnameTarget())->toBe('localhost');
    $config = require base_path('config/igr.php');
    expect($config['custom_domains']['cname_target'])->toBe(parse_url((string) env('APP_URL'), PHP_URL_HOST));
});

it('solo el dueño de la agencia cambia la marca; todos los clientes de la agencia la usan', function () {
    $w = $this->w;
    $acc = 'acc_'.$w->agency->id;
    $body = ['brand_name' => 'Imagina La Web', 'primary_color' => '#0f766e', 'sender_name' => 'Imagina', 'sender_email' => 'hola@imaginalaweb.co', 'support_email' => 'soporte@imaginalaweb.co', 'white_label' => true];
    $this->actingAs($w->member)->putJson($w->api($w->a, "agency/branding/{$acc}"), $body)->assertForbidden();
    $this->actingAs($w->owner)->putJson($w->api($w->a, "agency/branding/{$acc}"), ['primary_color' => 'rojo'])->assertUnprocessable();
    $this->actingAs($w->owner)->putJson($w->api($w->a, "agency/branding/{$acc}"), $body)->assertOk();

    $brand = app(Branding::class)->for($w->b);
    expect($brand->name)->toBe('Imagina La Web')->and($brand->primaryColor)->toBe('#0F766E')->and($brand->whiteLabel)->toBeTrue()
        ->and($brand->supportEmail)->toBe('soporte@imaginalaweb.co')
        ->and($brand->senderEmail)->not->toBe('hola@imaginalaweb.co'); // sin dominio verificado, el de la plataforma
    expect(app(Branding::class)->for($w->c)->name)->toBe('Imagina Growth'); // la otra agencia no cambia

    DB::table('channel_accounts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $w->b->id, 'channel' => 'email', 'provider' => 'ses',
        'display_name' => 'Correo', 'identity' => 'imaginalaweb.co', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->app->forgetScopedInstances();
    expect(app(Branding::class)->for($w->b)->senderEmail)->toBe('hola@imaginalaweb.co')
        ->and(app(Branding::class)->for($w->a)->senderEmail)->not->toBe('hola@imaginalaweb.co');

    // El panel del cliente (también su dueño directo) muestra la marca de la agencia.
    $this->actingAs($w->clientOwnerA)->getJson($w->api($w->a))->assertOk()->assertJsonPath('branding.name', 'Imagina La Web')->assertJsonPath('branding.white_label', true);
    $list = $this->actingAs($w->member)->getJson($w->api($w->a, 'agency/branding'))->assertOk();
    expect($list->json('accounts.0.branding.brand_name'))->toBe('Imagina La Web')->and($list->json('accounts.0.sender_verified_in'))->toBe(1)
        ->and($list->json('accounts.0.can_manage'))->toBeFalse();
});

it('sube y borra el logo (PNG o JPG)', function () {
    Storage::fake('public');
    $w = $this->w;
    $acc = 'acc_'.$w->agency->id;
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/branding/{$acc}/logo"), ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertUnprocessable();
    $url = $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/branding/{$acc}/logo"), ['logo' => UploadedFile::fake()->image('logo.png', 300, 80)])->assertOk()->json('logo_url');
    $path = AccountBranding::query()->findOrFail($w->agency->id)->logo_path;
    expect($url)->toContain('branding/')->and(Storage::disk('public')->exists((string) $path))->toBeTrue()
        ->and(app(Branding::class)->for($w->a)->logoUrl)->toBe($url);
    $this->actingAs($w->owner)->deleteJson($w->api($w->a, "agency/branding/{$acc}/logo"))->assertOk();
    expect(Storage::disk('public')->exists((string) $path))->toBeFalse();
});

it('dominio propio: registros DNS, verificación y certificado solo para los verificados', function () {
    $w = $this->w;
    $internal = ['REMOTE_ADDR' => '172.18.0.5'];
    $res = $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/domains'), ['account_id' => 'acc_'.$w->agency->id, 'host' => 'https://Panel.ImaginaLaWeb.co/', 'purpose' => 'panel'])
        ->assertCreated();
    $id = $res->json('domain.id');
    $records = collect($res->json('domain.records'))->keyBy('type');
    expect($res->json('domain.host'))->toBe('panel.imaginalaweb.co')->and($res->json('domain.status'))->toBe('pending')
        ->and($records['CNAME']['value'])->toBe('domains.growth.imagina.cloud')
        ->and($records['TXT']['name'])->toBe('_igr-verify.panel.imaginalaweb.co')
        ->and($records['TXT']['value'])->toStartWith('igr-verify=');

    $this->call('GET', '/internal/caddy/ask?domain=panel.imaginalaweb.co', [], [], [], $internal)->assertNotFound();
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/domains/{$id}/verify"))->assertOk()
        ->assertJsonPath('domain.status', 'failed')->assertJsonPath('domain.last_error', fn ($e) => str_contains($e, 'TXT'));

    $this->dns->txt['_igr-verify.panel.imaginalaweb.co'] = [$records['TXT']['value']];
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/domains/{$id}/verify"))->assertOk()->assertJsonPath('domain.last_error', fn ($e) => str_contains($e, 'CNAME'));
    $this->dns->cname['panel.imaginalaweb.co'] = ['Domains.Growth.Imagina.Cloud.'];
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/domains/{$id}/verify"))->assertOk()->assertJsonPath('domain.status', 'verified');

    $this->call('GET', '/internal/caddy/ask?domain=panel.imaginalaweb.co', [], [], [], $internal)->assertOk();
    $this->call('GET', '/internal/caddy/ask?domain=panel.imaginalaweb.co', [], [], [], ['REMOTE_ADDR' => '190.24.1.10'])->assertForbidden();
    expect(app(Branding::class)->for($w->a)->panelUrl)->toBe('https://panel.imaginalaweb.co')
        ->and(DB::table('audit_logs')->where('action', 'agency.domain_verified')->count())->toBe(1);

    // La pantalla de ingreso en ese dominio lleva la marca de la agencia; en otro, la de la plataforma.
    AccountBranding::query()->create(['account_id' => $w->agency->id, 'brand_name' => 'Imagina La Web', 'white_label' => true]);
    $this->getJson('http://panel.imaginalaweb.co/api/public/branding')->assertOk()->assertJsonPath('branding.name', 'Imagina La Web')->assertJsonPath('branding.white_label', true);
    $this->getJson('http://otro.example.co/api/public/branding')->assertOk()->assertJsonPath('branding.name', 'Imagina Growth');

    // Borrar el dominio le quita el certificado.
    $this->actingAs($w->member)->deleteJson($w->api($w->a, "agency/domains/{$id}"))->assertForbidden();
    $this->actingAs($w->owner)->deleteJson($w->api($w->a, "agency/domains/{$id}"))->assertOk();
    $this->call('GET', '/internal/caddy/ask?domain=panel.imaginalaweb.co', [], [], [], $internal)->assertNotFound();
});

it('rechaza dominios inválidos, de la plataforma, repetidos o de otra agencia', function () {
    $w = $this->w;
    $post = fn (array $body) => $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/domains'), $body + ['account_id' => 'acc_'.$w->agency->id, 'purpose' => 'links']);
    $post(['host' => 'no es un dominio'])->assertUnprocessable();
    $post(['host' => '10.0.0.1'])->assertUnprocessable();
    $post(['host' => 'x.domains.growth.imagina.cloud'])->assertUnprocessable();
    $post(['host' => 'links.cafesierra.co', 'workspace_id' => $w->b->publicId()])->assertCreated();
    $post(['host' => 'links.cafesierra.co'])->assertUnprocessable();
    $post(['host' => 'links.ajena.co', 'workspace_id' => $w->c->publicId()])->assertNotFound();

    $domain = CustomDomain::query()->firstOrFail();
    expect($domain->workspace_id)->toBe($w->b->id);
    $this->actingAs($w->foreignOwner)->postJson($w->api($w->c, 'agency/domains/'.$domain->publicId().'/verify'))->assertNotFound();
    $this->actingAs($w->foreignOwner)->postJson($w->api($w->c, 'agency/domains'), ['account_id' => 'acc_'.$w->agency->id, 'host' => 'x.co', 'purpose' => 'panel'])->assertNotFound();
});
