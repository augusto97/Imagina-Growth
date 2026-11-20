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
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Actions\InviteToWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
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

    /**
     * POST agency/clients — la agencia da de alta un cliente: un espacio de trabajo nuevo en su cuenta de agencia
     * (la agencia lo administra por su rol en la cuenta, sin quedar como miembro directo) y, si se da, la invitación
     * al dueño o administrador del cliente.
     */
    public function createClient(Request $request, CreateWorkspace $create, InviteToWorkspace $invite): JsonResponse
    {
        $user = $this->agencyUser($request);
        $data = $request->validate([
            'account_id' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'country' => ['required', Rule::in(['CO', 'MX', 'VE', 'PE', 'CL', 'EC', 'AR', 'US', 'ES', 'PA', 'CR', 'GT', 'DO'])],
            'currency' => ['required', Rule::in(['COP', 'MXN', 'USD', 'PEN', 'CLP', 'ARS', 'EUR', 'VES'])],
            'timezone' => ['required', 'timezone'],
            'industry' => ['nullable', 'string', 'max:40'],
            'invite_email' => ['nullable', 'email', 'max:190'],
            'invite_role' => ['nullable', Rule::in([WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])],
        ]);
        $account = Account::findByPublicId($data['account_id']);
        if ($account === null || $account->type !== AccountType::Agency || ! in_array(AgencyAccess::role($user, $account->id), ['agency_owner', 'agency_member'], true)) {
            abort(404);
        }
        $country = (string) $data['country'];
        $workspace = $create->createInAccount($account, new WorkspaceData(
            name: (string) $data['name'],
            country: $country,
            timezone: (string) $data['timezone'],
            currency: (string) $data['currency'],
            locale: in_array($country, ['MX', 'VE'], true) ? 'es-'.$country : 'es-CO',
            industry: isset($data['industry']) && $data['industry'] !== '' ? (string) $data['industry'] : null,
        ), null, $user);
        if (! empty($data['invite_email'])) {
            $invite->handle($workspace, $user, (string) $data['invite_email'], WorkspaceRole::from((string) ($data['invite_role'] ?? WorkspaceRole::Owner->value)));
        }

        return response()->json(['workspace_id' => $workspace->publicId(), 'name' => $workspace->name], 201);
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
