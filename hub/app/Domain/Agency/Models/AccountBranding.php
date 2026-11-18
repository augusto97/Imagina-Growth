<?php

declare(strict_types=1);

namespace App\Domain\Agency\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca de una cuenta (M22). Es de la cuenta, no de un espacio de trabajo: la
 * leen todos los espacios de trabajo de la agencia. Solo la editan sus
 * miembros (`AgencyAccess`).
 *
 * @property string $account_id
 * @property string|null $brand_name
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property string|null $support_email
 * @property bool $white_label
 */
class AccountBranding extends Model
{
    protected $table = 'account_branding';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'brand_name', 'logo_path', 'primary_color', 'sender_name', 'sender_email', 'support_email', 'white_label'];

    protected function casts(): array
    {
        return ['white_label' => 'boolean'];
    }
}
