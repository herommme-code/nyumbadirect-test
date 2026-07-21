<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:6', 'max:255'],
            ]);

            $user = User::create([
                'name' => 'Nyumbadirect Guest',
                'email' => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'location' => 'Dar es Salaam',
                'bio' => 'Looking for verified rental homes.',
            ]);

            return response()->json([
                'message' => 'Account created successfully.',
                'user' => $this->userPayload($user),
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException) {
            return response()->json([
                'message' => 'We could not create your account right now. Please try again.',
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $this->markUserOnline($user);

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $googleClientId = config('services.google.client_id');
        if (! is_string($googleClientId) || trim($googleClientId) === '') {
            return response()->json([
                'message' => 'Google Sign-In is not configured.',
            ], 500);
        }

        $googleUser = $this->verifyGoogleIdToken($validated['id_token'], $googleClientId);
        if ($googleUser === null) {
            return response()->json([
                'message' => 'Invalid Google sign-in token.',
            ], 401);
        }

        $email = strtolower($googleUser['email'] ?? '');
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'Google account did not include a valid email address.',
            ], 422);
        }

        if (! filter_var($googleUser['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'message' => 'Google account email is not verified.',
            ], 422);
        }

        try {
            $googleId = $googleUser['sub'] ?? null;
            $hasGoogleAuthColumns = $this->hasGoogleAuthColumns();
            $user = User::query()
                ->when(
                    $hasGoogleAuthColumns && $googleId,
                    fn ($query) => $query->orWhere('google_id', $googleId)
                )
                ->orWhere('email', $email)
                ->first();

            if ($user) {
                $updates = [
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ];

                if ($hasGoogleAuthColumns) {
                    $updates['google_id'] = $googleId;
                    $updates['auth_provider'] = 'google';
                }

                if (! empty($googleUser['picture'])) {
                    $updates['profile_photo_url'] = $this->normalizedProfilePhotoUrl($googleUser['picture']);
                }

                $user->forceFill($updates)->save();
            } else {
                $attributes = [
                    'name' => $googleUser['name'] ?? 'Nyumbadirect Guest',
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(40)),
                    'location' => 'Dar es Salaam',
                    'bio' => 'Looking for verified rental homes.',
                    'profile_photo_url' => ! empty($googleUser['picture'])
                        ? $this->normalizedProfilePhotoUrl($googleUser['picture'])
                        : null,
                ];

                if ($hasGoogleAuthColumns) {
                    $attributes['google_id'] = $googleId;
                    $attributes['auth_provider'] = 'google';
                }

                $user = User::create($attributes);
            }

            $this->markUserOnline($user);

            return response()->json([
                'message' => 'Logged in successfully.',
                'user' => $this->userPayload($user->refresh()),
            ]);
        } catch (QueryException $exception) {
            Log::error('Google Sign-In database error.', [
                'email' => $email,
                'sql_error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not log you in with Google right now. Please try again.',
            ], 500);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $this->markUserOnline($user);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'profile_photo_url' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $profilePhotoUrl = $this->profilePhotoUrlForUpdate(
            $validated['profile_photo_url'] ?? null,
            $user->profile_photo_url
        );

        $updates = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'location' => $validated['location'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'profile_photo_url' => $profilePhotoUrl,
        ];

        if ($profilePhotoUrl === null) {
            $this->deleteStoredProfilePhoto($user);
            $updates['profile_photo_filename'] = null;
            $updates['profile_photo_mime'] = null;
            $updates['profile_photo_data'] = null;
        }

        $user->update($updates);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $photo = $request->file('photo');
        $this->deleteStoredProfilePhoto($user);

        $path = $photo->store('profile-photos', 'public');

        $user->update([
            'profile_photo_url' => $this->publicStorageUrl($request, $path),
            'profile_photo_filename' => basename($path),
            'profile_photo_mime' => $photo->getMimeType() ?: 'image/jpeg',
            'profile_photo_data' => base64_encode(file_get_contents($photo->getRealPath())),
        ]);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'user' => $this->userPayload($user->refresh()),
        ]);
    }

    public function removeProfilePhoto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $this->deleteStoredProfilePhoto($user);
        $user->update([
            'profile_photo_url' => null,
            'profile_photo_filename' => null,
            'profile_photo_mime' => null,
            'profile_photo_data' => null,
        ]);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'user' => $this->userPayload($user->refresh()),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $currentEmail = strtolower($validated['email'] ?? '');
        if ($currentEmail !== '') {
            $currentUser = User::where('email', $currentEmail)->first();
            if ($currentUser) {
                $this->markUserOnline($currentUser);
            }
        }
        $users = User::query()
            ->when($currentEmail !== '', fn ($query) => $query->where('email', '!=', $currentEmail))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->userPayload($user))
            ->values();

        return response()->json(['users' => $users]);
    }

    public function presence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'online' => ['required', 'boolean'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated['online']
            ? $this->markUserOnline($user)
            : $this->markUserOffline($user);

        return response()->json([
            'message' => $validated['online'] ? 'User is online.' : 'User is offline.',
            'user' => $this->userPayload($user->refresh()),
        ]);
    }

    public function profilePhoto(string $filename)
    {
        $safeFilename = basename($filename);

        if ($safeFilename === '') {
            abort(404);
        }

        foreach (['profile_photos', 'profile-photos'] as $directory) {
            $path = $directory.'/'.$safeFilename;
            if (Storage::disk('public')->exists($path)) {
                return response()->file(Storage::disk('public')->path($path), [
                    'Cache-Control' => 'public, max-age=604800',
                ]);
            }
        }

        $user = User::where('profile_photo_filename', $safeFilename)->first();
        if (! $user || empty($user->profile_photo_data)) {
            abort(404);
        }

        $photoData = base64_decode($user->profile_photo_data, true);
        if ($photoData === false) {
            abort(404);
        }

        return response($photoData, 200, [
            'Content-Type' => $user->profile_photo_mime ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    private function verifyGoogleIdToken(string $idToken, string $googleClientId): ?array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['aud'] ?? null) !== $googleClientId) {
            return null;
        }

        if (! isset($payload['sub'])) {
            return null;
        }

        return $payload;
    }

    private function hasGoogleAuthColumns(): bool
    {
        try {
            return Schema::hasColumn('users', 'google_id') &&
                Schema::hasColumn('users', 'auth_provider');
        } catch (\Throwable $exception) {
            Log::error('Could not inspect Google auth columns.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_number' => $user->whatsapp_number,
            'location' => $user->location,
            'bio' => $user->bio,
            'profile_photo_url' => $this->normalizedProfilePhotoUrl($user->profile_photo_url),
            'is_online' => $this->isUserOnline($user),
            'last_seen_at' => optional($user->last_seen_at)->toIso8601String(),
            'created_at' => $user->created_at,
        ];
    }

    private function publicStorageUrl(Request $request, string $path): string
    {
        return $this->publicAppUrl().Storage::url($path);
    }

    private function profilePhotoUrlForUpdate(?string $nextUrl, ?string $currentUrl): ?string
    {
        if ($nextUrl === null) {
            return $currentUrl;
        }

        $trimmedUrl = trim($nextUrl);
        if ($trimmedUrl === '') {
            return null;
        }

        return preg_match('/^https?:\/\//i', $trimmedUrl)
            ? $this->normalizedProfilePhotoUrl($trimmedUrl)
            : $currentUrl;
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

    private function deleteStoredProfilePhoto(User $user): void
    {
        $filenames = array_filter([
            $user->profile_photo_filename,
            $this->profilePhotoFilenameFromUrl($user->profile_photo_url),
        ]);

        foreach (array_unique($filenames) as $filename) {
            foreach (['profile_photos', 'profile-photos'] as $directory) {
                Storage::disk('public')->delete($directory.'/'.basename($filename));
            }
        }
    }

    private function profilePhotoFilenameFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH) ?: trim($url);
        if (
            str_contains($path, '/profile-photos/') ||
            str_contains($path, '/profile_photos/')
        ) {
            return basename($path);
        }

        return null;
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

    private function markUserOnline(User $user): void
    {
        $user->forceFill([
            'is_online' => true,
            'last_seen_at' => now(),
        ])->save();
    }

    private function markUserOffline(User $user): void
    {
        $user->forceFill([
            'is_online' => false,
            'last_seen_at' => now(),
        ])->save();
    }

    private function isUserOnline(User $user): bool
    {
        return (bool) $user->is_online &&
            $user->last_seen_at !== null &&
            $user->last_seen_at->greaterThan(now()->subMinutes(2));
    }
}


