<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyLocation extends Model
{
    protected $connection = 'pgsql_locations';

    protected $fillable = [
        'user_id',
        'user_email',
        'listing_id',
        'latitude',
        'longitude',
        'source',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'registered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
