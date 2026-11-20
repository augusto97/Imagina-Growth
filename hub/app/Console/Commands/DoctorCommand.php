<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Platform\DoctorCheck;
use App\Domain\Billing\Platform\InstallationDoctor;
use Illuminate\Console\Command;

/**
 * Diagnóstico de la instalación (F3, F15): qué falta configurar (correo, SES, Meta, Anthropic, Google, Reverb…), si
 * las colas y las tareas programadas corren, las tarifas de WhatsApp, los planes y la versión del plugin de cada
 * sitio. Sale con código 1 si hay algún error. Lo mismo se ve en Plataforma → Instalación.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'igr:doctor {--json : Imprime el resultado en JSON}';

    protected $description = 'Revisa la instalación y dice qué falta configurar y cómo';

    public function handle(InstallationDoctor $doctor): int
    {
        $checks = $doctor->run();
        $overall = InstallationDoctor::overall($checks);
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $overall, 'checks' => array_map(fn (DoctorCheck $c) => $c->toArray(), $checks)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $overall === DoctorCheck::ERROR ? self::FAILURE : self::SUCCESS;
        }
        $labels = [DoctorCheck::OK => __('bien'), DoctorCheck::INFO => __('nota'), DoctorCheck::WARNING => __('falta'), DoctorCheck::ERROR => __('error')];
        $group = null;
        foreach ($checks as $check) {
            if ($check->group !== $group) {
                $group = $check->group;
                $this->newLine();
                $this->line('<options=bold>'.self::groupName($group).'</>');
            }
            $tag = match ($check->status) {
                DoctorCheck::OK => '<fg=green>',
                DoctorCheck::INFO => '<fg=blue>',
                DoctorCheck::WARNING => '<fg=yellow>',
                default => '<fg=red>',
            };
            $this->line(sprintf('  %s[%s]</> %s', $tag, $labels[$check->status] ?? $check->status, $check->title));
            if ($check->status !== DoctorCheck::OK && $check->detail !== '') {
                $this->line('      '.$check->detail);
            }
            foreach ($check->items as $item) {
                $this->line('      · '.$item);
            }
        }
        $this->newLine();
        match ($overall) {
            DoctorCheck::ERROR => $this->error(__('Hay errores que impiden que la plataforma funcione completa.')),
            DoctorCheck::WARNING => $this->warn(__('Funciona, pero falta configurar algunas cosas.')),
            default => $this->info(__('Todo en orden.')),
        };

        return $overall === DoctorCheck::ERROR ? self::FAILURE : self::SUCCESS;
    }

    public static function groupName(string $group): string
    {
        return match ($group) {
            'installation' => __('Instalación'),
            'services' => __('Servicios y colas'),
            'email' => __('Correo'),
            'whatsapp' => __('WhatsApp y Meta'),
            'integrations' => __('IA e integraciones'),
            'data' => __('Administración, planes y sitios'),
            default => $group,
        };
    }
}
