<?php

namespace App\Services;

use Spatie\GoogleCalendar\Event;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    /**
     * Create a new event in Google Calendar
     *
     * @param array $eventData
     * @return Event|null
     */
    public function createEvent(array $eventData)
    {
        try {
            $event = new Event;
            
            $event->name = $eventData['title'];
            $event->description = $eventData['description'] ?? '';
            $event->startDateTime = Carbon::parse($eventData['start_time']);
            $event->endDateTime = Carbon::parse($eventData['end_time']);
            
            // Set location if provided
            if (isset($eventData['location'])) {
                $event->location = $eventData['location'];
            }
            
            // Add attendees if provided
            if (isset($eventData['attendees']) && is_array($eventData['attendees'])) {
                $event->addAttendee($eventData['attendees']);
            }
            
            // Set reminder if provided
            if (isset($eventData['reminder_minutes'])) {
                $event->addEmailReminder($eventData['reminder_minutes']);
            }
            
            $event->save();
            
            Log::info('Google Calendar event created successfully', ['event_id' => $event->id]);
            
            return $event;
            
        } catch (Exception $e) {
            Log::error('Failed to create Google Calendar event', [
                'error' => $e->getMessage(),
                'event_data' => $eventData
            ]);
            
            return null;
        }
    }
    
    /**
     * Get events from Google Calendar
     *
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @param int $maxResults
     * @return \Illuminate\Support\Collection
     */
    public function getEvents(Carbon $startDate = null, Carbon $endDate = null, int $maxResults = 50)
    {
        try {
            $startDate = $startDate ?? Carbon::now();
            $endDate = $endDate ?? Carbon::now()->addMonth();
            
            $events = Event::get($startDate, $endDate, [], $maxResults);
            
            Log::info('Retrieved Google Calendar events', [
                'count' => $events->count(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]);
            
            return $events;
            
        } catch (Exception $e) {
            Log::error('Failed to retrieve Google Calendar events', [
                'error' => $e->getMessage(),
                'start_date' => $startDate?->toDateString(),
                'end_date' => $endDate?->toDateString()
            ]);
            
            return collect();
        }
    }
    
    /**
     * Update an existing event in Google Calendar
     *
     * @param string $eventId
     * @param array $eventData
     * @return Event|null
     */
    public function updateEvent(string $eventId, array $eventData)
    {
        try {
            $event = Event::find($eventId);
            
            if (!$event) {
                Log::warning('Google Calendar event not found for update', ['event_id' => $eventId]);
                return null;
            }
            
            // Update event properties
            if (isset($eventData['title'])) {
                $event->name = $eventData['title'];
            }
            
            if (isset($eventData['description'])) {
                $event->description = $eventData['description'];
            }
            
            if (isset($eventData['start_time'])) {
                $event->startDateTime = Carbon::parse($eventData['start_time']);
            }
            
            if (isset($eventData['end_time'])) {
                $event->endDateTime = Carbon::parse($eventData['end_time']);
            }
            
            if (isset($eventData['location'])) {
                $event->location = $eventData['location'];
            }
            
            $event->save();
            
            Log::info('Google Calendar event updated successfully', ['event_id' => $eventId]);
            
            return $event;
            
        } catch (Exception $e) {
            Log::error('Failed to update Google Calendar event', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
                'event_data' => $eventData
            ]);
            
            return null;
        }
    }
    
    /**
     * Delete an event from Google Calendar
     *
     * @param string $eventId
     * @return bool
     */
    public function deleteEvent(string $eventId)
    {
        try {
            $event = Event::find($eventId);
            
            if (!$event) {
                Log::warning('Google Calendar event not found for deletion', ['event_id' => $eventId]);
                return false;
            }
            
            $event->delete();
            
            Log::info('Google Calendar event deleted successfully', ['event_id' => $eventId]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to delete Google Calendar event', [
                'error' => $e->getMessage(),
                'event_id' => $eventId
            ]);
            
            return false;
        }
    }
    
    /**
     * Check if a time slot is available (no conflicting events)
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return bool
     */
    public function isTimeSlotAvailable(Carbon $startTime, Carbon $endTime)
    {
        try {
            $events = $this->getEvents($startTime->copy()->subHour(), $endTime->copy()->addHour());
            
            foreach ($events as $event) {
                $eventStart = Carbon::parse($event->startDateTime);
                $eventEnd = Carbon::parse($event->endDateTime);
                
                // Check for overlap
                if ($startTime->lt($eventEnd) && $endTime->gt($eventStart)) {
                    Log::info('Time slot conflict detected', [
                        'requested_start' => $startTime->toDateTimeString(),
                        'requested_end' => $endTime->toDateTimeString(),
                        'conflicting_event' => $event->name,
                        'event_start' => $eventStart->toDateTimeString(),
                        'event_end' => $eventEnd->toDateTimeString()
                    ]);
                    
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
     * @param Carbon $workingHoursStart
     * @param Carbon $workingHoursEnd
     * @return array
     */
    public function getAvailableTimeSlots(
        Carbon $date, 
        int $slotDurationMinutes = 60, 
        Carbon $workingHoursStart = null, 
        Carbon $workingHoursEnd = null
    ) {
        $workingHoursStart = $workingHoursStart ?? $date->copy()->setTime(9, 0);
        $workingHoursEnd = $workingHoursEnd ?? $date->copy()->setTime(17, 0);
        
        $availableSlots = [];
        $currentSlot = $workingHoursStart->copy();
        
        while ($currentSlot->copy()->addMinutes($slotDurationMinutes)->lte($workingHoursEnd)) {
            $slotEnd = $currentSlot->copy()->addMinutes($slotDurationMinutes);
            
            if ($this->isTimeSlotAvailable($currentSlot, $slotEnd)) {
                $availableSlots[] = [
                    'start' => $currentSlot->toDateTimeString(),
                    'end' => $slotEnd->toDateTimeString(),
                    'formatted_time' => $currentSlot->format('H:i') . ' - ' . $slotEnd->format('H:i')
                ];
            }
            
            $currentSlot->addMinutes($slotDurationMinutes);
        }
        
        return $availableSlots;
    }
}

