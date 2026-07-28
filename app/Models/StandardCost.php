<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardCost extends Model
{
    protected $fillable = [
        'property_id',
        'cost_category_id',
        'standard_amount',
    ];

    protected function casts(): array
    {
        return [
            'standard_amount' => 'decimal:2',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }
}
