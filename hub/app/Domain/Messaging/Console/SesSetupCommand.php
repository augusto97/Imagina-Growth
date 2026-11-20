<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Support\SesEventSetup;
use Illuminate\Console\Command;

/**
 * Prepara el SES de la plataforma (F5): crea el configuration set `IGR_SES_CONFIGURATION_SET` y su destino de eventos
 * hacia `IGR_SES_SNS_TOPIC_ARN` si faltan. Idempotente: correrlo otra vez no cambia nada si ya está bien. Lo mismo pasa
 * solo al conectar o probar un canal SES.
 */
final class SesSetupCommand extends Command
{
    protected $signature = 'igr:ses:setup';

    protected $description = 'Crea el configuration set de SES y su destino de eventos hacia SNS si faltan';

    public function handle(SesEventSetup $setup): int
    {
        $result = $setup->ensure();
        match ($result['status']) {
            'ok' => $this->info($result['message']),
            'no_topic' => $this->warn($result['message']),
            default => $this->error($result['message']),
        };
        if ($result['status'] === 'ok') {
            $this->line(__('Recuerda: la política del tema de SNS debe dejar publicar a ses.amazonaws.com con AWS:SourceArn = arn:aws:ses:<región>:<cuenta>:configuration-set/:set, y el tema debe tener suscrita la URL :url.', [
                'set' => $result['configuration_set'], 'url' => rtrim((string) config('app.url'), '/').'/webhooks/ses',
            ]));
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
