<?php

use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Messaging\Support\SesEventSetup;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * F5: el configuration set `igr-events` y su destino de eventos hacia SNS se crean solos (idempotente) al conectar o
 * probar un canal SES y con `igr:ses:setup`, con un mensaje claro si a IAM le falta un permiso; los envíos salen con él.
 */

uses(MessagingFixtures::class);

/**
 * SES simulado: cada operación responde lo que diga el guion (un arreglo es el resultado; un texto, el código de error
 * de AWS) y queda anotada.
 *
 * @param  array<string, array<string, mixed>|string>  $script
 */
function scriptedSes(array $script): ArrayObject
{
    $calls = new ArrayObject;
    app()->instance(SesClientFactory::class, new class($calls, $script) extends SesClientFactory
    {
        /** @param array<string, array<string, mixed>|string> $script */
        public function __construct(private ArrayObject $calls, private array $script) {}

        public function configured(): bool
        {
            return true;
        }

        public function make(): SesV2Client
        {
            $handler = new MockHandler;
            foreach (range(1, 20) as $_) {
                $handler->append(function (CommandInterface $cmd) {
                    $this->calls[] = ['name' => $cmd->getName()] + $cmd->toArray();
                    $answer = $this->script[$cmd->getName()] ?? ['MessageId' => 'ses-1'];

                    return is_string($answer) ? new AwsException($answer, $cmd, ['code' => $answer]) : new Result($answer);
                });
            }

            return new SesV2Client(['version' => '2019-09-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $handler]);
        }
    });

    return $calls;
}

function sesCallNames(ArrayObject $calls): array
{
    return array_map(fn (array $c) => $c['name'], $calls->getArrayCopy());
}

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->api = "/api/w/{$this->ws->publicId()}";
    $this->topic = 'arn:aws:sns:us-east-1:1:igr';
});

it('crea el configuration set y su destino hacia SNS la primera vez, y después no cambia nada', function () {
    $calls = scriptedSes(['GetConfigurationSetEventDestinations' => ['EventDestinations' => []]]);
    $this->artisan('igr:ses:setup')->expectsOutputToContain('igr-events')->assertSuccessful();
    expect(sesCallNames($calls))->toBe(['CreateConfigurationSet', 'GetConfigurationSetEventDestinations', 'CreateConfigurationSetEventDestination'])
        ->and($calls[0]['ConfigurationSetName'])->toBe('igr-events')
        ->and($calls[2]['EventDestinationName'])->toBe('igr-sns')
        ->and($calls[2]['EventDestination']['Enabled'])->toBeTrue()
        ->and($calls[2]['EventDestination']['SnsDestination']['TopicArn'])->toBe($this->topic)
        ->and($calls[2]['EventDestination']['MatchingEventTypes'])->toContain('BOUNCE', 'COMPLAINT', 'DELIVERY', 'OPEN')
        // Sin CLICK: SES reescribiría los enlaces que ya pasan por /c/.
        ->and($calls[2]['EventDestination']['MatchingEventTypes'])->not->toContain('CLICK')
        ->and(SesEventSetup::lastResult())->toMatchArray(['status' => 'ok', 'changed' => true]);

    // Ya existe todo igual: ni crea ni actualiza.
    $calls = scriptedSes([
        'CreateConfigurationSet' => 'AlreadyExistsException',
        'GetConfigurationSetEventDestinations' => ['EventDestinations' => [['Name' => 'igr-sns', 'Enabled' => true, 'MatchingEventTypes' => array_reverse(SesEventSetup::EVENTS), 'SnsDestination' => ['TopicArn' => $this->topic]]]],
    ]);
    expect(app(SesEventSetup::class)->ensure())->toMatchArray(['status' => 'ok', 'changed' => false])
        ->and(sesCallNames($calls))->toBe(['CreateConfigurationSet', 'GetConfigurationSetEventDestinations']);

    // Apunta a otro tema o está apagado: se corrige.
    $calls = scriptedSes([
        'CreateConfigurationSet' => 'AlreadyExistsException',
        'GetConfigurationSetEventDestinations' => ['EventDestinations' => [['Name' => 'igr-sns', 'Enabled' => false, 'MatchingEventTypes' => ['BOUNCE'], 'SnsDestination' => ['TopicArn' => 'arn:aws:sns:us-east-1:1:viejo']]]],
    ]);
    expect(app(SesEventSetup::class)->ensure())->toMatchArray(['status' => 'ok', 'changed' => true])
        ->and(sesCallNames($calls))->toBe(['CreateConfigurationSet', 'GetConfigurationSetEventDestinations', 'UpdateConfigurationSetEventDestination'])
        ->and($calls[2]['EventDestination']['SnsDestination']['TopicArn'])->toBe($this->topic);
});

