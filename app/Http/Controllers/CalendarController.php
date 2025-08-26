<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use App\Services\ICalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Carbon\Carbon;
use Exception;

class CalendarController extends Controller
{
    protected $googleCalendarService;
    protected $icalService;
    
    public function __construct(GoogleCalendarService $googleCalendarService, ICalService $icalService)
    {
        $this->googleCalendarService = $googleCalendarService;
        $this->icalService = $icalService;
    }
    
    /**
     * Get calendar events
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEvents(Request $request): JsonResponse
    {
        try {
            $startDate = $request->has('start_date') 
                ? Carbon::parse($request->start_date) 
                : Carbon::now()->startOfMonth();
                
            $endDate = $request->has('end_date') 
                ? Carbon::parse($request->end_date) 
                : Carbon::now()->endOfMonth();
                
            $maxResults = $request->get('max_results', 50);
            
            $events = $this->googleCalendarService->getEvents($startDate, $endDate, $maxResults);
            
            $formattedEvents = $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'title' => $event->name,
                    'description' => $event->description,
                    'start' => $event->startDateTime,
                    'end' => $event->endDateTime,
                    'location' => $event->location,
                    'attendees' => $event->attendees ?? [],
                    'created_at' => $event->createdDateTime,
                    'updated_at' => $event->updatedDateTime,
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $formattedEvents,
                'meta' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'count' => $formattedEvents->count()
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve calendar events',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a new calendar event
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createEvent(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after:start_time',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'attendees' => 'nullable|array',
                'attendees.*' => 'email',
                'reminder_minutes' => 'nullable|integer|min:0'
            ]);
            
            $eventData = [
                'title' => $request->title,
                'description' => $request->description,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'location' => $request->location,
                'attendees' => $request->attendees,
                'reminder_minutes' => $request->reminder_minutes
            ];
            
            $event = $this->googleCalendarService->createEvent($eventData);
            
            if (!$event) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create calendar event'
                ], 500);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Calendar event created successfully',
                'data' => [
                    'id' => $event->id,
                    'title' => $event->name,
                    'start' => $event->startDateTime,
                    'end' => $event->endDateTime,
                    'location' => $event->location
                ]
            ], 201);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create calendar event',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update an existing calendar event
     *
     * @param Request $request
     * @param string $eventId
     * @return JsonResponse
     */
    public function updateEvent(Request $request, string $eventId): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'start_time' => 'sometimes|required|date',
                'end_time' => 'sometimes|required|date|after:start_time',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255'
            ]);
            
            $eventData = $request->only([
                'title', 'description', 'start_time', 'end_time', 'location'
            ]);
            
            $event = $this->googleCalendarService->updateEvent($eventId, $eventData);
            
            if (!$event) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event not found or failed to update'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Calendar event updated successfully',
                'data' => [
                    'id' => $event->id,
                    'title' => $event->name,
                    'start' => $event->startDateTime,
                    'end' => $event->endDateTime,
                    'location' => $event->location
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update calendar event',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete a calendar event
     *
     * @param string $eventId
     * @return JsonResponse
     */
    public function deleteEvent(string $eventId): JsonResponse
    {
        try {
            $success = $this->googleCalendarService->deleteEvent($eventId);
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event not found or failed to delete'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Calendar event deleted successfully'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete calendar event',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check availability for a time slot
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'start_time' => 'required|date',
                'end_time' => 'required|date|after:start_time'
            ]);
            
            $startTime = Carbon::parse($request->start_time);
            $endTime = Carbon::parse($request->end_time);
            
            $available = $this->googleCalendarService->isTimeSlotAvailable($startTime, $endTime);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'available' => $available,
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time' => $endTime->toDateTimeString()
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get available time slots for a date
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvailableSlots(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date',
                'slot_duration' => 'nullable|integer|min:15|max:480',
                'working_hours_start' => 'nullable|date_format:H:i',
                'working_hours_end' => 'nullable|date_format:H:i'
            ]);
            
            $date = Carbon::parse($request->date);
            $slotDuration = $request->get('slot_duration', 60);
            
            $workingHoursStart = $request->working_hours_start 
                ? $date->copy()->setTimeFromTimeString($request->working_hours_start)
                : $date->copy()->setTime(9, 0);
                
            $workingHoursEnd = $request->working_hours_end 
                ? $date->copy()->setTimeFromTimeString($request->working_hours_end)
                : $date->copy()->setTime(17, 0);
            
            $availableSlots = $this->googleCalendarService->getAvailableTimeSlots(
                $date, 
                $slotDuration, 
                $workingHoursStart, 
                $workingHoursEnd
            );
            
            return response()->json([
                'success' => true,
                'data' => $availableSlots,
                'meta' => [
                    'date' => $date->toDateString(),
                    'slot_duration_minutes' => $slotDuration,
                    'working_hours' => [
                        'start' => $workingHoursStart->format('H:i'),
                        'end' => $workingHoursEnd->format('H:i')
                    ],
                    'total_slots' => count($availableSlots)
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available slots',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Export calendar events to iCal format
     *
     * @param Request $request
     * @return Response
     */
    public function exportToICal(Request $request): Response
    {
        try {
            $startDate = $request->has('start_date') 
                ? Carbon::parse($request->start_date) 
                : Carbon::now()->startOfMonth();
                
            $endDate = $request->has('end_date') 
                ? Carbon::parse($request->end_date) 
                : Carbon::now()->endOfMonth();
            
            $events = $this->googleCalendarService->getEvents($startDate, $endDate);
            
            $icalEvents = $events->map(function ($event) {
                return [
                    'uid' => $event->id,
                    'title' => $event->name,
                    'description' => $event->description,
                    'start_time' => $event->startDateTime,
                    'end_time' => $event->endDateTime,
                    'location' => $event->location,
                    'organizer_email' => 'noreply@calendar-booking-app.com',
                    'organizer_name' => 'Calendar Booking App'
                ];
            })->toArray();
            
            $icalContent = $this->icalService->generateICalFile(
                $icalEvents, 
                'Calendar Export',
                'Exported calendar events'
            );
            
            $filename = 'calendar_export_' . Carbon::now()->format('Y-m-d_H-i-s') . '.ics';
            
            return response($icalContent)
                ->header('Content-Type', 'text/calendar; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
                
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export calendar',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Import events from iCal file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importFromICal(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ical_file' => 'required|file|mimes:ics,txt|max:2048'
            ]);
            
            $file = $request->file('ical_file');
            $content = file_get_contents($file->getPathname());
            
            $events = $this->icalService->parseICalFile($content);
            
            $importedCount = 0;
            $errors = [];
            
            foreach ($events as $eventData) {
                try {
                    if ($eventData['start_time'] && $eventData['end_time']) {
                        $googleEventData = [
                            'title' => $eventData['title'],
                            'description' => $eventData['description'],
                            'start_time' => $eventData['start_time'],
                            'end_time' => $eventData['end_time'],
                            'location' => $eventData['location']
                        ];
                        
                        $event = $this->googleCalendarService->createEvent($googleEventData);
                        if ($event) {
                            $importedCount++;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Failed to import event '{$eventData['title']}': " . $e->getMessage();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Successfully imported {$importedCount} events",
                'data' => [
                    'total_events' => $events->count(),
                    'imported_count' => $importedCount,
                    'errors' => $errors
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import iCal file',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

