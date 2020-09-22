<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventNotificationController extends Controller
{
    public function zoom_event(Request $request)
    {
        Log::debug($request->header('Authorization'));
        Log::debug($request->input('event'));
        Log::debug($request->headers->all());
        return response(null, 204);
    }
}
