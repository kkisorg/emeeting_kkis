<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Carbon\Carbon;
use GuzzleHttp;

use App\Meeting;

class MeetingController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        Log::info('Started listing meetings.');
        $now = Carbon::now();
        $meetings = Meeting::where('status', 'ENABLED')
                           ->where('start_at', '>=', $now)
                           ->orderBy('start_at')
                           ->get();
        foreach ($meetings as $meeting) {
            $meeting->local_start_at = $meeting->start_at->copy()->tz('Asia/Singapore');
        }
        Log::info('Getting '.count($meetings).' meetings.');
        Log::info('Finished indexing meetings.');
        return view('meeting', ['meetings' => $meetings]);
    }

    public function scheduled_sync()
    {
        Log::info('Started scheduled meeting synchronization');
        $this->sync();
        Log::info('Finished scheduled meeting synchronization');
    }

    public function manual_sync()
    {
        Log::info('Started manual meeting synchronization');
        $this->sync();
        Log::info('Finished manual meeting synchronization');
        return redirect()->route('home')->with('status', 'Manual sync executed successfully!');
    }

    private function sync()
    {
        $client = new GuzzleHttp\Client([
            'base_uri' => env('ZOOM_BASE_URI'),
            'timeout' => 5.0,
        ]);

        // To handle deletion, first mark all meeting as disabled.
        // Later during synchronixation, mark them back as enabled.
        // Accordingly, the deleted meeting will be marked as disabled.
        $now = Carbon::now();
        Meeting::where('start_at', '>=', $now)->update(['status' => 'DISABLED']);
        Log::info('Scheduled meetings disabled.');

        $next_page_token = null;
        while(($next_page_token === null) || ($next_page_token !== '')) {
            // Prepare and perform request.
            $request_headers = ['Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN')];
            $request_query = ['type' => 'upcoming', 'page_size' => 10];
            if ($next_page_token !== null) {
                $request_query['next_page_token'] = $next_page_token;
            }
            $response = $client->request(
                'GET',
                'users/'.env('ZOOM_USER_ID').'/meetings',
                [
                    'headers' => $request_headers,
                    'query' => $request_query,
                ]
            );
            Log::info('List meetings requested');

            // Validate response and process accordingly.
            if ($response->getStatusCode() !== 200) {
                break;
            }
            $body = $response->getBody();
            $contents = $body->getContents();
            Log::debug($contents);
            $contents_json = json_decode($contents);
            Log::debug(json_encode($contents_json));
            foreach ($contents_json->meetings as $meeting) {
                if (in_array($meeting->type, array(2, 8))) {
                    $meeting_start_time = Carbon::createFromFormat(
                        'Y-m-d\TH:i:s\Z', $meeting->start_time);
                    Log::debug($meeting->id);
                    Meeting::updateOrCreate(
                        ['meeting_id' => $meeting->id,
                         'start_at' => $meeting_start_time],
                        ['topic' => $meeting->topic,
                         'duration' => $meeting->duration,
                         'zoom_url' => $meeting->join_url,
                         'status' => 'ENABLED']
                    );
                }
            }

            // Update next page token for pagination.
            $next_page_token = $contents_json->next_page_token;
        }
    }

    public function start_livestream()
    {
        $now = Carbon::now();
        $next_one_minute = $now->copy()->addMinute();

        $meeting = Meeting::where('status', 'ENABLED')
                          ->whereNotNull('livestream_configuration_id')
                          ->whereBetween('livestream_start_at', [$now, $next_one_minute])
                          ->first();

        if ($meeting) {
            $client = new GuzzleHttp\Client([
                'base_uri' => env('ZOOM_BASE_URI'),
                'timeout' => 5.0,
            ]);
            $request_headers = [
                'Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN'),
                'Content-Type' => 'application/json'
            ];
            $body = [
                'action' => 'start',
                'settings' => [
                    'active_speaker_name' => false,
                    'display_name' => $meeting->livestream_configurations->name
                ]
            ];
            $response = $client->request(
               'PATCH',
               'meetings/'.$meeting->meeting_id.'/livestream/status',
               [
                   'headers' => $request_headers,
                   'body' => json_encode($body),
               ]
            );
        }
    }
}
