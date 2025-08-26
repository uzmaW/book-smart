<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\ICalService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

class BookingService
{
    protected $googleCalendarService;
    protected $icalService;
    
    public function __construct(GoogleCalendarService $googleCalendarService, ICalService $icalService)
    {
        $this->googleCalendarService = $googleCalendarService;
        $this->icalService = $icalService;
    }
    
    /**
     * Create a new booking
     *
     * @param array $bookingData
     * @return Booking|null
     */
    public function createBooking(array $bookingData)
    {
        try {
            // Validate booking data
            $validationResult = $this->validateBookingData($bookingData);
            if (!$validationResult['valid']) {
                Log::warning('Booking validation failed', [
                    'errors' => $validationResult['errors'],
                    'booking_data' => $bookingData
                ]);
                return null;
            }
            
            $startTime = Carbon::parse($bookingData['start_time']);
            $endTime = Carbon::parse($bookingData['end_time']);
            
            // Check availability
            if (!$this->isTimeSlotAvailable($startTime, $endTime, $bookingData['provider_id'] ?? null)) {
                Log::warning('Time slot not available for booking', [
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time' => $endTime->toDateTimeString(),
                    'provider_id' => $bookingData['provider_id'] ?? null
                ]);
                return null;
            }
            
            // Create booking record
            $booking = Booking::create([
                'user_id' => $bookingData['user_id'],
                'provider_id' => $bookingData['provider_id'] ?? null,
                'title' => $bookingData['title'],
                'description' => $bookingData['description'] ?? '',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'location' => $bookingData['location'] ?? '',
                'status' => 'confirmed',
                'booking_type' => $bookingData['booking_type'] ?? 'appointment',
                'attendee_email' => $bookingData['attendee_email'] ?? '',
                'attendee_name' => $bookingData['attendee_name'] ?? '',
                'notes' => $bookingData['notes'] ?? '',
            ]);
            
            // Create Google Calendar event if enabled
            if ($bookingData['sync_google_calendar'] ?? true) {
                $this->syncBookingToGoogleCalendar($booking);
            }
            
            Log::info('Booking created successfully', ['booking_id' => $booking->id]);
            
            return $booking;
            
        } catch (Exception $e) {
            Log::error('Failed to create booking', [
                'error' => $e->getMessage(),
                'booking_data' => $bookingData
            ]);
            
            return null;
        }
    }
    
