<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http;

use App\Domain\Agency\Domains\CustomDomains;
use App\Domain\Agency\Http\Concerns\ResolvesAgency;
use App\Domain\Agency\Models\AccountBranding as BrandingModel;
use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Agency\Support\AgencyBranding;
use App\Domain\Agency\Support\SenderDomain;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Marca blanca (M22): la marca de cada cuenta de agencia (nombre, logo, color,
 * remitente, correo de soporte, ocultar Imagina Growth) y sus dominios propios.
 * Todos los de la agencia ven; solo el dueño de la agencia cambia.
 */
final class AgencyBrandingController
{
    use ResolvesAgency;

    /** GET agency/branding */
    public function index(Request $request): JsonResponse
    {
        $user = $this->agencyUser($request);
        $accounts = Account::query()->whereIn('id', $this->agencyAccountIds($user))->orderBy('name')->get();

        return response()->json(['accounts' => $accounts->map(function (Account $a) use ($user): array {
            $b = BrandingModel::query()->find($a->id);
            $workspaces = Workspace::query()->where('account_id', $a->id)->orderBy('name')->get(['id', 'name']);
            $sender = is_string($b?->sender_email) && $b->sender_email !== '' ? $b->sender_email : null;

            return [
                'id' => $a->publicId(),
                'name' => $a->name,
                'can_manage' => AgencyAccess::isOwner($user, $a->id),
                'branding' => [
                    'brand_name' => $b?->brand_name,
                    'logo_url' => $b !== null ? AgencyBranding::logoUrl($b) : null,
                    'primary_color' => $b?->primary_color,
                    'sender_name' => $b?->sender_name,
                    'sender_email' => $sender,
                    'support_email' => $b?->support_email,
                    'white_label' => (bool) $b?->white_label,
                ],
                'sender_verified_in' => $sender === null ? 0 : $workspaces->filter(fn (Workspace $w) => SenderDomain::verifiedFor($w->id, $sender))->count(),
                'workspaces' => $workspaces->map(fn (Workspace $w) => ['id' => $w->publicId(), 'name' => $w->name])->values(),
                'domains' => CustomDomain::query()->where('account_id', $a->id)->orderBy('host')->get()
                    ->map(fn (CustomDomain $d) => self::domain($d, $workspaces->firstWhere('id', $d->workspace_id)?->name))->values(),
                'cname_target' => CustomDomains::cnameTarget(),
            ];
        })->values()]);
    }

    /** PUT agency/branding/{account} */
    public function update(Request $request, string $account, RecordAuditLog $audit): JsonResponse
    {
        $user = $this->agencyUser($request);
        $id = $this->ownedAccountId($user, $account);
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:120'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'email', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'white_label' => ['sometimes', 'boolean'],
        ]);
        $b = BrandingModel::query()->firstOrNew(['account_id' => $id]);
        $b->fill(array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data))->save();
        $audit->handle('agency.branding_updated', $b, ['fields' => array_keys($data)], null, $id, $user->id);

        return response()->json(['ok' => true]);
    }

    /** POST agency/branding/{account}/logo (archivo PNG o JPG, máximo 1 MB) */
    public function logo(Request $request, string $account): JsonResponse
    {
        $user = $this->agencyUser($request);
        $id = $this->ownedAccountId($user, $account);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);
        $file = $request->file('logo');
        abort_unless($file instanceof UploadedFile, 422);
        $b = BrandingModel::query()->firstOrNew(['account_id' => $id]);
        $old = $b->logo_path;
        $path = $file->storeAs('branding/'.$id, Str::lower((string) Str::ulid()).'.'.($file->extension() === 'png' ? 'png' : 'jpg'), 'public');
        $b->fill(['logo_path' => is_string($path) ? $path : null])->save();
        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['logo_url' => AgencyBranding::logoUrl($b)]);
    }

    /** DELETE agency/branding/{account}/logo */
    public function deleteLogo(Request $request, string $account): JsonResponse
    {
        $user = $this->agencyUser($request);
        $id = $this->ownedAccountId($user, $account);
        $b = BrandingModel::query()->find($id);
        if ($b !== null && is_string($b->logo_path)) {
            Storage::disk('public')->delete($b->logo_path);
            $b->fill(['logo_path' => null])->save();
        }

        return response()->json(['ok' => true]);
    }

    /** POST agency/domains {account_id, host, purpose, workspace_id?} */
    public function addDomain(Request $request, CustomDomains $domains): JsonResponse
    {
        $user = $this->agencyUser($request);
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:40'],
            'host' => ['required', 'string', 'max:253'],
            'purpose' => ['required', Rule::in(CustomDomain::PURPOSES)],
            'workspace_id' => ['nullable', 'string', 'max:40'],
        ]);
        $accountId = $this->ownedAccountId($user, $data['account_id']);
        $workspaceId = null;
        if (is_string($data['workspace_id'] ?? null) && $data['workspace_id'] !== '') {
            $ws = $this->clientWorkspace($user, $data['workspace_id']);
            abort_unless($ws->account_id === $accountId, 422);
            $workspaceId = $ws->id;
        }
        $domain = $domains->add($accountId, $data['purpose'] === 'panel' ? null : $workspaceId, $data['host'], $data['purpose'], $user->id);

        return response()->json(['domain' => self::domain($domain, null)], 201);
    }

    /** POST agency/domains/{domain}/verify */
    public function verifyDomain(Request $request, string $domain, CustomDomains $domains): JsonResponse
    {
        $user = $this->agencyUser($request);
        $model = $this->findDomain($user, $domain);

        return response()->json(['domain' => self::domain($domains->verify($model, $user->id), null)]);
    }

    /** DELETE agency/domains/{domain} */
    public function deleteDomain(Request $request, string $domain, CustomDomains $domains): JsonResponse
    {
        $user = $this->agencyUser($request);
        $domains->remove($this->findDomain($user, $domain), $user->id);

        return response()->json(['ok' => true]);
    }

    private function findDomain(User $user, string $publicId): CustomDomain
    {
        $id = PrefixedId::decode(CustomDomain::ID_PREFIX, $publicId);
        $model = $id === null ? null : CustomDomain::query()->whereIn('account_id', $this->agencyAccountIds($user))->find($id);
        if ($model === null) {
            abort(404);
        }
        $this->ownedAccountId($user, $model->account_id);

        return $model;
    }

    /** @return array<string, mixed> */
    private static function domain(CustomDomain $d, ?string $workspaceName): array
    {
        return [
            'id' => $d->publicId(),
            'host' => $d->host,
            'purpose' => $d->purpose,
            'workspace' => $workspaceName,
            'status' => $d->status,
            'verified_at' => $d->verified_at?->toIso8601String(),
            'last_error' => $d->last_error,
            'records' => CustomDomains::instructions($d),
        ];
    }
}
