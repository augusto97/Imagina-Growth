<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de M07 para las pruebas: un espacio de trabajo de Colombia con una
 * cuenta de WhatsApp oficial y una de SES activas, un contacto con
 * consentimiento de marketing y un renderizador de plantillas de prueba
 * (el real lo implementa M08).
 *
 * @property User $owner
 * @property Workspace $ws
 * @property Contact $contact
 * @property ChannelAccount $wa
 * @property ChannelAccount $email
 */
trait MessagingFixtures
{
    public function setUpMessaging(): void
    {
        $this->useIngestInfrastructure();
        config([
            'igr-messaging.links_url' => 'https://go.test',
            'igr-messaging.meta.graph_url' => 'https://graph.test',
            'igr-messaging.meta.app_secret' => 'meta-app-secret',
            'igr-messaging.meta.webhook_verify_token' => 'verificame',
            // Solo se aceptan avisos de SES del tema configurado (S6).
            'igr-messaging.ses.sns_topic_arn' => 'arn:aws:sns:us-east-1:1:igr',
        ]);
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
        [$this->contact, $this->wa, $this->email] = app(CurrentWorkspace::class)->run($this->ws, function () {
            $contact = Contact::query()->create([
                'workspace_id' => $this->ws->id, 'first_name' => 'Ana', 'country' => 'CO',
                'email' => 'ana@ejemplo.com', 'email_bidx' => BlindIndex::email($this->ws->id, 'ana@ejemplo.com'),
                'phone' => '+573001234567', 'phone_bidx' => BlindIndex::phone($this->ws->id, '+573001234567'),
            ]);
            foreach (['whatsapp', 'email', 'sms'] as $channel) {
                $this->grant($contact, $channel);
            }
            $wa = ChannelAccount::query()->create([
                'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp',
                'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true, 'external_id' => '1111', 'identity' => '+57 300 0000000',
                'config' => ['waba_id' => '2222', 'phone_number_id' => '1111', 'access_token' => 'EAAB-token-de-prueba'],
            ]);
            $email = ChannelAccount::query()->create([
                'workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'ses', 'display_name' => 'Correo',
                'status' => 'active', 'is_default' => true, 'external_id' => 'tienda.co', 'identity' => 'tienda.co',
                'config' => ['domain' => 'tienda.co', 'from_email' => 'hola@tienda.co', 'from_name' => 'Pinturas La 14'],
            ]);

            return [$contact, $wa, $email];
        });
        $this->fakeRenderer();
        $this->fakeSes();
    }

    /**
     * SES sin red: cada envío responde un MessageId y queda en `$sesSent`.
     *
     * @return \ArrayObject<int, array<string, mixed>>
     */
    public function fakeSes(): \ArrayObject
    {
        $sent = new \ArrayObject;
        app()->instance(SesClientFactory::class, new class($sent) extends SesClientFactory
        {
            /** @param \ArrayObject<int, array<string, mixed>> $sent */
            public function __construct(private \ArrayObject $sent) {}

            public function make(): SesV2Client
            {
                $handler = new MockHandler;
                $handler->append(function (CommandInterface $cmd) {
                    $this->sent[] = ['name' => $cmd->getName()] + $cmd->toArray();

                    return new Result(['MessageId' => 'ses-'.count($this->sent)]);
                });

                return new SesV2Client(['version' => '2019-09-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $handler]);
            }
        });

        return $sent;
    }

    public function grant(Contact $contact, string $channel): void
    {
        DB::table('contact_consents')->insert([
            'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $contact->workspace_id, 'contact_id' => $contact->id,
            'channel' => $channel, 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'checkout',
            'granted_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    /** Plantillas de prueba: `tpl_email` (correo con un enlace) y `tpl_wa` (plantilla de WhatsApp con URL). */
    public function fakeRenderer(): void
    {
        app()->instance(TemplateRenderer::class, new class implements TemplateRenderer
        {
            public function render(string $templateId, RenderContext $context): RenderedMessage
            {
                $name = $context->contact?->first_name ?? 'hola';
                $baja = (string) ($context->vars['enlaces']['baja'] ?? '');

                return match ($templateId) {
                    'tpl_email' => new RenderedMessage(
                        subject: "{$name}, tu carrito te espera",
                        html: '<html><body><p>Hola '.e($name).'</p><a href="https://tienda.co/carrito?x=1">Ver carrito</a><a href="mailto:hola@tienda.co">Escríbenos</a></body></html>',
                        text: "Hola {$name}. Ver carrito: https://tienda.co/carrito?x=1",
                    ),
                    'tpl_email_baja' => new RenderedMessage(subject: 'Con baja', html: '<html><body><a href="'.e($baja).'">Baja</a></body></html>', text: 'Baja: '.$baja),
                    'tpl_wa' => new RenderedMessage(
                        waTemplateName: 'carrito_abandonado',
                        waLanguage: 'es',
                        waComponents: [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $name], ['type' => 'text', 'text' => 'https://tienda.co/carrito']]],
                            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'https://tienda.co/checkout']]],
                        ],
                    ),
                    default => throw new \RuntimeException('Plantilla rota'),
                };
            }
        });
    }
}
