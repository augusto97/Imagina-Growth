<?php

declare(strict_types=1);

namespace App\Domain\Consent\Console;

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Excepciones de festivos que edita el admin de la plataforma (cumplimiento.md
 * §4.2): agregar un festivo (p. ej. un día cívico decretado), quitar uno o listar
 * el calendario efectivo de un año. Los workers toman el cambio al reiniciarse
 * (`horizon:terminate`); este proceso lo aplica en el acto.
 */
final class HolidayOverrideCommand extends Command
{
    protected $signature = 'igr:holidays {country : CO, MX…} {date? : YYYY-MM-DD (sin fecha: lista el año --year)}
        {--name= : Nombre del festivo agregado}
        {--remove : La fecha deja de ser festivo}
        {--reset : Borra la excepción de esa fecha}
        {--year= : Año a listar}';

    protected $description = 'Agrega, quita o lista festivos por país (excepciones de la ventana legal)';

    public function handle(): int
    {
        $country = strtoupper((string) $this->argument('country'));
        $date = $this->argument('date');
        if (! is_string($date) || $date === '') {
            $year = (int) ($this->option('year') ?: now()->year);
            $this->table(['Fecha', 'Festivo'], array_map(fn ($d, $n) => [$d, $n], array_keys(Holidays::forYear($country, $year)), Holidays::forYear($country, $year)));

            return self::SUCCESS;
        }
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $date) : null;
        if (! $day instanceof CarbonImmutable) {
            $this->error(__('Fecha inválida: usa YYYY-MM-DD.'));

            return self::FAILURE;
        }
        $day = $day->startOfDay();
        if ($this->option('reset')) {
            DB::table('holiday_overrides')->where('country', $country)->where('date', $day->toDateString())->delete();
        } else {
            DB::table('holiday_overrides')->updateOrInsert(
                ['country' => $country, 'date' => $day->toDateString()],
                ['is_holiday' => ! $this->option('remove'), 'name' => $this->option('name') ?: null, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        Holidays::flush();
        $this->info(Holidays::isHoliday($country, $day)
            ? __(':date es festivo en :country.', ['date' => $day->toDateString(), 'country' => $country])
            : __(':date no es festivo en :country.', ['date' => $day->toDateString(), 'country' => $country]));

        return self::SUCCESS;
    }
}
