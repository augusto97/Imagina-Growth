<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * La opción `--workspace` de los comandos igr:*: acepta el ID público (`ws_…`)
 * o el ULID interno, una o varias veces. Un valor que no corresponde a ningún
 * espacio de trabajo es un error: nunca se ignora en silencio ni se cae a «todos».
 */
final class WorkspaceOption
{
    /**
     * @param  array<int, mixed>|string|null  $values
     * @return Collection<int, Workspace>
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(array|string|null $values, bool $allWhenEmpty = true): Collection
    {
        $values = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $values), fn (string $v) => $v !== ''));
        if ($values === []) {
            return $allWhenEmpty ? Workspace::query()->where('status', 'active')->orderBy('ch_id')->get() : new Collection;
        }

        $ids = [];
        $unknown = [];
        foreach ($values as $value) {
            $id = PrefixedId::decode(Workspace::ID_PREFIX, $value) ?? (PrefixedId::isUlid($value) ? $value : null);
            if ($id === null) {
                $unknown[] = $value;
            } else {
                $ids[$id] = $value;
            }
        }
        $found = Workspace::query()->whereIn('id', array_keys($ids))->orderBy('ch_id')->get();
        foreach (array_diff(array_keys($ids), $found->modelKeys()) as $missing) {
            $unknown[] = $ids[$missing];
        }
        if ($unknown !== []) {
            throw new InvalidArgumentException('No existe el espacio de trabajo: '.implode(', ', $unknown).' (usa --workspace=ws_…).');
        }

        return $found;
    }
}
