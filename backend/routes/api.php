<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DaerahController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DesaController;
use App\Http\Controllers\Api\FaceController;
use App\Http\Controllers\Api\ImporJamaahController;
use App\Http\Controllers\Api\JadwalRutinController;
use App\Http\Controllers\Api\JamaahController;
use App\Http\Controllers\Api\KegiatanController;
use App\Http\Controllers\Api\KelompokController;
use App\Http\Controllers\Api\RekapController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// Webhook dari WA Gateway (D:\Projects\wa) — tanpa sesi user, diverifikasi via HMAC signature
Route::middleware('wa.webhook')->post('/wa/webhook', [WaController::class, 'webhook']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Master struktur: hanya super admin & admin
    Route::middleware('role:super_admin|admin')->group(function () {
        Route::apiResource('daerahs', DaerahController::class)->except('show');
        Route::apiResource('desas', DesaController::class)->except('show');
        Route::apiResource('kelompoks', KelompokController::class)->except('show');
        Route::get('/jamaahs/impor/template', [ImporJamaahController::class, 'template']);
        Route::get('/jamaahs/impor/template-xlsx', [ImporJamaahController::class, 'templateXlsx']);
        Route::post('/jamaahs/impor/periksa', [ImporJamaahController::class, 'periksa']);
        Route::post('/jamaahs/impor', [ImporJamaahController::class, 'simpan']);
        Route::delete('/jamaahs/impor/{imporId}', [ImporJamaahController::class, 'batal']);
        // Sebelum apiResource: kalau tidak, "keluarga" dikira id jamaah dan selalu 404.
        Route::get('/jamaahs/keluarga', [JamaahController::class, 'keluarga']);
        Route::post('/jamaahs/sambung-keluarga', [JamaahController::class, 'sambungKeluarga']);
        Route::apiResource('jamaahs', JamaahController::class);
        Route::post('/jamaahs/{jamaah}/photos', [JamaahController::class, 'storePhoto']);
        Route::delete('/jamaahs/{jamaah}/photos/{photo}', [JamaahController::class, 'destroyPhoto']);
        Route::post('/jamaahs/{jamaah}/face-enroll', [FaceController::class, 'enroll']);
        Route::apiResource('users', UserController::class)->except(['show']);
        // Jadwal rutin cuma diatur admin; kegiatan yang diterbitkannya dipakai semua role.
        Route::apiResource('jadwal-rutins', JadwalRutinController::class)->except('show')
            ->parameters(['jadwal-rutins' => 'jadwalRutin']);
    });

    // Kegiatan + absensi + rekap: semua role
    Route::middleware('role:super_admin|admin|absensi')->group(function () {
        // Kiosk standby: kegiatan ditentukan dari jam, bukan dipilih di URL
        Route::get('/kegiatan-aktif', [KegiatanController::class, 'aktif']);
        Route::post('/absensi-wajah', [FaceController::class, 'identifyStandby']);

        Route::apiResource('kegiatans', KegiatanController::class);
        Route::get('/kegiatans/{kegiatan}/peserta', [KegiatanController::class, 'peserta']);
        Route::post('/kegiatans/{kegiatan}/absensi', [KegiatanController::class, 'storeAbsensi']);
        Route::patch('/kegiatans/{kegiatan}/libur', [KegiatanController::class, 'libur']);
        Route::post('/kegiatans/{kegiatan}/absensi-wajah', [FaceController::class, 'identify']);
        Route::get('/rekap', [RekapController::class, 'index']);
    });

    // Kiosk (role absensi) ikut membaca ini untuk teks suaranya; yang mengubah tetap admin.
    Route::middleware('role:super_admin|admin|absensi')->get('/settings', [SettingController::class, 'index']);
    Route::middleware('role:super_admin|admin')->put('/settings/{key}', [SettingController::class, 'update']);

    // Log aktivitas: audit trail, hanya super admin (data lintas seluruh wilayah)
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    });
});
