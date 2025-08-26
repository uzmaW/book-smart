<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Google_Client;
use Google_Service_Calendar;
use Exception;

class GoogleAuthController extends Controller
{
    private $client;
    
    public function __construct()
    {
        $this->client = new Google_Client();
        $this->client->setApplicationName('Calendar Booking App');
        $this->client->setScopes([Google_Service_Calendar::CALENDAR]);
        $this->client->setAuthConfig(storage_path('app/google-calendar/oauth-credentials.json'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
        $this->client->setRedirectUri(config('app.url') . '/auth/google/callback');
    }
    
    /**
     * Redirect to Google OAuth
     */
    public function redirectToGoogle(): RedirectResponse
    {
        try {
            $authUrl = $this->client->createAuthUrl();
            return redirect($authUrl);
        } catch (Exception $e) {
            Log::error('Failed to create Google auth URL', ['error' => $e->getMessage()]);
            return redirect()->route('home')->with('error', 'Failed to connect to Google Calendar');
        }
    }
    
    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            if ($request->has('error')) {
                Log::warning('Google OAuth error', ['error' => $request->get('error')]);
                return redirect()->route('home')->with('error', 'Google authorization was denied');
            }
            
            if (!$request->has('code')) {
                Log::warning('No authorization code received from Google');
                return redirect()->route('home')->with('error', 'No authorization code received');
            }
            
            $token = $this->client->fetchAccessTokenWithAuthCode($request->get('code'));
            
            if (isset($token['error'])) {
                Log::error('Google OAuth token error', ['error' => $token['error']]);
                return redirect()->route('home')->with('error', 'Failed to obtain access token');
            }
            
            // Store the token
            $tokenPath = storage_path('app/google-calendar/oauth-token.json');
            file_put_contents($tokenPath, json_encode($token));
            
            // Store token in session for current user
            session(['google_calendar_token' => $token]);
            
            Log::info('Google Calendar authorization successful');
            
            return redirect()->route('calendar.index')->with('success', 'Google Calendar connected successfully');
            
        } catch (Exception $e) {
            Log::error('Google OAuth callback error', ['error' => $e->getMessage()]);
            return redirect()->route('home')->with('error', 'Failed to complete Google authorization');
        }
    }
    
    /**
     * Check if user is authenticated with Google Calendar
     */
    public function checkAuthStatus()
    {
        try {
            $tokenPath = storage_path('app/google-calendar/oauth-token.json');
            
            if (!file_exists($tokenPath)) {
                return response()->json(['authenticated' => false, 'message' => 'No token found']);
            }
            
            $token = json_decode(file_get_contents($tokenPath), true);
            $this->client->setAccessToken($token);
            
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    $token = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($token));
                    
                    return response()->json(['authenticated' => true, 'message' => 'Token refreshed']);
                } else {
                    return response()->json(['authenticated' => false, 'message' => 'Token expired, re-authentication required']);
                }
            }
            
            return response()->json(['authenticated' => true, 'message' => 'Authenticated']);
            
        } catch (Exception $e) {
            Log::error('Failed to check Google auth status', ['error' => $e->getMessage()]);
            return response()->json(['authenticated' => false, 'message' => 'Authentication check failed']);
        }
    }
    
    /**
     * Revoke Google Calendar access
     */
    public function revokeAccess()
    {
        try {
            $tokenPath = storage_path('app/google-calendar/oauth-token.json');
            
            if (file_exists($tokenPath)) {
                $token = json_decode(file_get_contents($tokenPath), true);
                $this->client->setAccessToken($token);
                $this->client->revokeToken();
                
                // Remove token file
                unlink($tokenPath);
            }
            
            // Clear session
            session()->forget('google_calendar_token');
            
            Log::info('Google Calendar access revoked');
            
            return redirect()->route('home')->with('success', 'Google Calendar access revoked successfully');
            
        } catch (Exception $e) {
            Log::error('Failed to revoke Google Calendar access', ['error' => $e->getMessage()]);
            return redirect()->route('home')->with('error', 'Failed to revoke access');
        }
    }
}

