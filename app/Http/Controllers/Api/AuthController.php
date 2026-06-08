<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

        $user->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'location' => $validated['location'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'profile_photo_url' => $profilePhotoUrl,
        ]);

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

        $path = $request->file('photo')->store('profile-photos', 'public');
        $user->update([
            'profile_photo_url' => $this->publicStorageUrl($request, $path),
        ]);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
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
        return rtrim(config('app.url'), '/').Storage::url($path);
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
        $appUrl = rtrim(config('app.url'), '/');
        if (str_starts_with($trimmedUrl, '/')) {
            return $appUrl.$this->normalizedStoragePath($trimmedUrl);
        }

        if (preg_match('/^https?:\/\/(127\.0\.0\.1|localhost|10\.0\.2\.2)(:\d+)?(\/.*)?$/i', $trimmedUrl, $matches)) {
            $path = $matches[3] ?? '';
            return $appUrl.$this->normalizedStoragePath($path);
        }

        $parsedUrl = parse_url($trimmedUrl);
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $path = $parsedUrl['path'] ?? '';
        if (($parsedUrl['host'] ?? null) === $appHost && str_starts_with($path, '/api/storage/')) {
            return $appUrl.$this->normalizedStoragePath($path);
        }

        return $trimmedUrl;
    }

    private function normalizedStoragePath(string $path): string
    {
        return str_starts_with($path, '/api/storage/')
            ? substr($path, 4)
            : $path;
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


