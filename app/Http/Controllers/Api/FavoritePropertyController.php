<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FavoriteProperty;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoritePropertyController extends Controller
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

        return response()->json([
            'favorites' => $user->favoriteProperties()
                ->pluck('listing_id')
                ->values(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'favorites' => ['present', 'array'],
            'favorites.*' => ['string', 'max:255'],
        ]);

        $user = $this->userFor($validated['email']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $incomingIds = collect($validated['favorites'])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $user->favoriteProperties()
            ->whereNotIn('listing_id', $incomingIds)
            ->delete();

        foreach ($incomingIds as $listingId) {
            FavoriteProperty::firstOrCreate([
                'user_id' => $user->id,
                'listing_id' => $listingId,
            ]);
        }

        SyncEvent::record(
            'favorites.synced',
            [
                'email' => $user->email,
                'favorites' => $incomingIds->values()->all(),
            ],
            $user->email,
            'favorites',
            (string) $user->id
        );

        return response()->json([
            'message' => 'Favorites saved.',
            'favorites' => $incomingIds,
        ]);
    }

    private function userFor(string $email): ?User
    {
        return User::where('email', strtolower($email))->first();
    }
}
