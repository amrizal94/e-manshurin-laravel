<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function waReplyTemplate(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => ['template' => Setting::get(Setting::WA_REPLY_TEMPLATE, Setting::DEFAULT_WA_REPLY_TEMPLATE)],
        ]);
    }

    public function updateWaReplyTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:500']]);
        Setting::set(Setting::WA_REPLY_TEMPLATE, $data['template']);

        return response()->json(['success' => true, 'message' => 'Template balasan disimpan', 'data' => ['template' => $data['template']]]);
    }
}
