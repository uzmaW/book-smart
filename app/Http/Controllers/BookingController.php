<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Carbon\Carbon;
use Exception;

class BookingController extends Controller
{
    protected $bookingService;
    
    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }
    
    /**
     * Get bookings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $startDate = $request->has('start_date') 
                ? Carbon::parse($request->start_date) 
                : Carbon::now()->startOfMonth();
                
            $endDate = $request->has('end_date') 
                ? Carbon::parse($request->end_date) 
                : Carbon::now()->endOfMonth();
                
            $userId = $request->get('user_id');
            $providerId = $request->get('provider_id');
            
            $bookings = $this->bookingService->getBookings($startDate, $endDate, $userId, $providerId);
            
            $formattedBookings = $bookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'title' => $booking->title,
                    'description' => $booking->description,
                    'start_time' => $booking->start_time->toDateTimeString(),
                    'end_time' => $booking->end_time->toDateTimeString(),
                    'location' => $booking->location,
                    'status' => $booking->status,
                    'booking_type' => $booking->booking_type,
                    'attendee_email' => $booking->attendee_email,
                    'attendee_name' => $booking->attendee_name,
                    'notes' => $booking->notes,
                    'user_id' => $booking->user_id,
                    'provider_id' => $booking->provider_id,
                    'google_event_id' => $booking->google_event_id,
                    'created_at' => $booking->created_at->toDateTimeString(),
                    'updated_at' => $booking->updated_at->toDateTimeString()
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $formattedBookings,
                'meta' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'count' => $formattedBookings->count()
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a new booking
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_id' => 'required|integer',
                'title' => 'required|string|max:255',
                'start_time' => 'required|date|after:now',
                'end_time' => 'required|date|after:start_time',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'provider_id' => 'nullable|integer',
                'booking_type' => 'nullable|string|in:appointment,meeting,event,consultation',
                'attendee_email' => 'nullable|email',
                'attendee_name' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'sync_google_calendar' => 'nullable|boolean'
            ]);
            
            $bookingData = $request->only([
                'user_id', 'title', 'description', 'start_time', 'end_time', 
                'location', 'provider_id', 'booking_type', 'attendee_email', 
                'attendee_name', 'notes', 'sync_google_calendar'
            ]);
            
            $booking = $this->bookingService->createBooking($bookingData);
            
            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create booking. Time slot may not be available.'
                ], 400);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully',
                'data' => [
                    'id' => $booking->id,
                    'title' => $booking->title,
                    'start_time' => $booking->start_time->toDateTimeString(),
                    'end_time' => $booking->end_time->toDateTimeString(),
                    'status' => $booking->status,
                    'google_event_id' => $booking->google_event_id
                ]
            ], 201);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Show a specific booking
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $booking->id,
                    'title' => $booking->title,
                    'description' => $booking->description,
                    'start_time' => $booking->start_time->toDateTimeString(),
                    'end_time' => $booking->end_time->toDateTimeString(),
                    'location' => $booking->location,
                    'status' => $booking->status,
                    'booking_type' => $booking->booking_type,
                    'attendee_email' => $booking->attendee_email,
                    'attendee_name' => $booking->attendee_name,
                    'notes' => $booking->notes,
                    'user_id' => $booking->user_id,
                    'provider_id' => $booking->provider_id,
                    'google_event_id' => $booking->google_event_id,
                    'cancellation_reason' => $booking->cancellation_reason,
                    'cancelled_at' => $booking->cancelled_at?->toDateTimeString(),
                    'created_at' => $booking->created_at->toDateTimeString(),
                    'updated_at' => $booking->updated_at->toDateTimeString()
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * Update a booking
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($id);
            
            $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'start_time' => 'sometimes|required|date',
                'end_time' => 'sometimes|required|date|after:start_time',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'booking_type' => 'nullable|string|in:appointment,meeting,event,consultation',
                'attendee_email' => 'nullable|email',
                'attendee_name' => 'nullable|string|max:255',
                'notes' => 'nullable|string'
            ]);
            
            $updateData = $request->only([
                'title', 'description', 'start_time', 'end_time', 
                'location', 'booking_type', 'attendee_email', 
                'attendee_name', 'notes'
            ]);
            
            $success = $this->bookingService->updateBooking($booking, $updateData);
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update booking. New time slot may not be available.'
                ], 400);
            }
            
            $booking->refresh();
            
            return response()->json([
                'success' => true,
                'message' => 'Booking updated successfully',
                'data' => [
                    'id' => $booking->id,
                    'title' => $booking->title,
                    'start_time' => $booking->start_time->toDateTimeString(),
                    'end_time' => $booking->end_time->toDateTimeString(),
                    'status' => $booking->status,
                    'updated_at' => $booking->updated_at->toDateTimeString()
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cancel a booking
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($id);
            
            $request->validate([
                'reason' => 'nullable|string|max:500'
            ]);
            
            $reason = $request->get('reason', '');
            
            $success = $this->bookingService->cancelBooking($booking, $reason);
            
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to cancel booking'
                ], 500);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete a booking
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($id);
            
            // Cancel the booking first (removes from Google Calendar)
            $this->bookingService->cancelBooking($booking, 'Booking deleted');
            
            // Delete the booking record
            $booking->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Booking deleted successfully'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete booking',
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
                'end_time' => 'required|date|after:start_time',
                'provider_id' => 'nullable|integer',
                'exclude_booking_id' => 'nullable|integer'
            ]);
            
            $startTime = Carbon::parse($request->start_time);
            $endTime = Carbon::parse($request->end_time);
            $providerId = $request->get('provider_id');
            $excludeBookingId = $request->get('exclude_booking_id');
            
            $available = $this->bookingService->isTimeSlotAvailable(
                $startTime, 
                $endTime, 
                $providerId, 
                $excludeBookingId
            );
            
            return response()->json([
                'success' => true,
                'data' => [
                    'available' => $available,
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time' => $endTime->toDateTimeString(),
                    'provider_id' => $providerId
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
                'working_hours_end' => 'nullable|date_format:H:i',
                'provider_id' => 'nullable|integer'
            ]);
            
            $date = Carbon::parse($request->date);
            $slotDuration = $request->get('slot_duration', 60);
            $providerId = $request->get('provider_id');
            
            $workingHoursStart = $request->working_hours_start 
                ? $date->copy()->setTimeFromTimeString($request->working_hours_start)
                : $date->copy()->setTime(9, 0);
                
            $workingHoursEnd = $request->working_hours_end 
                ? $date->copy()->setTimeFromTimeString($request->working_hours_end)
                : $date->copy()->setTime(17, 0);
            
            $availableSlots = $this->bookingService->getAvailableTimeSlots(
                $date, 
                $slotDuration, 
                $workingHoursStart, 
                $workingHoursEnd,
                $providerId
            );
            
            return response()->json([
                'success' => true,
                'data' => $availableSlots,
                'meta' => [
                    'date' => $date->toDateString(),
                    'slot_duration_minutes' => $slotDuration,
                    'provider_id' => $providerId,
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
     * Export bookings to iCal format
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
                
            $userId = $request->get('user_id');
            $providerId = $request->get('provider_id');
            
            $bookings = $this->bookingService->getBookings($startDate, $endDate, $userId, $providerId);
            
            $icalContent = $this->bookingService->exportBookingsToICal($bookings, 'My Bookings');
            
            $filename = 'bookings_export_' . Carbon::now()->format('Y-m-d_H-i-s') . '.ics';
            
            return response($icalContent)
                ->header('Content-Type', 'text/calendar; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
                
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

