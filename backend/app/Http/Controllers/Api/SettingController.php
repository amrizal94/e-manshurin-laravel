<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Semua pengaturan sekaligus. Kiosk absen wajah ikut memanggil ini tiap menit
     * untuk teks suaranya, jadi role absensi boleh membaca — tapi tidak mengubah.
     */
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'OK', 'data' => Setting::semua()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(array_key_exists($key, Setting::BAWAAN), 404);

        $data = $request->validate(['value' => ['required', 'string', 'max:500']]);
        Setting::set($key, $data['value']);

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan disimpan',
            'data' => [$key => $data['value']],
        ]);
    }
}
