<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KegiatanController extends Controller
{
    private function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'jenis_pengajian' => ['required', 'in:umum,caberawit,praremaja,remaja,usman'],
            'daerah_id' => ['nullable', 'exists:daerahs,id'],
            'desa_id' => ['nullable', 'exists:desas,id'],
            'kelompok_id' => ['nullable', 'exists:kelompoks,id'],
            'tanggal' => ['required', 'date'],
            'jam_mulai' => ['nullable', 'date_format:H:i'],
            'jam_selesai' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** Tepat satu target struktur, dan target harus di dalam scope user. */
    private function assertTarget(User $user, array $data): void
    {
        $targets = array_filter([$data['daerah_id'] ?? null, $data['desa_id'] ?? null, $data['kelompok_id'] ?? null]);
        abort_if(count($targets) !== 1, 422, 'Isi tepat satu target struktur (daerah, desa, atau kelompok)');

        if (! $user->daerah_id && ! $user->desa_id && ! $user->kelompok_id) {
            return; // super admin
        }

        $allowed = match (true) {
            (bool) $user->kelompok_id => ($data['kelompok_id'] ?? null) == $user->kelompok_id,
            (bool) $user->desa_id => ($data['desa_id'] ?? null) == $user->desa_id
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->where('desa_id', $user->desa_id)->exists()),
            default => ($data['daerah_id'] ?? null) == $user->daerah_id
                || (($data['desa_id'] ?? null) && \App\Models\Desa::where('id', $data['desa_id'])->where('daerah_id', $user->daerah_id)->exists())
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->whereHas('desa', fn ($q) => $q->where('daerah_id', $user->daerah_id))->exists()),
        };

        abort_unless($allowed, 403, 'Target struktur di luar wilayah akun Anda');
    }

    private function assertVisible(Request $request, Kegiatan $kegiatan): void
    {
        abort_unless(Kegiatan::visibleTo($request->user())->whereKey($kegiatan->id)->exists(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Kegiatan::visibleTo($request->user())
            ->with('daerah:id,nama', 'desa:id,nama', 'kelompok:id,nama')
            ->withCount('absensis')
            ->orderByDesc('tanggal');

        if ($request->filled('jenis_pengajian')) {
            $query->where('jenis_pengajian', $request->string('jenis_pengajian'));
        }
        if ($request->filled('dari')) {
            $query->whereDate('tanggal', '>=', $request->date('dari'));
        }
        if ($request->filled('sampai')) {
            $query->whereDate('tanggal', '<=', $request->date('sampai'));
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $query->paginate($request->integer('per_page', 25)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->assertTarget($request->user(), $data);
        $data['created_by'] = $request->user()->id;

        return response()->json(['success' => true, 'message' => 'Kegiatan dibuat', 'data' => Kegiatan::create($data)], 201);
    }

    public function show(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        $this->assertVisible($request, $kegiatan);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $kegiatan->load('daerah:id,nama', 'desa:id,nama', 'kelompok:id,nama', 'creator:id,name'),
        ]);
    }

    public function update(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        $this->assertVisible($request, $kegiatan);
        $data = $request->validate($this->rules());
        $this->assertTarget($request->user(), $data);
        $kegiatan->update($data);

        return response()->json(['success' => true, 'message' => 'Kegiatan diperbarui', 'data' => $kegiatan]);
    }

    public function destroy(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        $this->assertVisible($request, $kegiatan);
        $kegiatan->delete();

        return response()->json(['success' => true, 'message' => 'Kegiatan dihapus', 'data' => null]);
    }

    /** Daftar jamaah yang berhak absen + status absensinya. */
    public function peserta(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        $this->assertVisible($request, $kegiatan);

        $absensiByJamaah = $kegiatan->absensis()->get()->keyBy('jamaah_id');

        $peserta = $kegiatan->pesertaQuery()
            ->with('kelompok:id,nama')
            ->orderBy('nama_lengkap')
            ->get()
            ->map(function ($jamaah) use ($absensiByJamaah) {
                $absensi = $absensiByJamaah->get($jamaah->id);
                $jamaah->setAttribute('absensi', $absensi ? [
                    'status' => $absensi->status,
                    'keterangan' => $absensi->keterangan,
                    'metode' => $absensi->metode,
                    'waktu_absen' => $absensi->waktu_absen,
                ] : null);

                return $jamaah;
            });

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $peserta]);
    }

    /** Catat / ubah absensi satu jamaah (upsert). */
    public function storeAbsensi(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        $this->assertVisible($request, $kegiatan);

        $data = $request->validate([
            'jamaah_id' => ['required', 'exists:jamaahs,id'],
            'status' => ['required', 'in:hadir,izin,alpha'],
            'keterangan' => ['nullable', 'string'],
            'metode' => ['nullable', 'in:face,manual,wa'],
        ]);

        abort_unless(
            $kegiatan->pesertaQuery()->whereKey($data['jamaah_id'])->exists(),
            422,
            'Jamaah tidak termasuk peserta kegiatan ini'
        );

        $absensi = Absensi::updateOrCreate(
            ['kegiatan_id' => $kegiatan->id, 'jamaah_id' => $data['jamaah_id']],
            [
                'status' => $data['status'],
                'keterangan' => $data['keterangan'] ?? null,
                'metode' => $data['metode'] ?? 'manual',
                'waktu_absen' => now(),
            ]
        );

        return response()->json(['success' => true, 'message' => 'Absensi tercatat', 'data' => $absensi]);
    }
}
