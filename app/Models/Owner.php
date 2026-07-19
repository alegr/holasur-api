<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    protected $fillable = [
        'avantio_id',
        'name',
        'email',
        'phone',
        'country',
        'intranet_access',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'intranet_access' => 'boolean',
            'raw_data' => 'array',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
