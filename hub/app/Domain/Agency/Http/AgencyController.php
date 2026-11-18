<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http;

use App\Domain\Agency\Http\Concerns\ResolvesAgency;
use App\Domain\Agency\Queries\AgencyOverview;
use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Agency\Support\SupportSessions;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Models\Flow;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Models\Template;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Panel de agencia (M22): todos los clientes de las cuentas de agencia de la persona. */
final class AgencyController
{
    use ResolvesAgency;

    /** GET agency/overview */
    public function overview(Request $request, AgencyOverview $overview): JsonResponse
    {
        $user = $this->agencyUser($request);
        $ids = $this->agencyAccountIds($user);
        $data = $overview->handle($ids);
        foreach ($data['accounts'] as $i => $account) {
            $data['accounts'][$i]['can_manage'] = AgencyAccess::isOwner($user, substr((string) $account['id'], 4));
        }

        return response()->json($data);
    }

    /** POST agency/clients/{client}/enter — abre una sesión de soporte auditada si no es miembro directo. */
    public function enter(Request $request, string $client, SupportSessions $sessions): JsonResponse
    {
        $user = $this->agencyUser($request);
        $workspace = $this->clientWorkspace($user, $client);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $session = null;
        if (AgencyAccess::viaAgency($user, $workspace)) {
            $session = $sessions->start($user, $workspace, (string) ($data['reason'] ?? ''), $request->ip());
        }

        return response()->json(['workspace_id' => $workspace->publicId(), 'support_session' => $session?->publicId()]);
    }

    /** GET agency/clients/{client}/items?kind=flow — lo que se puede guardar como plantilla. */
    public function items(Request $request, string $client, CurrentWorkspace $current): JsonResponse
    {
        $user = $this->agencyUser($request);
        $workspace = $this->clientWorkspace($user, $client);
        $kind = (string) $request->validate(['kind' => ['required', Rule::in(['flow', 'segment', 'widget', 'template', 'campaign'])]])['kind'];

        $items = $current->run($workspace, fn () => match ($kind) {
            'flow' => Flow::query()->orderBy('name')->limit(500)->get()->map(fn (Flow $f) => ['id' => $f->publicId(), 'name' => $f->name, 'detail' => $f->status]),
            'segment' => Segment::query()->where('kind', Segment::KIND_DYNAMIC)->where('is_system', false)->orderBy('name')->limit(500)->get()
                ->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'detail' => $s->folder]),
            'widget' => OnsiteWidget::query()->orderBy('name')->limit(500)->get()->map(fn (OnsiteWidget $w) => ['id' => $w->publicId(), 'name' => $w->name, 'detail' => $w->kind]),
            'template' => Template::query()->where('status', '!=', 'archived')->orderBy('name')->limit(500)->get()
                ->map(fn (Template $t) => ['id' => Template::ID_PREFIX.'_'.$t->id, 'name' => $t->name, 'detail' => $t->channel]),
            default => Campaign::query()->orderByDesc('created_at')->limit(500)->get()->map(fn (Campaign $c) => ['id' => $c->publicId(), 'name' => $c->name, 'detail' => $c->channel]),
        });

        return response()->json(['items' => $items->values()]);
    }
}
