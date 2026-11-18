<?php

declare(strict_types=1);

namespace App\Domain\Segments\Queries;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Compiler\SegmentTimeout;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Vista previa de un segmento (hub/CLAUDE.md §6): conteo, muestra de 20
 * contactos, contactos alcanzables por canal y valor del segmento, con un
 * máximo de 10 s.
 */
final class SegmentPreview
{
    public function __construct(private readonly SegmentCompiler $compiler) {}

    /**
     * @param  array<string, mixed>  $ast  validado
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, array $ast, bool $unmasked, int $sampleSize = 20): array
    {
        $started = microtime(true);
        $ctx = new CompileContext($workspace, timeoutSeconds: 10);
        DB::statement('SET SESSION max_statement_time = '.$ctx->timeoutSeconds);
        try {
            // La consulta de miembros corre una vez; lo demás lee la tabla temporal.
            [$table, $count] = $ctx->materializeMembers($this->compiler->query($ast, $ctx));
            $sampleIds = DB::table($table)->orderByDesc('last_seen_at')->orderBy('contact_id')->limit($sampleSize)->pluck('contact_id')->all();
            $value = DB::table($table)->selectRaw('COALESCE(SUM(revenue), 0) AS revenue, COALESCE(SUM(clv), 0) AS clv, COUNT(clv) AS with_clv')->first();
            $channels = [];
            foreach (['email', 'whatsapp'] as $channel) {
                $q = DB::table('contacts as c')->join($table.' as m', 'm.contact_id', '=', 'c.id')->where('c.workspace_id', $workspace->id);
                $this->compiler->applyMarketingConsent($q, $channel);
                $channels[$channel] = $q->count();
            }
        } catch (QueryException $e) {
            throw SegmentTimeout::looksLike($e) ? new SegmentTimeout($e) : $e;
        } finally {
            DB::statement('SET SESSION max_statement_time = 0');
            $ctx->cleanup();
        }

        $contacts = Contact::query()->whereKey($sampleIds)->get()->keyBy('id');
        $sample = [];
        foreach ($sampleIds as $id) {
            $c = $contacts->get($id);
            if ($c instanceof Contact) {
                $sample[] = ContactRow::make($c, $unmasked);
            }
        }

        return [
            'count' => $count,
            'sample' => $sample,
            'channels' => $channels,
            'value' => [
                'revenue_ws' => number_format((float) ($value->revenue ?? 0), 2, '.', ''),
                'predicted_clv_ws' => (int) ($value->with_clv ?? 0) > 0 ? number_format((float) ($value->clv ?? 0), 2, '.', '') : null,
            ],
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
