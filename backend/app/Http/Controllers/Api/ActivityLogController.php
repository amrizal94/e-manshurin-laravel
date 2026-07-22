<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = Activity::with('causer:id,name', 'subject')
            ->latest()
            ->paginate(30);

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $logs]);
    }
}
