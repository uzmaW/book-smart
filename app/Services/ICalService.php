<?php

namespace App\Services;

use Spatie\IcalendarGenerator\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Components\Alert;
use Spatie\IcalendarGenerator\Enums\Classification;
use Spatie\IcalendarGenerator\Enums\EventStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

class ICalService
{
    /**
     * Generate iCal file from events data
     *
     * @param array $events
     * @param string $calendarName
     * @param string $description
     * @return string
     */
    public function generateICalFile(array $events, string $calendarName = 'Calendar', string $description = '')
    {
        try {
            $calendar = Calendar::create($calendarName)
                ->description($description)
                ->timezone('UTC');
            
            foreach ($events as $eventData) {
                $event = $this->createEventFromData($eventData);
                if ($event) {
                    $calendar->event($event);
                }
            }
            
            Log::info('iCal file generated successfully', [
                'calendar_name' => $calendarName,
                'events_count' => count($events)
            ]);
            
            return $calendar->get();
            
        } catch (Exception $e) {
            Log::error('Failed to generate iCal file', [
                'error' => $e->getMessage(),
                'calendar_name' => $calendarName,
                'events_count' => count($events)
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Create an Event component from event data
     *
     * @param array $eventData
     * @return Event|null
     */
    private function createEventFromData(array $eventData)
    {
        try {
            $event = Event::create()
                ->name($eventData['title'])
                ->description($eventData['description'] ?? '')
                ->startsAt(Carbon::parse($eventData['start_time']))
                ->endsAt(Carbon::parse($eventData['end_time']));
            
            // Set unique identifier
            if (isset($eventData['uid'])) {
                $event->uniqueIdentifier($eventData['uid']);
            }
            
            // Set location
            if (isset($eventData['location'])) {
                $event->address($eventData['location']);
            }
            
            // Set organizer
            if (isset($eventData['organizer_email'])) {
                $event->organizer(
                    $eventData['organizer_email'],
                    $eventData['organizer_name'] ?? ''
                );
            }
            
            // Add attendees
            if (isset($eventData['attendees']) && is_array($eventData['attendees'])) {
                foreach ($eventData['attendees'] as $attendee) {
                    if (is_array($attendee)) {
                        $event->attendee(
                            $attendee['email'],
                            $attendee['name'] ?? ''
                        );
                    } else {
                        $event->attendee($attendee);
                    }
                }
            }
            
            // Set classification
            if (isset($eventData['classification'])) {
                switch (strtolower($eventData['classification'])) {
                    case 'private':
                        $event->classification(Classification::private());
                        break;
                    case 'confidential':
                        $event->classification(Classification::confidential());
                        break;
                    default:
                        $event->classification(Classification::public());
                        break;
                }
            }
            
            // Set status
            if (isset($eventData['status'])) {
                switch (strtolower($eventData['status'])) {
                    case 'tentative':
                        $event->status(EventStatus::tentative());
                        break;
                    case 'cancelled':
                        $event->status(EventStatus::cancelled());
                        break;
                    default:
                        $event->status(EventStatus::confirmed());
                        break;
                }
            }
            
            // Add reminders/alerts
            if (isset($eventData['reminders']) && is_array($eventData['reminders'])) {
                foreach ($eventData['reminders'] as $reminder) {
                    $minutes = is_array($reminder) ? $reminder['minutes'] : $reminder;
                    $event->alert(
                        Alert::minutesBeforeStart($minutes)
                            ->to($eventData['organizer_email'] ?? 'user@example.com')
                    );
                }
            }
            
            // Set recurrence rule if provided
            if (isset($eventData['recurrence_rule'])) {
                $event->rrule($eventData['recurrence_rule']);
            }
            
            return $event;
            
        } catch (Exception $e) {
            Log::error('Failed to create iCal event', [
                'error' => $e->getMessage(),
                'event_data' => $eventData
            ]);
            
            return null;
        }
    }
    
    /**
     * Parse iCal file content and extract events
     *
     * @param string $icalContent
     * @return Collection
     */
    public function parseICalFile(string $icalContent)
    {
        try {
            $events = collect();
            $lines = explode("\n", $icalContent);
            $currentEvent = null;
            $inEvent = false;
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                if ($line === 'BEGIN:VEVENT') {
                    $inEvent = true;
                    $currentEvent = [];
                    continue;
                }
                
                if ($line === 'END:VEVENT') {
                    if ($currentEvent && $inEvent) {
                        $events->push($this->processEventData($currentEvent));
                    }
                    $inEvent = false;
                    $currentEvent = null;
                    continue;
                }
                
                if ($inEvent && strpos($line, ':') !== false) {
                    [$property, $value] = explode(':', $line, 2);
                    $property = strtoupper(trim($property));
                    $value = trim($value);
                    
                    // Handle property parameters (e.g., DTSTART;TZID=America/New_York:20230101T120000)
                    if (strpos($property, ';') !== false) {
                        [$property, $params] = explode(';', $property, 2);
                        $currentEvent[$property . '_PARAMS'] = $params;
                    }
                    
                    $currentEvent[$property] = $value;
                }
            }
            
            Log::info('iCal file parsed successfully', ['events_count' => $events->count()]);
            
            return $events;
            
        } catch (Exception $e) {
            Log::error('Failed to parse iCal file', ['error' => $e->getMessage()]);
            return collect();
        }
    }
    
    /**
     * Process raw event data from iCal parsing
     *
     * @param array $eventData
     * @return array
     */
    private function processEventData(array $eventData)
    {
        $processed = [
            'uid' => $eventData['UID'] ?? null,
            'title' => $eventData['SUMMARY'] ?? 'Untitled Event',
            'description' => $eventData['DESCRIPTION'] ?? '',
            'location' => $eventData['LOCATION'] ?? '',
            'start_time' => $this->parseDateTime($eventData['DTSTART'] ?? ''),
            'end_time' => $this->parseDateTime($eventData['DTEND'] ?? ''),
            'created_at' => $this->parseDateTime($eventData['CREATED'] ?? ''),
            'updated_at' => $this->parseDateTime($eventData['LAST-MODIFIED'] ?? ''),
            'organizer' => $this->parseOrganizer($eventData['ORGANIZER'] ?? ''),
            'attendees' => $this->parseAttendees($eventData),
            'status' => strtolower($eventData['STATUS'] ?? 'confirmed'),
            'classification' => strtolower($eventData['CLASS'] ?? 'public'),
            'recurrence_rule' => $eventData['RRULE'] ?? null,
        ];
        
        return $processed;
    }
    
    /**
     * Parse iCal datetime format
     *
     * @param string $datetime
     * @return Carbon|null
     */
    private function parseDateTime(string $datetime)
    {
        if (empty($datetime)) {
            return null;
        }
        
        try {
            // Handle different iCal datetime formats
            if (strlen($datetime) === 8) {
                // Date only format: YYYYMMDD
                return Carbon::createFromFormat('Ymd', $datetime);
            } elseif (strlen($datetime) === 15 && substr($datetime, -1) === 'Z') {
                // UTC format: YYYYMMDDTHHMMSSZ
                return Carbon::createFromFormat('Ymd\THis\Z', $datetime);
            } elseif (strlen($datetime) === 15) {
                // Local format: YYYYMMDDTHHMMSS
                return Carbon::createFromFormat('Ymd\THis', $datetime);
            }
            
            // Fallback to Carbon's parsing
            return Carbon::parse($datetime);
            
        } catch (Exception $e) {
            Log::warning('Failed to parse iCal datetime', [
                'datetime' => $datetime,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    /**
     * Parse organizer information
     *
     * @param string $organizer
     * @return array
     */
    private function parseOrganizer(string $organizer)
    {
        if (empty($organizer)) {
            return [];
        }
        
        $result = [];
        
        // Extract email (MAILTO:email@example.com)
        if (preg_match('/MAILTO:([^;]+)/', $organizer, $matches)) {
            $result['email'] = $matches[1];
        }
        
        // Extract name (CN=Name)
        if (preg_match('/CN=([^;:]+)/', $organizer, $matches)) {
            $result['name'] = $matches[1];
        }
        
        return $result;
    }
    
    /**
     * Parse attendees information
     *
     * @param array $eventData
     * @return array
     */
    private function parseAttendees(array $eventData)
    {
        $attendees = [];
        
        foreach ($eventData as $key => $value) {
            if (strpos($key, 'ATTENDEE') === 0) {
                $attendee = [];
                
                // Extract email
                if (preg_match('/MAILTO:([^;]+)/', $value, $matches)) {
                    $attendee['email'] = $matches[1];
                }
                
                // Extract name
                if (preg_match('/CN=([^;:]+)/', $value, $matches)) {
                    $attendee['name'] = $matches[1];
                }
                
                // Extract participation status
                if (preg_match('/PARTSTAT=([^;:]+)/', $value, $matches)) {
                    $attendee['status'] = strtolower($matches[1]);
                }
                
                if (!empty($attendee)) {
                    $attendees[] = $attendee;
                }
            }
        }
        
        return $attendees;
    }
    
    /**
     * Export events to iCal file and save to storage
     *
     * @param array $events
     * @param string $filename
     * @param string $calendarName
     * @return string File path
     */
    public function exportToFile(array $events, string $filename, string $calendarName = 'Calendar')
    {
        $icalContent = $this->generateICalFile($events, $calendarName);
        
        $filePath = storage_path('app/ical/' . $filename);
        
        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($filePath, $icalContent);
        
        Log::info('iCal file exported', ['file_path' => $filePath]);
        
        return $filePath;
    }
    
    /**
     * Import events from iCal file
     *
     * @param string $filePath
     * @return Collection
     */
    public function importFromFile(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("iCal file not found: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        return $this->parseICalFile($content);
    }
}

