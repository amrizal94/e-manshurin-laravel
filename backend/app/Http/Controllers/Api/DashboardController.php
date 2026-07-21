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

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'total_jamaah' => (clone $jamaah)->where('aktif', true)->count(),
                'total_tidak_aktif' => (clone $jamaah)->where('aktif', false)->count(),
                'total_mubaligh' => (clone $jamaah)->where('aktif', true)->where('status_mubaligh', true)->count(),
                'jumlah_daerah' => $user->daerah_id || $user->desa_id || $user->kelompok_id ? null : Daerah::count(),
                'jumlah_desa' => $jumlahDesa,
                'jumlah_kelompok' => $jumlahKelompok,
                'per_kategori_usia' => (clone $jamaah)->where('aktif', true)
                    ->selectRaw('kategori_usia, count(*) as total')
                    ->groupBy('kategori_usia')
                    ->pluck('total', 'kategori_usia'),
                'kegiatan_bulan_ini' => Kegiatan::visibleTo($user)
                    ->whereBetween('tanggal', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),
            ],
        ]);
    }
}
