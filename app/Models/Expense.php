<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'expense_number',
        'beneficiary_type',
        'beneficiary_name',
        'expense_type',
        'cost_category_id',
        'property_id',
        'owner_id',
        'booking_id',
        'imputation_type',
        'amount',
        'currency',
        'usd_rate',
        'usd_amount',
        'is_recurring',
        'recurrence_frequency',
        'scheduled_date',
        'due_date',
        'paid_date',
        'payment_method',
        'payment_account',
        'payment_status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'usd_rate' => 'decimal:4',
            'usd_amount' => 'decimal:2',
            'is_recurring' => 'boolean',
            'scheduled_date' => 'date',
            'due_date' => 'date',
            'paid_date' => 'date',
        ];
    }

    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Generate the next expense number for the given year.
     */
    public static function generateNumber(): string
    {
        $year = date('Y');
        $prefix = "EGR-{$year}-";

        $last = static::where('expense_number', 'like', "{$prefix}%")
            ->orderByDesc('expense_number')
            ->value('expense_number');

        if ($last) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
