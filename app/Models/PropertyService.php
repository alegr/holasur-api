<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyService extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_applicable' => 'boolean',
        'is_personalized' => 'boolean',
        'owner_return_percent' => 'decimal:2',
        'is_from_avantio' => 'boolean',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function catalog()
    {
        return $this->belongsTo(ServiceCatalog::class, 'service_catalog_id');
    }
}