it('si a IAM le falta un permiso lo dice con su nombre, y conectar el canal responde 422', function () {
    scriptedSes(['GetConfigurationSetEventDestinations' => ['EventDestinations' => []], 'CreateConfigurationSetEventDestination' => 'AccessDeniedException']);
    $this->artisan('igr:ses:setup')->expectsOutputToContain('ses:CreateConfigurationSetEventDestination')->assertFailed();
    expect(SesEventSetup::lastResult())->toMatchArray(['status' => 'error', 'permission' => 'ses:CreateConfigurationSetEventDestination']);

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/email", ['provider' => 'ses', 'from_email' => 'hola@pinturas.co', 'from_name' => 'Pinturas'])
        ->assertUnprocessable()->assertJsonValidationErrors('provider');
    expect($res->json('errors.provider.0'))->toContain('ses:CreateConfigurationSetEventDestination')->toContain('IAM');

    // igr:doctor lo muestra como error, con qué hacer.
    Artisan::call('igr:doctor', ['--json' => true]);
    $check = collect(json_decode(trim(Artisan::output()), true)['checks'])->firstWhere('key', 'email.ses_events');
    expect($check['status'])->toBe('error')->and($check['detail'])->toContain('Preparar ahora')->and($check['link'])->toBe('pestana=ajustes&seccion=ses');

    // Sin permiso para crear el configuration set pero se puede leer (lo creó alguien a mano): sirve.
    $calls = scriptedSes(['CreateConfigurationSet' => 'AccessDeniedException', 'GetConfigurationSet' => ['ConfigurationSetName' => 'igr-events'], 'GetConfigurationSetEventDestinations' => ['EventDestinations' => []]]);
    expect(app(SesEventSetup::class)->ensure()['status'])->toBe('ok')
        ->and(sesCallNames($calls))->toBe(['CreateConfigurationSet', 'GetConfigurationSet', 'GetConfigurationSetEventDestinations', 'CreateConfigurationSetEventDestination']);
    // Ni crear ni leer: falta crear.
    scriptedSes(['CreateConfigurationSet' => 'AccessDeniedException', 'GetConfigurationSet' => 'AccessDeniedException']);
    expect(app(SesEventSetup::class)->ensure())->toMatchArray(['status' => 'error', 'permission' => 'ses:CreateConfigurationSet']);
});

it('al conectar un dominio por SES prepara antes el configuration set, y sin tema de SNS avisa pero conecta', function () {
    config(['igr-messaging.ses.sns_topic_arn' => null]);
    $calls = scriptedSes(['CreateEmailIdentity' => ['DkimAttributes' => ['Tokens' => ['t1', 't2', 't3'], 'Status' => 'PENDING']]]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/email", ['provider' => 'ses', 'from_email' => 'hola@pinturas.co', 'from_name' => 'Pinturas'])->assertCreated();
    expect($res->json('ses_events.status'))->toBe('no_topic')->and($res->json('ses_events.message'))->toContain('tema de SNS')->toContain('Plataforma → Ajustes → Amazon SES')
        // El configuration set primero: la identidad del dominio se crea con él.
        ->and(sesCallNames($calls))->toBe(['CreateConfigurationSet', 'CreateEmailIdentity'])
        ->and($calls[1]['ConfigurationSetName'])->toBe('igr-events');
    Artisan::call('igr:doctor', ['--json' => true]);
    expect(collect(json_decode(trim(Artisan::output()), true)['checks'])->firstWhere('key', 'email.ses_events')['status'])->toBe('warning');
});

it('probar un canal SES lo deja listo y el correo de prueba sale con el configuration set', function () {
    $calls = scriptedSes(['GetConfigurationSetEventDestinations' => ['EventDestinations' => []]]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$this->email->publicId()}/test", ['to' => 'prueba@pinturas.co'])->assertOk();
    expect($res->json('status'))->toBe('queued')->and($res->json('ses_events.status'))->toBe('ok');
    $names = sesCallNames($calls);
    expect(array_slice($names, 0, 3))->toBe(['CreateConfigurationSet', 'GetConfigurationSetEventDestinations', 'CreateConfigurationSetEventDestination'])
        ->and($names)->toContain('SendEmail');
    $send = collect($calls->getArrayCopy())->firstWhere('name', 'SendEmail');
    expect($send['ConfigurationSetName'])->toBe('igr-events');

    // Si no se puede preparar, la prueba no se encola y dice por qué.
    scriptedSes(['CreateConfigurationSet' => 'AccessDeniedException', 'GetConfigurationSet' => 'AccessDeniedException']);
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$this->email->publicId()}/test", ['to' => 'prueba@pinturas.co'])
        ->assertUnprocessable()->assertJsonValidationErrors('to');
});
