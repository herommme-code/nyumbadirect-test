<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProperty extends Model
{
    protected $fillable = [
        'user_id',
        'listing_id',
        'title',
        'description',
        'price',
        'type',
        'bedrooms',
        'bathrooms',
        'region',
        'district',
        'ward',
        'landmark',
        'latitude',
        'longitude',
        'amenities',
        'is_verified',
        'is_featured',
        'image_url',
        'local_image_paths',
        'local_video_path',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'amenities' => 'array',
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'local_image_paths' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
