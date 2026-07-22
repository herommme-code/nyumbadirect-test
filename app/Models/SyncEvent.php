<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncEvent extends Model
{
    protected $fillable = [
        'event_type',
        'target_email',
        'entity_type',
        'entity_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public static function record(
        string $eventType,
        array $payload = [],
        ?string $targetEmail = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): self {
        return static::create([
            'event_type' => $eventType,
            'target_email' => $targetEmail ? strtolower(trim($targetEmail)) : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
        ]);
    }

    public function visibleTo(?string $email): bool
    {
        $targetEmail = strtolower(trim((string) ($this->target_email ?? '')));
        $viewerEmail = strtolower(trim((string) ($email ?? '')));

        return $targetEmail === '' || ($viewerEmail !== '' && $targetEmail === $viewerEmail);
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'target_email' => $this->target_email,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'payload' => $this->payload ?? [],
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
