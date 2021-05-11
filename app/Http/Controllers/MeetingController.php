<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use App\LivestreamConfiguration;
use App\Meeting;
use App\Notifications\GeneralNotification;

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

    public function triggered_sync()
    {
        Log::info('Started triggered meeting synchronization.');
        try {
            Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                ->notify(new GeneralNotification('Started triggered meeting synchronization.'));
        } catch (\Exception $e) {
            Log::warning('Failed sending notification via Telegram: Started triggered meeting synchronization.');
        }

        $this->sync();

        Log::info('Finished triggered meeting synchronization.');
        try {
            Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                ->notify(new GeneralNotification('Finished triggered meeting synchronization.'));
        } catch (\Exception $e) {
            Log::warning('Failed sending notification via Telegram: Finished triggered meeting synchronization.');
        }
    }

    public function manual_sync()
    {
        Log::info('Started manual meeting synchronization.');
        $this->sync();
        Log::info('Finished manual meeting synchronization.');
        return redirect()->route('home')->with('status', 'Manual sync executed successfully!');
    }

    private function sync()
    {
        $client = new Client([
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
            try {
                $response = $client->request(
                    'GET',
                    'users/'.env('ZOOM_USER_ID').'/meetings',
                    [
                        'headers' => $request_headers,
                        'query' => $request_query,
                    ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when listing meetings.'));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when listing meetings.');
                }
                break;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when listing meetings.'));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when listing meetings.');
                }
                break;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when listing meetings.'));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when listing meetings.');
                }
                break;
            }

            // Process the response accordingly.
            Log::info('List meetings requested.');
            $body = $response->getBody();
            $contents = $body->getContents();
            $contents_json = json_decode($contents);
            foreach ($contents_json->meetings as $meeting) {
                if (in_array($meeting->type, array(2, 8))) {
                    $meeting_start_time = Carbon::createFromFormat(
                        'Y-m-d\TH:i:s\Z', $meeting->start_time);
                    Meeting::updateOrCreate(
                        ['meeting_id' => (string) $meeting->id,
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

    public function test_livestream()
    {
        $now = Carbon::now();
        $two_minutes_later = $now->copy()->addMinutes(2);
        $three_minutes_later = $now->copy()->addMinutes(3);

        $meeting = Meeting::where('status', 'ENABLED')
                          ->whereNotNull('livestream_configuration_id')
                          ->whereBetween('livestream_start_at', [$two_minutes_later, $three_minutes_later])
                          ->first();

        if ($meeting) {
            // Edit the configuration.
            Log::info('Configuring test livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');

            $test_livestream_configuration = LivestreamConfiguration::where('name', 'TEST')->first();
            if (!$test_livestream_configuration) {
                Log::error('Cannot find livestream configuration for testing.');
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                        new GeneralNotification('Cannot find livestream configuration for testing.'));
                } catch (\Exception $e) {
                    Log::warning('Cannot find livestream configuration for testing.');
                }
                return;
            }

            $client = new Client([
                'base_uri' => env('ZOOM_BASE_URI'),
                'timeout' => 5.0,
            ]);
            $request_headers = [
                'Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN'),
                'Content-Type' => 'application/json'
            ];
            $body = [
                'stream_url' => $test_livestream_configuration->livestream_url,
                'stream_key' => $test_livestream_configuration->livestream_key,
                'page_url' => null
            ];
            try {
                $response = $client->request(
                   'PATCH',
                   'meetings/'.$meeting->meeting_id.'/livestream',
                   [
                       'headers' => $request_headers,
                       'body' => json_encode($body),
                   ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when configuring test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when configuring test livestream.');
                }
                return;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when configuring test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when configuring test livestream.');
                }
                return;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when configuring test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when configuring test livestream.');
                }
                return;
            }
            Log::info('Test livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.') configured using '.$test_livestream_configuration->name.'.');

            // Start the livestream.
            Log::info('Starting test livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            $client = new Client([
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
                    'display_name' => $test_livestream_configuration->name
                ]
            ];
            try {
                $response = $client->request(
                   'PATCH',
                   'meetings/'.$meeting->meeting_id.'/livestream/status',
                   [
                       'headers' => $request_headers,
                       'body' => json_encode($body),
                   ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when starting test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when starting test livestream.');
                }
                return;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when starting test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when starting test livestream.');
                }
                return;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when starting test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when starting test livestream.');
                }
                return;
            }

            // Wait for sometimes, making sure test livestream was started successfully.
            sleep(30);

            // Stop the livestream.
            // When livestream encountered a problem, this request will return HTTP status code 400.
            Log::info('Stopping test livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            $client = new Client([
                'base_uri' => env('ZOOM_BASE_URI'),
                'timeout' => 5.0,
            ]);
            $request_headers = [
                'Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN'),
                'Content-Type' => 'application/json'
            ];
            $body = [
                'action' => 'stop',
                'settings' => [
                    'active_speaker_name' => false,
                    'display_name' => $test_livestream_configuration->name
                ]
            ];
            try {
                $response = $client->request(
                   'PATCH',
                   'meetings/'.$meeting->meeting_id.'/livestream/status',
                   [
                       'headers' => $request_headers,
                       'body' => json_encode($body),
                   ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when stopping test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when stopping test livestream.');
                }
                return;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when stopping test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when stopping test livestream.');
                }
                return;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when stopping test livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when stopping test livestream.');
                }
                return;
            }

            Log::info('Test livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.') executed successfully.');
        }
    }

    public function start_livestream()
    {
        $now = Carbon::now();
        $last_one_minute = $now->copy()->subMinute();

        $meeting = Meeting::where('status', 'ENABLED')
                          ->whereNotNull('livestream_configuration_id')
                          ->whereBetween('livestream_start_at', [$last_one_minute, $now])
                          ->first();

        if ($meeting) {
            // Edit the configuration.
            Log::info('Configuring livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Configuring livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').', $meeting));
            } catch (\Exception $e) {
                Log::warning('Failed sending notification via Telegram: '.
                    'Configuring livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            }
            $client = new Client([
                'base_uri' => env('ZOOM_BASE_URI'),
                'timeout' => 5.0,
            ]);
            $request_headers = [
                'Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN'),
                'Content-Type' => 'application/json'
            ];
            $body = [
                'stream_url' => $meeting->livestream_configurations->livestream_url,
                'stream_key' => $meeting->livestream_configurations->livestream_key,
                'page_url' => $meeting->livestream_redirection_url
            ];
            try {
                $response = $client->request(
                   'PATCH',
                   'meetings/'.$meeting->meeting_id.'/livestream',
                   [
                       'headers' => $request_headers,
                       'body' => json_encode($body),
                   ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when configuring livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when configuring livestream.');
                }
                return;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when configuring livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when configuring livestream.');
                }
                return;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when configuring livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when configuring livestream.');
                }
                return;
            }
            Log::info('Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.')'.
                ' configured using '.$meeting->livestream_configurations->name.'.');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification(
                        'Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.') configured using '
                        .$meeting->livestream_configurations->name.'.', $meeting));
            } catch (\Exception $e) {
                Log::warning(
                    'Failed sending notification via Telegram: '.
                    'Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.')'.
                    ' configured using '.$meeting->livestream_configurations->name.'.');
            }

            // Start the livestream.
            Log::info('Starting livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Starting livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').', $meeting));
            } catch (\Exception $e) {
                Log::warning('Failed sending notification via Telegram: '.
                    'Starting livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.').');
            }
            $client = new Client([
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
            try {
                $response = $client->request(
                   'PATCH',
                   'meetings/'.$meeting->meeting_id.'/livestream/status',
                   [
                       'headers' => $request_headers,
                       'body' => json_encode($body),
                   ]
                );
            } catch (ConnectException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ConnectException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ConnectException occurred when starting livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ConnectException occurred when starting livestream.');
                }
                return;
            } catch (ClientException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ClientException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ClientException occurred when starting livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ClientException occurred when starting livestream.');
                }
                return;
            } catch (ServerException $e) {
                $error_message = "Request:\n".Psr7\str($e->getRequest());
                if ($e->hasResponse()) {
                    $error_message .= "Response:\n".Psr7\str($e->getResponse());
                }
                Log::error("ServerException:\n".$error_message);
                try {
                    Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))
                        ->notify(new GeneralNotification('ServerException occurred when starting livestream.', $meeting));
                } catch (\Exception $e) {
                    Log::warning('Failed sending notification via Telegram: ServerException occurred when starting livestream.');
                }
                return;
            }
            Log::info('Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.')'.
                ' started using '.$meeting->livestream_configurations->name.'.');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification(
                        'Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.') started using '
                        .$meeting->livestream_configurations->name.'.', $meeting));
            } catch (\Exception $e) {
                Log::warning(
                    'Failed sending notification via Telegram: '.
                    'Livestream for meeting ID '.$meeting->meeting_id.' ('.$meeting->topic.')'.
                    ' started using '.$meeting->livestream_configurations->name.'.');
            }
        }
    }

    public function test()
    {
        Log::info('Started test on Telegram notification.');
        try {
            Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                new GeneralNotification('Test message!'));
            Log::info('Finished test on Telegram notification.');
        } catch (\Exception $e) {
            Log::error('Failed sending notification via Telegram: Test message!');
            Log::error('Failed test on Telegram notification.');
        }

        Log::info('Started test on Zoom REST API.');
        // Prepare and perform request.
        $client = new Client([
            'base_uri' => env('ZOOM_BASE_URI'),
            'timeout' => 5.0,
        ]);
        $request_headers = ['Authorization' => 'Bearer '.env('ZOOM_JWT_TOKEN')];
        $request_query = ['email' => env('ZOOM_USER_ID')];
        try {
            $response = $client->request(
                'GET',
                'users/email',
                [
                    'headers' => $request_headers,
                    'query' => $request_query,
                ]
            );
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Zoom REST API test succeed!'));
            } catch (\Exception $e) {
                Log::error('Failed sending notification via Telegram: Zoom REST API test succeed!');
            }
            Log::info('Finished test on Zoom REST API.');
        } catch (ConnectException $e) {
            $error_message = "Request:\n".Psr7\str($e->getRequest());
            if ($e->hasResponse()) {
                $error_message .= "Response:\n".Psr7\str($e->getResponse());
            }
            Log::error("ConnectException:\n".$error_message);
            Log::error('Failed test on Zoom REST API: ConnectException.');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Failed test on Zoom REST API: ConnectException.'));
            } catch (\Exception $e) {
                Log::error('Failed sending notification via Telegram: Failed test on Zoom REST API: ConnectException.');
            }
        } catch (ClientException $e) {
            $error_message = "Request:\n".Psr7\str($e->getRequest());
            if ($e->hasResponse()) {
                $error_message .= "Response:\n".Psr7\str($e->getResponse());
            }
            Log::error("ClientException:\n".$error_message);
            Log::error('Failed test on Zoom REST API: ClientException.');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Failed test on Zoom REST API: ClientException.'));
            } catch (\Exception $e) {
                Log::error('Failed sending notification via Telegram: Failed test on Zoom REST API: ClientException.');
            }
        } catch (ServerException $e) {
            $error_message = "Request:\n".Psr7\str($e->getRequest());
            if ($e->hasResponse()) {
                $error_message .= "Response:\n".Psr7\str($e->getResponse());
            }
            Log::error("ServerException:\n".$error_message);
            Log::error('Failed test on Zoom REST API: ServerException.');
            try {
                Notification::route('telegram', env('TELEGRAM_ADMIN_USER_ID'))->notify(
                    new GeneralNotification('Failed test on Zoom REST API: ServerException.'));
            } catch (\Exception $e) {
                Log::error('Failed sending notification via Telegram: Failed test on Zoom REST API: ServerException.');
            }
        }
    }
}
