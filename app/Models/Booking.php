<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'location',
        'status',
        'booking_type',
        'attendee_email',
        'attendee_name',
        'notes',
        'google_event_id',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $dates = [
        'start_time',
        'end_time',
        'cancelled_at',
    ];

    /**
     * Get the user that owns the booking
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the provider for the booking
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * Scope a query to only include active bookings
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /**
     * Scope a query to only include bookings for a specific date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include upcoming bookings
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }

    /**
     * Scope a query to only include past bookings
     */
    public function scopePast($query)
    {
        return $query->where('end_time', '<', Carbon::now());
    }

    /**
     * Check if the booking is in the past
     */
    public function isPast(): bool
    {
        return $this->end_time->lt(Carbon::now());
    }

    /**
     * Check if the booking is upcoming
     */
    public function isUpcoming(): bool
    {
        return $this->start_time->gt(Carbon::now());
    }

    /**
     * Check if the booking is currently active
     */
    public function isActive(): bool
    {
        $now = Carbon::now();
        return $this->start_time->lte($now) && $this->end_time->gte($now);
    }

    /**
     * Check if the booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->status !== 'cancelled' && $this->isUpcoming();
    }

    /**
     * Check if the booking can be modified
     */
    public function canBeModified(): bool
    {
        return $this->status !== 'cancelled' && $this->isUpcoming();
    }

    /**
     * Get the duration of the booking in minutes
     */
    public function getDurationInMinutes(): int
    {
        return $this->start_time->diffInMinutes($this->end_time);
    }

    /**
     * Get formatted duration string
     */
    public function getFormattedDuration(): string
    {
        $minutes = $this->getDurationInMinutes();
        
        if ($minutes < 60) {
            return $minutes . ' minutes';
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($remainingMinutes === 0) {
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ' . $remainingMinutes . ' minutes';
    }
}
