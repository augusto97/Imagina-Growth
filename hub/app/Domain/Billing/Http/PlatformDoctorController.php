<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Console\Commands\DoctorCommand;
use App\Domain\Billing\Platform\DoctorCheck;
use App\Domain\Billing\Platform\InstallationDoctor;
use Illuminate\Http\JsonResponse;

/** GET /api/platform/doctor — el diagnóstico de la instalación (pestaña «Instalación»), lo mismo que `igr:doctor`. */
final class PlatformDoctorController
{
    public function __invoke(InstallationDoctor $doctor): JsonResponse
    {
        $checks = $doctor->run();
        $groups = [];
        foreach ($checks as $check) {
            $groups[$check->group] ??= ['key' => $check->group, 'name' => DoctorCommand::groupName($check->group), 'checks' => []];
            $groups[$check->group]['checks'][] = $check->toArray();
        }

        return response()->json([
            'status' => InstallationDoctor::overall($checks),
            'groups' => array_values($groups),
            'counts' => array_count_values(array_map(fn (DoctorCheck $c) => $c->status, $checks)),
            'checked_at' => now()->toIso8601String(),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
