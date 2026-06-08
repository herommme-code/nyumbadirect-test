<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyLocation;
use App\Models\SellerProperty;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SellerPropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $properties = $user->sellerProperties()
            ->latest()
            ->get()
            ->map(fn (SellerProperty $property) => $this->propertyPayload($property))
            ->values();

        return response()->json(['properties' => $properties]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'properties' => ['present', 'array'],
            'properties.*.id' => ['required', 'string', 'max:255'],
            'properties.*.title' => ['required', 'string', 'max:255'],
            'properties.*.description' => ['nullable', 'string', 'max:5000'],
            'properties.*.price' => ['required', 'integer', 'min:0'],
            'properties.*.type' => ['required', 'string', 'max:80'],
            'properties.*.bedrooms' => ['required', 'integer', 'min:0'],
            'properties.*.bathrooms' => ['required', 'integer', 'min:0'],
            'properties.*.region' => ['nullable', 'string', 'max:255'],
            'properties.*.district' => ['nullable', 'string', 'max:255'],
            'properties.*.ward' => ['nullable', 'string', 'max:255'],
            'properties.*.landmark' => ['nullable', 'string', 'max:255'],
            'properties.*.latitude' => ['nullable', 'numeric'],
            'properties.*.longitude' => ['nullable', 'numeric'],
            'properties.*.amenities' => ['nullable', 'array'],
            'properties.*.isVerified' => ['boolean'],
            'properties.*.isFeatured' => ['boolean'],
            'properties.*.imageUrl' => ['nullable', 'string', 'max:4000'],
            'properties.*.localImagePaths' => ['nullable', 'array'],
            'properties.*.localVideoPath' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $incomingIds = collect($validated['properties'])
            ->pluck('id')
            ->filter()
            ->values();

        $user->sellerProperties()
            ->whereNotIn('listing_id', $incomingIds)
            ->delete();

        foreach ($validated['properties'] as $property) {
            SellerProperty::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'listing_id' => $property['id'],
                ],
                $this->databasePayload($property)
            );

            if (($property['isVerified'] ?? false) &&
                ($property['latitude'] ?? 0) != 0 &&
                ($property['longitude'] ?? 0) != 0) {
                $this->storePropertyLocation($user, $property['id'], $property);
            }
        }

        $properties = $user->sellerProperties()
            ->latest()
            ->get()
            ->map(fn (SellerProperty $property) => $this->propertyPayload($property))
            ->values();

        return response()->json([
            'message' => 'Seller properties saved.',
            'properties' => $properties,
        ]);
    }

    public function updateLocation(Request $request, string $listingId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $property = $user->sellerProperties()
            ->where('listing_id', $listingId)
            ->first();

        if (! $property) {
            $location = $this->storePropertyLocation($user, $listingId, $validated);

            return response()->json([
                'message' => 'Property location saved.',
                'location' => $this->locationPayload($location),
            ]);
        }

        $property->update([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'is_verified' => true,
        ]);
        $this->storePropertyLocation($user, $listingId, $validated);

        return response()->json([
            'message' => 'Property location saved.',
            'property' => $this->propertyPayload($property->refresh()),
        ]);
    }

    public function uploadImages(Request $request, string $listingId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['required', 'image', 'max:8192'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $property = $user->sellerProperties()
            ->where('listing_id', $listingId)
            ->first();

        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $imageUrls = [];
        foreach ($request->file('images', []) as $image) {
            $path = $image->store('seller-properties/'.$listingId, 'public');
            $imageUrls[] = $this->publicStorageUrl($request, $path);
        }

        $existingNetworkUrls = collect($property->local_image_paths ?? [])
            ->filter(fn ($path) => is_string($path) && preg_match('/^https?:\/\//i', $path))
            ->values()
            ->all();
        $nextImageUrls = array_values(array_unique([...$existingNetworkUrls, ...$imageUrls]));

        $property->update([
            'image_url' => $nextImageUrls[0] ?? $property->image_url,
            'local_image_paths' => $nextImageUrls,
        ]);

        return response()->json([
            'message' => 'Property images uploaded successfully.',
            'property' => $this->propertyPayload($property->refresh()),
        ]);
    }

    public function destroy(Request $request, string $listingId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $property = $user->sellerProperties()
            ->where('listing_id', $listingId)
            ->first();

        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $property->delete();

        PropertyLocation::where('user_id', $user->id)
            ->where('listing_id', $listingId)
            ->delete();

        Storage::disk('public')->deleteDirectory('seller-properties/'.$listingId);

        return response()->json(['message' => 'Property deleted.']);
    }

    public function published(): JsonResponse
    {
        $properties = SellerProperty::query()
            ->with('user')
            ->where('is_verified', true)
            ->latest()
            ->get()
            ->map(fn (SellerProperty $property) => $this->propertyPayload($property))
            ->values();

        return response()->json(['properties' => $properties]);
    }

    public function show(string $listingId): JsonResponse
    {
        $property = SellerProperty::query()
            ->with('user')
            ->where('listing_id', $listingId)
            ->where('is_verified', true)
            ->first();

        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json(['property' => $this->propertyPayload($property)]);
    }

    private function userFor(string $email): ?User
    {
        return User::where('email', strtolower($email))->first();
    }

    private function databasePayload(array $property): array
    {
        return [
            'title' => $property['title'],
            'description' => $property['description'] ?? '',
            'price' => $property['price'],
            'type' => $property['type'],
            'bedrooms' => $property['bedrooms'],
            'bathrooms' => $property['bathrooms'],
            'region' => $property['region'] ?? '',
            'district' => $property['district'] ?? '',
            'ward' => $property['ward'] ?? '',
            'landmark' => $property['landmark'] ?? '',
            'latitude' => $property['latitude'] ?? 0,
            'longitude' => $property['longitude'] ?? 0,
            'amenities' => $property['amenities'] ?? [],
            'is_verified' => $property['isVerified'] ?? false,
            'is_featured' => $property['isFeatured'] ?? false,
            'image_url' => $property['imageUrl'] ?? '',
            'local_image_paths' => $property['localImagePaths'] ?? [],
            'local_video_path' => $property['localVideoPath'] ?? null,
        ];
    }

    private function propertyPayload(SellerProperty $property): array
    {
        return [
            'id' => $property->listing_id,
            'title' => $property->title,
            'description' => $property->description,
            'price' => $property->price,
            'type' => $property->type,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'region' => $property->region,
            'district' => $property->district,
            'ward' => $property->ward,
            'landmark' => $property->landmark,
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            'amenities' => $property->amenities ?? [],
            'isVerified' => $property->is_verified,
            'isFeatured' => $property->is_featured,
            'imageUrl' => $property->image_url ?? '',
            'localImagePaths' => $property->local_image_paths ?? [],
            'localVideoPath' => $property->local_video_path,
            'seller' => $property->user ? $this->userPayload($property->user) : null,
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_number' => $user->whatsapp_number,
            'location' => $user->location,
            'bio' => $user->bio,
            'profile_photo_url' => $this->normalizedProfilePhotoUrl($user->profile_photo_url),
            'is_online' => $this->isUserOnline($user),
            'last_seen_at' => optional($user->last_seen_at)->toIso8601String(),
        ];
    }

    private function storePropertyLocation(
        User $user,
        string $listingId,
        array $location
    ): PropertyLocation {
        return PropertyLocation::updateOrCreate(
            [
                'user_id' => $user->id,
                'listing_id' => $listingId,
            ],
            [
                'user_email' => $user->email,
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'source' => 'gps',
                'registered_at' => now(),
            ]
        );
    }

    private function locationPayload(PropertyLocation $location): array
    {
        return [
            'id' => $location->id,
            'user_id' => $location->user_id,
            'user_email' => $location->user_email,
            'listing_id' => $location->listing_id,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'source' => $location->source,
            'registered_at' => $location->registered_at,
        ];
    }

    private function publicStorageUrl(Request $request, string $path): string
    {
        return rtrim(config('app.url'), '/').Storage::url($path);
    }

    private function isUserOnline(User $user): bool
    {
        return (bool) $user->is_online &&
            $user->last_seen_at !== null &&
            $user->last_seen_at->greaterThan(now()->subMinutes(2));
    }

    private function normalizedProfilePhotoUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $trimmedUrl = trim($url);
        $appUrl = $this->publicAppUrl();
        if (str_starts_with($trimmedUrl, '/')) {
            return $appUrl.$this->normalizedProfilePhotoPath($trimmedUrl);
        }

        if (preg_match('/^https?:\/\/(127\.0\.0\.1|localhost|10\.0\.2\.2)(:\d+)?(\/.*)?$/i', $trimmedUrl, $matches)) {
            $path = $matches[3] ?? '';
            return $appUrl.$this->normalizedProfilePhotoPath($path);
        }

        $parsedUrl = parse_url($trimmedUrl);
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $path = $parsedUrl['path'] ?? '';
        if (str_contains($path, '/api/profile-photos/') || str_contains($path, '/storage/profile_photos/') || str_contains($path, '/storage/profile-photos/') || str_contains($path, '/api/storage/profile_photos/') || str_contains($path, '/api/storage/profile-photos/')) {
            return $appUrl.$this->normalizedProfilePhotoPath($path);
        }

        if (($parsedUrl['host'] ?? null) === $appHost) {
            return $appUrl.$this->normalizedProfilePhotoPath($path);
        }

        return $trimmedUrl;
    }

    private function normalizedProfilePhotoPath(string $path): string
    {
        $normalizedPath = str_starts_with($path, '/api/storage/')
            ? substr($path, 4)
            : $path;

        if (str_starts_with($normalizedPath, '/storage/profile_photos/')) {
            return '/storage/profile-photos/'.basename($normalizedPath);
        }

        if (str_starts_with($normalizedPath, '/storage/profile-photos/')) {
            return '/storage/profile-photos/'.basename($normalizedPath);
        }

        if (str_starts_with($normalizedPath, '/api/profile-photos/')) {
            return '/storage/profile-photos/'.basename($normalizedPath);
        }

        return $normalizedPath;
    }

    private function publicAppUrl(): string
    {
        $appUrl = rtrim(config('app.url'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if ($host === null || filter_var($host, FILTER_VALIDATE_IP) || str_contains($host, 'localhost')) {
            return 'https://api.nyumbadirectonline.co.tz';
        }

        return $appUrl;
    }
}

