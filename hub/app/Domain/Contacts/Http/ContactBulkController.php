<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Queries\ContactList;
use App\Domain\Segments\Actions\CreateStaticSegment;
use App\Domain\Segments\Http\SegmentPresenter;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Csv;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acciones masivas de la lista de personas (modulos.md M03): exportar a CSV y agregar a un grupo de clientes fijo.
 * Trabajan con las personas elegidas (`ids`, hasta 500) o con todas las que cumplen los filtros de la lista
 * (`all=1` y los mismos filtros de `GET contacts`, hasta 50.000), siempre con el acceso del rol (ContactAccess).
 */
final class ContactBulkController
{
    /** Personas por acción con «todas las que cumplen los filtros». */
    public const MAX_ALL = 50_000;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET contacts/export?{filtros}&ids[]=… — CSV con BOM (Excel abre bien las tildes); auditoría `contacts.exported`. */
    public function export(Request $request, RecordAuditLog $audit): StreamedResponse
    {
        Gate::authorize('ws.exportContacts');
        $query = $this->selection($request);
        $workspaceId = $this->current->id();
        $total = (clone $query)->count();
        $audit->handle('contacts.exported', null, ['count' => min($total, self::MAX_ALL), 'filters' => array_keys(array_filter($request->only(array_keys(ContactList::rules()))))], $workspaceId);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'nombre', 'apellido', 'correo', 'telefono', 'ciudad', 'departamento', 'etapa', 'segmento_rfm', 'pedidos', 'gasto_total', 'ultimo_pedido', 'etiquetas'], ',', '"', '');
            $sent = 0;
            // Por id (no por el orden de la lista) para recorrer sin OFFSET; el filtro es el mismo.
            $query->reorder()->chunkById(1000, function ($contacts) use ($out, &$sent): bool {
                foreach ($contacts as $c) {
                    /** @var Contact $c */
                    $tags = $c->getAttribute('tags');
                    fputcsv($out, Csv::row([
                        $c->publicId(), $c->first_name ?? '', $c->last_name ?? '', $c->email ?? '', $c->phone ?? '',
                        $c->getAttribute('city') ?? '', $c->getAttribute('region_code') ?? '', $c->getAttribute('lifecycle_stage') ?? '',
                        $c->getAttribute('rfm_segment') ?? '', (int) $c->getAttribute('orders_count'), (string) $c->getAttribute('revenue_total_ws'),
                        $c->getAttribute('last_order_at')?->toDateString() ?? '', is_array($tags) ? implode(', ', $tags) : '',
                    ]), ',', '"', '');
                    if (++$sent >= self::MAX_ALL) {
                        return false;
                    }
                }

                return true;
            });
            fclose($out);
        }, 'personas-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * POST contacts/group {ids[] | all + filtros, segment_id | name} — agrega las personas a un grupo fijo existente
     * (`segment_id`) o crea uno nuevo con ese nombre. Responde el grupo y cuántas personas entraron.
     */
    public function group(Request $request, CreateStaticSegment $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        Gate::authorize('ws.viewAllContacts');
        $data = $request->validate([
            'segment_id' => ['nullable', 'required_without:name', 'string', 'max:40'],
            'name' => ['nullable', 'required_without:segment_id', 'string', 'min:2', 'max:160'],
        ]);
        $ids = array_values(array_map('strval', $this->selection($request)->reorder()->limit(self::MAX_ALL)->pluck('id')->all()));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => __('Elige al menos una persona.')]);
        }
        /** @var User $user */
        $user = $request->user();
        if (is_string($data['segment_id'] ?? null) && $data['segment_id'] !== '') {
            $segment = Segment::findByPublicId($data['segment_id']) ?? abort(404);
            if ($segment->isDynamic() || $segment->is_system) {
                throw ValidationException::withMessages(['segment_id' => __('Ese grupo se arma solo con sus condiciones: elige un grupo fijo o crea uno nuevo.')]);
            }
            $added = $create->append($segment, $ids, $user);
        } else {
            $segment = $create->fromContacts((string) $data['name'], $ids, $user);
            $added = count($ids);
        }

        return response()->json(['segment' => SegmentPresenter::make($segment->refresh()), 'added' => $added, 'selected' => count($ids)]);
    }

    /**
     * Las personas de la acción: las elegidas (`ids`) o todas las que cumplen los filtros (`all`).
     *
     * @return Builder<Contact>
     */
    private function selection(Request $request): Builder
    {
        $data = $request->validate(ContactList::rules() + [
            'all' => ['nullable', 'boolean'],
            'ids' => ['nullable', 'required_without:all', 'array', 'max:500'],
            'ids.*' => ['string', 'max:40'],
        ]);
        $query = ContactList::query($this->current->get(), $data);
        if (! (bool) ($data['all'] ?? false)) {
            $ids = array_values(array_filter(array_map(fn ($id) => PrefixedId::decode(Contact::ID_PREFIX, (string) $id), $data['ids'] ?? [])));
            $query->whereIn('id', $ids === [] ? ['-'] : $ids);
        }

        return $query;
    }
}
