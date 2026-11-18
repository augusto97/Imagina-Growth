<?php

declare(strict_types=1);

namespace App\Domain\Segments\Compiler;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de una compilación: espacio de trabajo, restricción opcional a un
 * contacto (evaluación en tiempo real) y las tablas temporales donde se
 * materializan los conjuntos que devuelve ClickHouse.
 */
final class CompileContext
{
    /** Por encima de este tamaño, un conjunto de ClickHouse va a una tabla temporal en vez de un IN (…). */
    public const INLINE_LIMIT = 1000;

    /** @var list<string> */
    private array $tempTables = [];

    private static int $seq = 0;

    /**
     * @param  list<string>  $anonymousIds  del contacto restringido, para resolver sus eventos anónimos
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?string $contactId = null,
        public readonly array $anonymousIds = [],
        public readonly int $timeoutSeconds = 10,
    ) {}

    public static function forContact(Workspace $workspace, string $contactId): self
    {
        $anons = DB::table('contact_identities')
            ->where('workspace_id', $workspace->id)
            ->where('contact_id', $contactId)
            ->where('type', 'anonymous_id')
            ->limit(200)
            ->pluck('value')
            ->map(fn ($v) => (string) $v)
            ->all();

        return new self($workspace, $contactId, array_values($anons));
    }

    public function timezone(): string
    {
        return $this->workspace->timezone ?: 'America/Bogota';
    }

    /**
     * Guarda un conjunto de IDs de contacto en una tabla temporal de la sesión
     * de MariaDB y devuelve su nombre.
     *
     * @param  list<string>  $ids
     */
    public function materialize(array $ids): string
    {
        $name = 'igr_seg_tmp_'.getmypid().'_'.(++self::$seq);
        Schema::create($name, function (Blueprint $table): void {
            $table->temporary();
            $table->char('contact_id', 26)->primary();
        });
        $this->tempTables[] = $name;
        foreach (array_chunk(array_values(array_unique($ids)), 5000) as $chunk) {
            DB::table($name)->insertOrIgnore(array_map(fn (string $id) => ['contact_id' => $id], $chunk));
        }

        return $name;
    }

    /** Borra las tablas temporales (también se borran solas al cerrar la conexión). */
    public function cleanup(): void
    {
        foreach ($this->tempTables as $name) {
            Schema::dropIfExists($name);
        }
        $this->tempTables = [];
    }
}
