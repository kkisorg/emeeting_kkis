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
        $ip_addresses = explode(';', env('ZOOM_WHITELIST_IP_ADDRESSES'));

        // Add test IP (if provided).
        $test_ip_address = env('ZOOM_WHITELIST_TEST_IP_ADDRESS');
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
                $message = 'Meeting '.$request->input('payload.object.id').' ('.$request->input('payload.object.topic').') started.';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.ended':
                $message = 'Meeting '.$request->input('payload.object.id').' ('.$request->input('payload.object.topic').') ended.';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.participant_joined':
                $meeting_id = $request->input('payload.object.id');
                $meeting_topic = $request->input('payload.object.topic');
                $participant_name = $request->input('payload.object.participant.user_name');
                $message = $participant_name.' joined meeting '.$meeting_id.' ('.$meeting_topic.').';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.participant_left':
                $meeting_id = $request->input('payload.object.id');
                $meeting_topic = $request->input('payload.object.topic');
                $participant_name = $request->input('payload.object.participant.user_name');
                $message = $participant_name.' left meeting '.$meeting_id.' ('.$meeting_topic.').';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.created':
            case 'meeting.updated':
            case 'meeting.deleted':
                app(MeetingController::class)->triggered_sync();
                break;
            case 'meeting.live_streaming_started':
                $meeting_id = $request->input('payload.object.id');
                $meeting_topic = $request->input('payload.object.topic');
                $message = 'Livestream for meeting '.$meeting_id.' ('.$meeting_topic.') started successfully.';
                Log::info($message);
                $this->send_telegram_notification($message);
                break;
            case 'meeting.live_streaming_stopped':
                $meeting_id = $request->input('payload.object.id');
                $meeting_topic = $request->input('payload.object.topic');
                $message = 'Livestream for meeting '.$meeting_id.' ('.$meeting_topic.') stopped successfully.';
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
