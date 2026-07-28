<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingOperation extends Model
{
    protected $fillable = [
        'booking_id',
        'operation_id',
        'status',
        'responsible',
        'commercial_notes',
        'operational_notes',
        'checklist',
        'incident_type',
        'incident_level',
        'cleaning_coordinated',
        'requires_maintenance',
        'pending_followup',
        'documentation',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'documentation' => 'array',
            'cleaning_coordinated' => 'boolean',
            'requires_maintenance' => 'boolean',
            'pending_followup' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
