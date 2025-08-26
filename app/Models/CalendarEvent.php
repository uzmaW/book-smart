<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'external_id',
        'source',
        'title',
        'description',
        'start_time',
        'end_time',
        'location',
        'attendees',
        'status',
        'recurrence_rule',
        'reminders',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'attendees' => 'array',
        'reminders' => 'array',
    ];

    protected $dates = [
        'start_time',
        'end_time',
    ];

    /**
     * Get the user that owns the calendar event
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include events from a specific source
     */
    public function scopeFromSource($query, $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope a query to only include events for a specific date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include upcoming events
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }

    /**
     * Scope a query to only include past events
     */
    public function scopePast($query)
    {
        return $query->where('end_time', '<', Carbon::now());
    }

    /**
     * Scope a query to only include active events
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /**
     * Check if the event is in the past
     */
    public function isPast(): bool
    {
        return $this->end_time->lt(Carbon::now());
    }

    /**
     * Check if the event is upcoming
     */
    public function isUpcoming(): bool
    {
        return $this->start_time->gt(Carbon::now());
    }

    /**
     * Check if the event is currently active
     */
    public function isActive(): bool
    {
        $now = Carbon::now();
        return $this->start_time->lte($now) && $this->end_time->gte($now);
    }

    /**
     * Get the duration of the event in minutes
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

    /**
     * Check if the event has attendees
     */
    public function hasAttendees(): bool
    {
        return !empty($this->attendees);
    }

    /**
     * Get the number of attendees
     */
    public function getAttendeesCount(): int
    {
        return count($this->attendees ?? []);
    }

    /**
     * Check if the event has reminders
     */
    public function hasReminders(): bool
    {
        return !empty($this->reminders);
    }

    /**
     * Check if the event is recurring
     */
    public function isRecurring(): bool
    {
        return !empty($this->recurrence_rule);
    }
}
