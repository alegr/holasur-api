<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = [
        'avantio_id',
        'booking_id',
        'property_id',
        'type',
        'responsible',
        'supplier',
        'status',
        'scheduled_date',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'raw_data' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
