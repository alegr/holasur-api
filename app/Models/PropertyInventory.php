<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyInventory extends Model
{
    protected $table = 'property_inventory';

    protected $fillable = [
        'property_id',
        'item_name',
        'description',
        'quantity',
        'condition',
        'notes',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
