<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Afiliado, influencer o aliado (modulos.md M14): sus cupones y su enlace
 * (`?ref=LINKCODE`) le atribuyen pedidos, con comisión porcentual o fija sobre el
 * pedido completado. `email` y `payout_info` van cifrados; el correo tiene índice
 * ciego para el enlace mágico del panel del creador.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $contact_id
 * @property string $name
 * @property string $kind influencer | affiliate | partner
 * @property string|null $email
 * @property string|null $email_bidx
 * @property list<string>|null $coupon_codes
 * @property string $link_code
 * @property array{type: string, value: string, basis?: string} $commission
 * @property list<array{label: string, url: string}>|null $material
 * @property string|null $payout_info
 * @property string|null $portal_nonce
 * @property CarbonInterface|null $portal_sent_at
 * @property string $status active | paused | archived
 * @property CarbonInterface|null $created_at
 */
class Affiliate extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'af';

    public const KINDS = ['influencer', 'affiliate', 'partner'];

    protected $fillable = ['workspace_id', 'contact_id', 'name', 'kind', 'email', 'email_bidx', 'coupon_codes', 'link_code', 'commission', 'material', 'payout_info', 'portal_nonce', 'portal_sent_at', 'status'];

    protected $hidden = ['email', 'email_bidx', 'payout_info', 'portal_nonce'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'payout_info' => 'encrypted',
            'coupon_codes' => 'array',
            'commission' => 'array',
            'material' => 'array',
            'portal_sent_at' => 'datetime',
        ];
    }

    /** @return list<string> códigos en minúsculas, como los guarda WooCommerce en el pedido */
    public function normalizedCoupons(): array
    {
        return array_values(array_unique(array_map(fn ($c) => mb_strtolower(trim((string) $c)), $this->coupon_codes ?? [])));
    }
}
