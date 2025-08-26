<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\GoogleAuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Google Calendar Authentication Routes
Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('google.callback');
    Route::get('/status', [GoogleAuthController::class, 'checkAuthStatus'])->name('google.status');
    Route::post('/revoke', [GoogleAuthController::class, 'revokeAccess'])->name('google.revoke');
});

// Calendar API Routes
Route::prefix('calendar')->group(function () {
    Route::get('/events', [CalendarController::class, 'getEvents'])->name('calendar.events.index');
    Route::post('/events', [CalendarController::class, 'createEvent'])->name('calendar.events.store');
    Route::put('/events/{eventId}', [CalendarController::class, 'updateEvent'])->name('calendar.events.update');
    Route::delete('/events/{eventId}', [CalendarController::class, 'deleteEvent'])->name('calendar.events.destroy');
    
    Route::post('/check-availability', [CalendarController::class, 'checkAvailability'])->name('calendar.check-availability');
    Route::get('/available-slots', [CalendarController::class, 'getAvailableSlots'])->name('calendar.available-slots');
    
    Route::get('/export/ical', [CalendarController::class, 'exportToICal'])->name('calendar.export.ical');
    Route::post('/import/ical', [CalendarController::class, 'importFromICal'])->name('calendar.import.ical');
});

// Booking API Routes
Route::prefix('bookings')->group(function () {
    Route::get('/', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('/{id}', [BookingController::class, 'show'])->name('bookings.show');
    Route::put('/{id}', [BookingController::class, 'update'])->name('bookings.update');
    Route::delete('/{id}', [BookingController::class, 'destroy'])->name('bookings.destroy');
    
    Route::post('/{id}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    
    Route::post('/check-availability', [BookingController::class, 'checkAvailability'])->name('bookings.check-availability');
    Route::get('/available-slots', [BookingController::class, 'getAvailableSlots'])->name('bookings.available-slots');
    
    Route::get('/export/ical', [BookingController::class, 'exportToICal'])->name('bookings.export.ical');
});

// Health check route
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toDateTimeString(),
        'version' => '1.0.0'
    ]);
})->name('api.health');

// API Documentation route
Route::get('/docs', function () {
    return response()->json([
        'name' => 'Calendar Booking API',
        'version' => '1.0.0',
        'description' => 'API for managing calendar events and bookings with Google Calendar and iCal integration',
        'endpoints' => [
            'authentication' => [
                'GET /api/auth/google/redirect' => 'Redirect to Google OAuth',
                'GET /api/auth/google/callback' => 'Handle Google OAuth callback',
                'GET /api/auth/google/status' => 'Check Google authentication status',
                'POST /api/auth/google/revoke' => 'Revoke Google Calendar access'
            ],
            'calendar' => [
                'GET /api/calendar/events' => 'Get calendar events',
                'POST /api/calendar/events' => 'Create calendar event',
                'PUT /api/calendar/events/{eventId}' => 'Update calendar event',
                'DELETE /api/calendar/events/{eventId}' => 'Delete calendar event',
                'POST /api/calendar/check-availability' => 'Check time slot availability',
                'GET /api/calendar/available-slots' => 'Get available time slots',
                'GET /api/calendar/export/ical' => 'Export calendar to iCal',
                'POST /api/calendar/import/ical' => 'Import iCal file'
            ],
            'bookings' => [
                'GET /api/bookings' => 'Get bookings',
                'POST /api/bookings' => 'Create booking',
                'GET /api/bookings/{id}' => 'Get specific booking',
                'PUT /api/bookings/{id}' => 'Update booking',
                'DELETE /api/bookings/{id}' => 'Delete booking',
                'POST /api/bookings/{id}/cancel' => 'Cancel booking',
                'POST /api/bookings/check-availability' => 'Check booking availability',
                'GET /api/bookings/available-slots' => 'Get available booking slots',
                'GET /api/bookings/export/ical' => 'Export bookings to iCal'
            ]
        ]
    ]);
})->name('api.docs');
