<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'auth_provider',
        'phone',
        'whatsapp_number',
        'location',
        'bio',
        'profile_photo_url',
        'profile_photo_filename',
        'profile_photo_mime',
        'profile_photo_data',
        'is_online',
        'last_seen_at',
    ];

    public const UPDATED_AT = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'profile_photo_data',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function sellerProperties(): HasMany
    {
        return $this->hasMany(SellerProperty::class);
    }

    public function propertyLocations(): HasMany
    {
        return $this->hasMany(PropertyLocation::class);
    }

    public function favoriteProperties(): HasMany
    {
        return $this->hasMany(FavoriteProperty::class);
    }
}
