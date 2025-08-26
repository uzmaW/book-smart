<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class CalendarIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'external_calendar_id',
        'credentials',
        'settings',
        'is_active',
        'sync_enabled',
        'sync_direction',
        'last_sync_at',
        'sync_status',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'sync_status' => 'array',
        'is_active' => 'boolean',
        'sync_enabled' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    protected $dates = [
        'last_sync_at',
    ];

    /**
     * Get the user that owns the calendar integration
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include active integrations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include integrations with sync enabled
     */
    public function scopeSyncEnabled($query)
    {
        return $query->where('sync_enabled', true);
    }

    /**
     * Scope a query to only include integrations of a specific type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Check if the integration is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if sync is enabled
     */
    public function isSyncEnabled(): bool
    {
        return $this->sync_enabled && $this->is_active;
    }

    /**
     * Check if the integration supports import
     */
    public function supportsImport(): bool
    {
        return in_array($this->sync_direction, ['import', 'bidirectional']);
    }

    /**
     * Check if the integration supports export
     */
    public function supportsExport(): bool
    {
        return in_array($this->sync_direction, ['export', 'bidirectional']);
    }

    /**
     * Get the last sync status
     */
    public function getLastSyncStatus(): ?array
    {
        return $this->sync_status;
    }

    /**
     * Check if the last sync was successful
     */
    public function wasLastSyncSuccessful(): bool
    {
        $status = $this->getLastSyncStatus();
        return $status && ($status['status'] ?? '') === 'success';
    }

    /**
     * Get the time since last sync
     */
    public function getTimeSinceLastSync(): ?string
    {
        if (!$this->last_sync_at) {
            return null;
        }

        return $this->last_sync_at->diffForHumans();
    }

    /**
     * Update sync status
     */
    public function updateSyncStatus(string $status, ?string $message = null, ?array $details = null): void
    {
        $this->update([
            'last_sync_at' => Carbon::now(),
            'sync_status' => [
                'status' => $status,
                'message' => $message,
                'details' => $details,
                'timestamp' => Carbon::now()->toISOString(),
            ],
        ]);
    }

    /**
     * Mark sync as successful
     */
    public function markSyncSuccessful(?string $message = null, ?array $details = null): void
    {
        $this->updateSyncStatus('success', $message, $details);
    }

    /**
     * Mark sync as failed
     */
    public function markSyncFailed(string $message, ?array $details = null): void
    {
        $this->updateSyncStatus('failed', $message, $details);
    }
}