    /**
     * Update an existing booking
     *
     * @param Booking $booking
     * @param array $updateData
     * @return bool
     */
    public function updateBooking(Booking $booking, array $updateData)
    {
        try {
            $originalStartTime = $booking->start_time;
            $originalEndTime = $booking->end_time;
            
            // Check if time is being changed
            $timeChanged = false;
            if (isset($updateData['start_time']) || isset($updateData['end_time'])) {
                $newStartTime = Carbon::parse($updateData['start_time'] ?? $booking->start_time);
                $newEndTime = Carbon::parse($updateData['end_time'] ?? $booking->end_time);
                
                if (!$newStartTime->eq($originalStartTime) || !$newEndTime->eq($originalEndTime)) {
                    $timeChanged = true;
                    
                    // Check availability for new time slot (excluding current booking)
                    if (!$this->isTimeSlotAvailable($newStartTime, $newEndTime, $booking->provider_id, $booking->id)) {
                        Log::warning('New time slot not available for booking update', [
                            'booking_id' => $booking->id,
                            'new_start_time' => $newStartTime->toDateTimeString(),
                            'new_end_time' => $newEndTime->toDateTimeString()
                        ]);
                        return false;
                    }
                }
            }
            
            // Update booking
            $booking->update($updateData);
            
            // Update Google Calendar event if time changed
            if ($timeChanged && $booking->google_event_id) {
                $this->updateGoogleCalendarEvent($booking);
            }
            
            Log::info('Booking updated successfully', ['booking_id' => $booking->id]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to update booking', [
                'error' => $e->getMessage(),
                'booking_id' => $booking->id,
                'update_data' => $updateData
            ]);
            
            return false;
        }
    }
    
    /**
     * Cancel a booking
     *
     * @param Booking $booking
     * @param string $reason
     * @return bool
     */
    public function cancelBooking(Booking $booking, string $reason = '')
    {
        try {
            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => Carbon::now()
            ]);
            
            // Delete from Google Calendar if exists
            if ($booking->google_event_id) {
                $this->googleCalendarService->deleteEvent($booking->google_event_id);
            }
            
            Log::info('Booking cancelled successfully', [
                'booking_id' => $booking->id,
                'reason' => $reason
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to cancel booking', [
                'error' => $e->getMessage(),
                'booking_id' => $booking->id
            ]);
            
            return false;
        }
    }
    
    /**
     * Check if a time slot is available
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param int|null $providerId
     * @param int|null $excludeBookingId
     * @return bool
     */
    public function isTimeSlotAvailable(Carbon $startTime, Carbon $endTime, int $providerId = null, int $excludeBookingId = null)
    {
        try {
            // Check database for conflicting bookings
            $query = Booking::where('status', '!=', 'cancelled')
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->where(function ($subQ) use ($startTime, $endTime) {
                        $subQ->where('start_time', '<', $endTime)
                             ->where('end_time', '>', $startTime);
                    });
                });
            
            if ($providerId) {
                $query->where('provider_id', $providerId);
            }
            
            if ($excludeBookingId) {
                $query->where('id', '!=', $excludeBookingId);
            }
            
            $conflictingBookings = $query->count();
            
            if ($conflictingBookings > 0) {
                Log::info('Time slot conflict found in database', [
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time' => $endTime->toDateTimeString(),
                    'provider_id' => $providerId,
                    'conflicting_bookings' => $conflictingBookings
                ]);
                return false;
            }
            
            // Check Google Calendar for conflicts if enabled
            if (config('google-calendar.calendar_id')) {
                $googleAvailable = $this->googleCalendarService->isTimeSlotAvailable($startTime, $endTime);
                if (!$googleAvailable) {
                    return false;
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to check time slot availability', [
                'error' => $e->getMessage(),
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get available time slots for a given date
     *
     * @param Carbon $date
     * @param int $slotDurationMinutes
     * @param Carbon|null $workingHoursStart
     * @param Carbon|null $workingHoursEnd
     * @param int|null $providerId
     * @return array
     */
    public function getAvailableTimeSlots(
        Carbon $date, 
        int $slotDurationMinutes = 60, 
        Carbon $workingHoursStart = null, 
        Carbon $workingHoursEnd = null,
        int $providerId = null
    ) {
        $workingHoursStart = $workingHoursStart ?? $date->copy()->setTime(9, 0);
        $workingHoursEnd = $workingHoursEnd ?? $date->copy()->setTime(17, 0);
        
        $availableSlots = [];
        $currentSlot = $workingHoursStart->copy();
        
        while ($currentSlot->copy()->addMinutes($slotDurationMinutes)->lte($workingHoursEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($slotDurationMinutes);
            
            if ($this->isTimeSlotAvailable($currentSlot, $slotEnd, $providerId)) {
                $availableSlots[] = [
                    'start' => $currentSlot->toDateTimeString(),
                    'end' => $slotEnd->toDateTimeString(),
                    'formatted_time' => $currentSlot->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    'available' => true
                ];
            }
            
            $currentSlot->addMinutes($slotDurationMinutes);
        }
        
        return $availableSlots;
    }
    
    /**
     * Get bookings for a specific date range
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int|null $userId
     * @param int|null $providerId
     * @return Collection
     */
    public function getBookings(Carbon $startDate, Carbon $endDate, int $userId = null, int $providerId = null)
    {
        $query = Booking::whereBetween('start_time', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time');
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($providerId) {
            $query->where('provider_id', $providerId);
        }
        
        return $query->get();
    }
    
    /**
     * Validate booking data
     *
     * @param array $bookingData
     * @return array
     */
    private function validateBookingData(array $bookingData)
    {
        $errors = [];
        
        // Required fields
        $requiredFields = ['user_id', 'title', 'start_time', 'end_time'];
        foreach ($requiredFields as $field) {
            if (!isset($bookingData[$field]) || empty($bookingData[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }
        
        // Validate dates
        if (isset($bookingData['start_time']) && isset($bookingData['end_time'])) {
            try {
                $startTime = Carbon::parse($bookingData['start_time']);
                $endTime = Carbon::parse($bookingData['end_time']);
                
                if ($endTime->lte($startTime)) {
                    $errors[] = 'End time must be after start time';
                }
                
                if ($startTime->lt(Carbon::now())) {
                    $errors[] = 'Start time cannot be in the past';
                }
                
            } catch (Exception $e) {
                $errors[] = 'Invalid date format';
            }
        }
        
        // Validate email if provided
        if (isset($bookingData['attendee_email']) && !empty($bookingData['attendee_email'])) {
            if (!filter_var($bookingData['attendee_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Sync booking to Google Calendar
     *
     * @param Booking $booking
     * @return void
     */
    private function syncBookingToGoogleCalendar(Booking $booking)
    {
        try {
            $eventData = [
                'title' => $booking->title,
                'description' => $booking->description,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'location' => $booking->location,
            ];
            
            if ($booking->attendee_email) {
                $eventData['attendees'] = [$booking->attendee_email];
            }
            
            $event = $this->googleCalendarService->createEvent($eventData);
            
            if ($event) {
                $booking->update(['google_event_id' => $event->id]);
                Log::info('Booking synced to Google Calendar', [
                    'booking_id' => $booking->id,
                    'google_event_id' => $event->id
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to sync booking to Google Calendar', [
                'error' => $e->getMessage(),
                'booking_id' => $booking->id
            ]);
        }
    }
    
    /**
     * Update Google Calendar event
     *
     * @param Booking $booking
     * @return void
     */
    private function updateGoogleCalendarEvent(Booking $booking)
    {
        try {
            $eventData = [
                'title' => $booking->title,
                'description' => $booking->description,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'location' => $booking->location,
            ];
            
            $this->googleCalendarService->updateEvent($booking->google_event_id, $eventData);
            
            Log::info('Google Calendar event updated', [
                'booking_id' => $booking->id,
                'google_event_id' => $booking->google_event_id
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to update Google Calendar event', [
                'error' => $e->getMessage(),
                'booking_id' => $booking->id
            ]);
        }
    }
    
    /**
     * Export bookings to iCal format
     *
     * @param Collection $bookings
     * @param string $calendarName
     * @return string
     */
    public function exportBookingsToICal(Collection $bookings, string $calendarName = 'My Bookings')
    {
        $events = $bookings->map(function ($booking) {
            return [
                'uid' => 'booking-' . $booking->id . '@calendar-booking-app.com',
                'title' => $booking->title,
                'description' => $booking->description,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'location' => $booking->location,
                'organizer_email' => $booking->user->email ?? 'noreply@calendar-booking-app.com',
                'organizer_name' => $booking->user->name ?? 'Calendar Booking App',
                'status' => $booking->status,
            ];
        })->toArray();
        
        return $this->icalService->generateICalFile($events, $calendarName);
    }
}

