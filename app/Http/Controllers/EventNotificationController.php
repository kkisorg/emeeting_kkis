<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

use App\Notifications\GeneralNotification;

class EventNotificationController extends Controller
{
    public function zoom_event(Request $request)
    {
        // Construct the array of whitelisted IP.
        $ip_addresses = explode(';', env('ZOOM_WHITELIST'));

        // Add test IP (if provided).
        $test_ip_address = env('ZOOM_WHITELIST_TEST');
        if ($test_ip_address !== '') {
            array_push($ip_addresses, $test_ip_address);
        }

        // Compare IP address for authorization.
        if (!in_array($request->ip(), $ip_addresses)) {
            $error_message = 'Received unauthorized Zoom notification from IP address '.$request->ip().'.';
            $this->send_telegram_notification($error_message);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = $request->input('event');
        switch ($event) {
            case 'meeting.started':
                $message = 'Meeting '.$request->input('payload.object.id').' started.';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.ended':
                $message = 'Meeting '.$request->input('payload.object.id').' ended.';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            default:
                break;
        }
        return response(null, 204);
    }

    private function send_telegram_notification($message) {
        try {
            Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                new GeneralNotification($message));
        } catch (\Exception $e) {
            Log::warning('Failed sending notification via Telegram: '.$message);
        }
    }
}
