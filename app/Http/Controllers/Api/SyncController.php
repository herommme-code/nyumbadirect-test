<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'since_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $events = $this->queryEvents(
            $validated['email'] ?? null,
            (int) ($validated['since_id'] ?? 0)
        )->orderBy('id')->get()->map(fn (SyncEvent $event) => $event->toClientArray())->values();

        return response()->json(['events' => $events]);
    }

    public function stream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'since_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $email = isset($validated['email']) ? strtolower(trim($validated['email'])) : null;
        $lastEventId = (int) ($validated['since_id'] ?? $request->header('Last-Event-ID', 0));

        return response()->stream(function () use ($email, &$lastEventId) {
            @set_time_limit(0);
            @ignore_user_abort(true);

            echo ": connected\n\n";
            @ob_flush();
            @flush();

            while (! connection_aborted()) {
                $events = $this->queryEvents($email, $lastEventId)
                    ->orderBy('id')
                    ->limit(100)
                    ->get();

                foreach ($events as $event) {
                    $lastEventId = $event->id;
                    echo 'id: '.$event->id."\n";
                    echo "event: sync\n";
                    echo 'data: '.json_encode($event->toClientArray())."\n\n";
                }

                echo ": keep-alive\n\n";
                @ob_flush();
                @flush();
                usleep(500000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function queryEvents(?string $email, int $sinceId)
    {
        return SyncEvent::query()
            ->where('id', '>', $sinceId)
            ->when($email !== null && trim($email) !== '', function ($query) use ($email) {
                $query->where(function ($inner) use ($email) {
                    $inner->whereNull('target_email')
                        ->orWhereRaw('LOWER(target_email) = ?', [strtolower(trim($email))]);
                });
            });
    }
}
