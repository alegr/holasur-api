<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'avantio_id',
        'avantio_reference',
        'owner_id',
        'name',
        'type',
        'location',
        'address',
        'size_m2',
        'bedrooms',
        'bathrooms',
        'beds',
        'max_guests',
        'status',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'size_m2' => 'decimal:2',
            'raw_data' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(PropertyIncident::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(PropertyInventory::class);
    }
}
