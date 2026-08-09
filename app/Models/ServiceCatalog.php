<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceCatalog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'default_unit_cost' => 'decimal:2',
        'default_unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function bookingServices()
    {
        return $this->hasMany(BookingService::class);
    }
}
