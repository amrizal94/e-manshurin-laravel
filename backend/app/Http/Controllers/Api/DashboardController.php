<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $jamaah = Jamaah::visibleTo($user);

        // Jumlah unit struktur di bawah scope user
        $jumlahDesa = null;
        $jumlahKelompok = null;

        if ($user->kelompok_id) {
            // level kelompok: tidak ada unit di bawahnya
        } elseif ($user->desa_id) {
            $jumlahKelompok = Kelompok::where('desa_id', $user->desa_id)->count();
        } elseif ($user->daerah_id) {
            $desaIds = Desa::where('daerah_id', $user->daerah_id)->pluck('id');
            $jumlahDesa = $desaIds->count();
            $jumlahKelompok = Kelompok::whereIn('desa_id', $desaIds)->count();
        } else {
            $jumlahDesa = Desa::count();
            $jumlahKelompok = Kelompok::count();
        }

        // Semua angka orang dihitung dari jamaah aktif saja, supaya laki-laki + perempuan
        // persis sama dengan Jamaah Aktif. Angka yang tidak menjumlah akan dilaporkan
        // sebagai bug, dan memang pantas.
        $perJenisKelamin = (clone $jamaah)->where('aktif', true)
            ->selectRaw('jenis_kelamin, count(*) as total')
            ->groupBy('jenis_kelamin')
            ->pluck('total', 'jenis_kelamin');

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'total_jamaah' => (clone $jamaah)->where('aktif', true)->count(),
                'total_tidak_aktif' => (clone $jamaah)->where('aktif', false)->count(),
                'total_mubaligh' => (clone $jamaah)->where('aktif', true)->where('status_mubaligh', true)->count(),
                'total_laki' => (int) ($perJenisKelamin['L'] ?? 0),
                'total_perempuan' => (int) ($perJenisKelamin['P'] ?? 0),
                // Yang dihitung kepala keluarganya, bukan jumlah kartu keluarga di daftar:
                // keluarga yang belum punya kepala memang belum jadi satu KK.
                'total_kk' => (clone $jamaah)->where('aktif', true)->where('status_kk', 'kepala_keluarga')->count(),
                // Pendampingnya wajib. Tanpa ini "21 KK untuk 202 jamaah" terbaca sebagai
                // kenyataan, padahal itu pekerjaan yang belum selesai.
                'belum_masuk_keluarga' => (clone $jamaah)->where('aktif', true)->belumMasukKeluarga()->count(),
                'jumlah_daerah' => $user->daerah_id || $user->desa_id || $user->kelompok_id ? null : Daerah::count(),
                'jumlah_desa' => $jumlahDesa,
                'jumlah_kelompok' => $jumlahKelompok,
                'per_kategori_usia' => (clone $jamaah)->where('aktif', true)
                    ->selectRaw('kategori_usia, count(*) as total')
                    ->groupBy('kategori_usia')
                    ->pluck('total', 'kategori_usia'),
                // Batas bulan dihitung dalam waktu setempat: dengan now() polos yang UTC,
                // tanggal 1 sebelum pukul 07.00 WIB masih terhitung bulan lalu.
                'kegiatan_bulan_ini' => Kegiatan::visibleTo($user)
                    ->whereBetween('tanggal', [
                        Kegiatan::sekarangLokal()->startOfMonth(),
                        Kegiatan::sekarangLokal()->endOfMonth(),
                    ])
                    ->count(),
            ],
        ]);
    }
}
