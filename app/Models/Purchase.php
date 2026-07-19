<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $fillable = [
        'purchase_number',
        'supplier_id',
        'receipt_type',
        'receipt_number',
        'receipt_date',
        'due_date',
        'paid_date',
        'accounting_month',
        'economic_responsible',
        'owner_id',
        'property_id',
        'booking_id',
        'imputation_type',
        'subtotal',
        'tax',
        'total',
        'currency',
        'usd_rate',
        'usd_total',
        'payment_method',
        'payment_status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'due_date' => 'date',
            'paid_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'usd_rate' => 'decimal:4',
            'usd_total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /**
     * Generate the next purchase number for the given year.
     */
    public static function generateNumber(): string
    {
        $year = date('Y');
        $prefix = "CMP-{$year}-";

        $last = static::where('purchase_number', 'like', "{$prefix}%")
            ->orderByDesc('purchase_number')
            ->value('purchase_number');

        if ($last) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
