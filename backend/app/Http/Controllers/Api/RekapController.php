<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Kegiatan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RekapController extends Controller
{
    /**
     * Matriks rekap: baris = jamaah, kolom = kegiatan dalam rentang tanggal.
     * Status per sel: hadir/izin/alpha (alpha = berhak hadir tapi tak ada catatan).
     * Flag jika ada >= 3 alpha berturut-turut.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'jenis_pengajian' => ['nullable', 'in:umum,caberawit,praremaja,remaja,usman'],
        ]);

        $kegiatans = Kegiatan::visibleTo($request->user())
            ->whereDate('tanggal', '>=', $data['dari'])
            ->whereDate('tanggal', '<=', $data['sampai'])
            ->when($data['jenis_pengajian'] ?? null, fn ($q, $jenis) => $q->where('jenis_pengajian', $jenis))
            ->orderBy('tanggal')
            ->get();

        if ($kegiatans->isEmpty()) {
            return response()->json(['success' => true, 'message' => 'OK', 'data' => ['kegiatans' => [], 'rows' => []]]);
        }

        // status tercatat: [jamaah_id][kegiatan_id] => status
        $recorded = Absensi::whereIn('kegiatan_id', $kegiatans->pluck('id'))
            ->get()
            ->groupBy('jamaah_id')
            ->map(fn ($group) => $group->keyBy('kegiatan_id')->map->status);

        // peserta per kegiatan + kumpulan semua jamaah yang terlibat
        $pesertaPerKegiatan = [];
        $jamaahById = collect();
        foreach ($kegiatans as $kegiatan) {
            $peserta = $kegiatan->pesertaQuery()->with('kelompok:id,nama')->get();
            $pesertaPerKegiatan[$kegiatan->id] = $peserta->pluck('id')->flip();
            $jamaahById = $jamaahById->union($peserta->keyBy('id'));
        }

        $rows = $jamaahById->sortBy('nama_lengkap')->values()->map(function ($jamaah) use ($kegiatans, $recorded, $pesertaPerKegiatan) {
            $statuses = [];
            $streak = 0;
            $maxStreak = 0;

            foreach ($kegiatans as $kegiatan) {
                $eligible = isset($pesertaPerKegiatan[$kegiatan->id][$jamaah->id]);
                if (! $eligible) {
                    $statuses[$kegiatan->id] = null;
                    continue;
                }

                $status = $recorded->get($jamaah->id)?->get($kegiatan->id) ?? 'alpha';
                $statuses[$kegiatan->id] = $status;

                $streak = $status === 'alpha' ? $streak + 1 : 0;
                $maxStreak = max($maxStreak, $streak);
            }

            return [
                'jamaah' => [
                    'id' => $jamaah->id,
                    'nama_lengkap' => $jamaah->nama_lengkap,
                    'kelompok' => $jamaah->kelompok?->nama,
                    'kategori_usia' => $jamaah->kategori_usia,
                ],
                'statuses' => $statuses,
                'perlu_perhatian' => $maxStreak >= 3,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'kegiatans' => $kegiatans->map(fn ($k) => [
                    'id' => $k->id,
                    'nama' => $k->nama,
                    'tanggal' => $k->tanggal->toDateString(),
                    'jenis_pengajian' => $k->jenis_pengajian,
                ]),
                'rows' => $rows,
            ],
        ]);
    }
}
