<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DaerahController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DesaController;
use App\Http\Controllers\Api\FaceController;
use App\Http\Controllers\Api\JamaahController;
use App\Http\Controllers\Api\KegiatanController;
use App\Http\Controllers\Api\KelompokController;
use App\Http\Controllers\Api\RekapController;
use App\Http\Controllers\Api\SettingController;
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
        Route::apiResource('jamaahs', JamaahController::class);
        Route::post('/jamaahs/{jamaah}/photos', [JamaahController::class, 'storePhoto']);
        Route::delete('/jamaahs/{jamaah}/photos/{photo}', [JamaahController::class, 'destroyPhoto']);
        Route::post('/jamaahs/{jamaah}/face-enroll', [FaceController::class, 'enroll']);
    });

    // Kegiatan + absensi + rekap: semua role
    Route::middleware('role:super_admin|admin|absensi')->group(function () {
        Route::apiResource('kegiatans', KegiatanController::class);
        Route::get('/kegiatans/{kegiatan}/peserta', [KegiatanController::class, 'peserta']);
        Route::post('/kegiatans/{kegiatan}/absensi', [KegiatanController::class, 'storeAbsensi']);
        Route::post('/kegiatans/{kegiatan}/absensi-wajah', [FaceController::class, 'identify']);
        Route::get('/rekap', [RekapController::class, 'index']);
    });

    // Pengaturan: hanya super admin & admin
    Route::middleware('role:super_admin|admin')->group(function () {
        Route::get('/settings/wa-reply-template', [SettingController::class, 'waReplyTemplate']);
        Route::put('/settings/wa-reply-template', [SettingController::class, 'updateWaReplyTemplate']);
    });
});
