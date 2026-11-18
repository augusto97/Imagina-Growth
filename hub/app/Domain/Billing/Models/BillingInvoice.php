<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Factura de una cuenta (M21, v1 manual o con enlace de pago). Es de la cuenta, no
 * de un espacio de trabajo: las consultas siempre filtran por `account_id`.
 *
 * @property string $id
 * @property string $account_id
 * @property string $number
 * @property Carbon $period
 * @property list<array{concept: string, quantity: int|float, unit_price: string, total: string}> $lines
 * @property string $total
 * @property string $currency
 * @property string $status draft | sent | paid | void | overdue
 * @property string|null $payment_url
 * @property string|null $pdf_path
 * @property Carbon|null $sent_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property string|null $issued_by
 * @property string|null $notes
 */
class BillingInvoice extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'inv';

    protected $fillable = ['account_id', 'number', 'period', 'lines', 'total', 'currency', 'status', 'payment_url', 'pdf_path', 'sent_at', 'due_at', 'paid_at', 'issued_by', 'notes'];

    protected $hidden = ['pdf_path'];

    protected function casts(): array
    {
        return ['period' => 'date', 'lines' => 'array', 'total' => 'decimal:2', 'sent_at' => 'datetime', 'due_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->publicId(), 'number' => $this->number, 'period' => $this->period->format('Y-m'),
            'lines' => $this->lines, 'total' => (string) $this->total, 'currency' => $this->currency, 'status' => $this->status,
            'payment_url' => $this->payment_url, 'due_at' => $this->due_at?->toIso8601String(), 'paid_at' => $this->paid_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(), 'has_pdf' => $this->pdf_path !== null, 'notes' => $this->notes,
        ];
    }
}
