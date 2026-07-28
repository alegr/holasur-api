<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $fillable = [
        'avantio_id',
        'avantio_reference',
        'property_id',
        'customer_id',
        'check_in',
        'check_out',
        'nights',
        'adults',
        'children',
        'status',
        'channel',
        'total_amount',
        'currency',
        'is_revenue',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'total_amount' => 'decimal:2',
            'is_revenue' => 'boolean',
            'raw_data' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function operation(): HasOne
    {
        return $this->hasOne(BookingOperation::class);
    }
}
