<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->findUser($request);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $this->markUserOnline($user);

        $conversations = Conversation::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
            })
            ->with(['messages', 'user', 'recipient'])
            ->latest('updated_at')
            ->get()
            ->map(fn (Conversation $conversation) => $this->conversationPayload($conversation, $user))
            ->filter(
                fn (array $conversation) => ! $conversation['deleted_for_viewer']
                    || count($conversation['messages']) > 0
            )
            ->map(function (array $conversation) {
                unset($conversation['deleted_for_viewer']);

                return $conversation;
            })
            ->values();

        return response()->json(['conversations' => $conversations]);
    }

    public function storeMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'listing_id' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $sender = User::where('email', strtolower($validated['email']))->first();

        if (! $sender) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $this->markUserOnline($sender);

        $recipient = null;
        if (! empty($validated['recipient_email'])) {
            $recipient = User::where('email', strtolower($validated['recipient_email']))->first();
            if (! $recipient) {
                return response()->json(['message' => 'Recipient not found.'], 404);
            }
            if ($recipient->id === $sender->id) {
                return response()->json(['message' => 'You cannot message yourself.'], 422);
            }
        }

        $listingId = $validated['listing_id'] ?? null;
        $conversation = $recipient
            ? $this->findOrCreateDirectConversation($sender, $recipient, $listingId)
            : Conversation::firstOrCreate([
                'user_id' => $sender->id,
                'listing_id' => $listingId ?? 'property-'.$sender->id,
            ]);

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $validated['body'],
            'from_user' => true,
            'sent_at' => now(),
        ]);

        $this->restoreConversationForUser($conversation, $sender);
        $conversation->touch();

        return response()->json([
            'message' => $this->messagePayload($message, $sender),
            'conversation' => $this->conversationPayload(
                $conversation->load(['messages', 'user', 'recipient']),
                $sender,
            ),
        ], 201);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->findUser($request);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $this->canAccessConversation($conversation, $user)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if ($conversation->user_id === $user->id) {
            $conversation->forceFill(['user_deleted_at' => now()])->save();
        } elseif ($conversation->recipient_id === $user->id) {
            $conversation->forceFill(['recipient_deleted_at' => now()])->save();
        }

        return response()->json(['message' => 'Conversation removed from your inbox.']);
    }

    public function destroyMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $user = $this->findUser($request);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $conversation = $message->conversation;

        if (! $conversation || ! $this->canAccessConversation($conversation, $user)) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->delete();
        $conversation->touch();

        return response()->json(['message' => 'Message deleted.']);
    }

    private function findUser(Request $request): ?User
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();
        if ($user) {
            $this->markUserOnline($user);
        }

        return $user;
    }

    private function findOrCreateDirectConversation(
        User $sender,
        User $recipient,
        ?string $listingId,
    ): Conversation {
        $firstId = min($sender->id, $recipient->id);
        $secondId = max($sender->id, $recipient->id);
        $contextId = $listingId ?? "direct-{$firstId}-{$secondId}";

        $conversation = Conversation::query()
            ->where('listing_id', $contextId)
            ->where(function ($query) use ($sender, $recipient) {
                $query->where(function ($query) use ($sender, $recipient) {
                    $query->where('user_id', $sender->id)
                        ->where('recipient_id', $recipient->id);
                })->orWhere(function ($query) use ($sender, $recipient) {
                    $query->where('user_id', $recipient->id)
                        ->where('recipient_id', $sender->id);
                });
            })
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return Conversation::create([
            'user_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'listing_id' => $contextId,
        ]);
    }

    private function canAccessConversation(Conversation $conversation, User $user): bool
    {
        return $conversation->user_id === $user->id
            || $conversation->recipient_id === $user->id;
    }

    private function restoreConversationForUser(Conversation $conversation, User $user): void
    {
        if ($conversation->user_id === $user->id) {
            $conversation->forceFill(['user_deleted_at' => null]);
        } elseif ($conversation->recipient_id === $user->id) {
            $conversation->forceFill(['recipient_deleted_at' => null]);
        }
    }

    private function deletedAtForViewer(Conversation $conversation, User $viewer): mixed
    {
        if ($conversation->user_id === $viewer->id) {
            return $conversation->user_deleted_at;
        }

        if ($conversation->recipient_id === $viewer->id) {
            return $conversation->recipient_deleted_at;
        }

        return null;
    }

    private function conversationPayload(Conversation $conversation, User $viewer): array
    {
        $otherUser = $conversation->recipient_id === $viewer->id
            ? $conversation->user
            : $conversation->recipient;

        $deletedAt = $this->deletedAtForViewer($conversation, $viewer);
        $messages = $conversation->messages;

        if ($deletedAt) {
            $messages = $messages->filter(
                fn ($message) => optional($message->sent_at)->greaterThan($deletedAt)
                    && (int) $message->sender_id !== (int) $viewer->id
            );
        }

        return [
            'id' => $conversation->id,
            'listing_id' => $conversation->listing_id,
            'other_user' => $otherUser ? $this->userPayload($otherUser) : null,
            'deleted_for_viewer' => $deletedAt !== null,
            'messages' => $messages
                ->map(fn ($message) => $this->messagePayload($message, $viewer))
                ->values(),
        ];
    }

    private function messagePayload($message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'from_user' => $message->sender_id
                ? $message->sender_id === $viewer->id
                : (bool) $message->from_user,
            'sender_id' => $message->sender_id,
            'sent_at' => optional($message->sent_at)->toIso8601String(),
        ];
    }

    private function userPayload(User $user): array
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
        ];
    }

    private function markUserOnline(User $user): void
    {
        $user->forceFill([
            'is_online' => true,
            'last_seen_at' => now(),
        ])->save();
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
        $appUrl = rtrim(config('app.url'), '/');
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
        if (str_contains($path, '/api/profile-photos/') || str_contains($path, '/storage/profile-photos/') || str_contains($path, '/api/storage/profile-photos/')) {
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

        if (str_starts_with($normalizedPath, '/storage/profile-photos/')) {
            return '/api/profile-photos/'.basename($normalizedPath);
        }

        if (str_starts_with($normalizedPath, '/api/profile-photos/')) {
            return '/api/profile-photos/'.basename($normalizedPath);
        }

        return $normalizedPath;
    }
}

