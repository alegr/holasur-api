<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvantioPayment extends Model
{
    protected $table = 'avantio_payments';

    protected $fillable = [
        'avantio_id',
        'payment_type',
        'date',
        'booking_reference',
        'property_code',
        'property_id',
        'description',
        'counterpart',
        'payment_method',
        'amount',
        'currency',
        'state',
        'portal',
        'observations',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'raw_data' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
