<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesStruktur;
use App\Http\Controllers\Controller;
use App\Models\JadwalRutin;
use App\Models\Kegiatan;
use App\Models\User;
use App\Support\PenghasilKegiatan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JadwalRutinController extends Controller
{
    use ScopesStruktur;

    public function __construct(private PenghasilKegiatan $penghasil) {}

    private function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'jenis_pengajian' => ['required', 'in:'.implode(',', array_keys(Kegiatan::KATEGORI_MAP))],
            'daerah_id' => ['nullable', 'exists:daerahs,id'],
            'desa_id' => ['nullable', 'exists:desas,id'],
            'kelompok_id' => ['nullable', 'exists:kelompoks,id'],
            'hari' => ['required', 'array', 'min:1'],
            'hari.*' => ['integer', 'between:0,6'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'aktif' => ['boolean'],
        ];
    }

    /** Ketiga kolom target selalu ikut walau klien tidak mengirimnya — kalau tidak, target lama tertinggal sebagai target kedua. */
    private function dataTervalidasi(Request $request): array
    {
        $data = $request->validate($this->rules())
            + ['daerah_id' => null, 'desa_id' => null, 'kelompok_id' => null];

        $data['hari'] = array_values(array_unique(array_map(intval(...), $data['hari'])));
        // Nilai bawaan kolom cuma berlaku di basis data; model hasil create() tidak
        // mengetahuinya, dan penghasil kegiatan yang membaca $jadwal->aktif akan
        // menganggap jadwal baru sudah nonaktif.
        $data['aktif'] ??= true;

        return $data;
    }

    private function assertTarget(User $user, array $data): void
    {
        $targets = array_filter([$data['daerah_id'] ?? null, $data['desa_id'] ?? null, $data['kelompok_id'] ?? null]);
        abort_if(count($targets) !== 1, 422, 'Isi tepat satu target struktur (daerah, desa, atau kelompok)');
        abort_unless($this->targetWithinScope($user, $data), 403, 'Target struktur di luar wilayah akun Anda');
    }

    private function assertVisible(Request $request, JadwalRutin $jadwal): void
    {
        abort_unless(JadwalRutin::visibleTo($request->user())->whereKey($jadwal->id)->exists(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $jadwals = JadwalRutin::visibleTo($request->user())
            ->with('daerah:id,nama', 'desa:id,nama', 'kelompok:id,nama')
            ->withCount(['kegiatans as kegiatan_mendatang_count' => fn ($q) => $q->whereDate('tanggal', '>=', Kegiatan::sekarangLokal()->toDateString())])
            ->orderBy('nama')
            ->get();

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $jadwals]);
    }

    /**
     * Kegiatannya langsung diterbitkan sebulan ke depan, dan jumlahnya dilaporkan balik.
     * Bentrok baru benar-benar ketahuan waktu tanggalnya nyata, jadi lebih jujur
     * melaporkan "8 dibuat, 2 dilewati" daripada mensimulasikannya lebih dulu.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->dataTervalidasi($request);
        $this->assertTarget($request->user(), $data);
        $data['created_by'] = $request->user()->id;

        $jadwal = JadwalRutin::create($data);
        $hasil = $this->penghasil->untuk($jadwal);

        return response()->json([
            'success' => true,
            'message' => $this->ringkasan($hasil),
            'data' => ['jadwal' => $jadwal, ...$hasil],
        ], 201);
    }

    /**
     * Kegiatan yang sudah terbit tidak ikut berubah. Sebagiannya mungkin sudah ada
     * absensinya, dan menggeser jamnya di belakang layar mengubah arti catatan yang
     * sudah jadi. Yang berubah cuma yang diterbitkan sesudah ini.
     */
    public function update(Request $request, JadwalRutin $jadwalRutin): JsonResponse
    {
        $this->assertVisible($request, $jadwalRutin);
        $data = $this->dataTervalidasi($request);
        $this->assertTarget($request->user(), $data);
        $jadwalRutin->update($data);

        $hasil = $this->penghasil->untuk($jadwalRutin);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal diperbarui. '.$this->ringkasan($hasil).' Kegiatan yang sudah terbit tidak diubah.',
            'data' => ['jadwal' => $jadwalRutin, ...$hasil],
        ]);
    }

    /**
     * Templatnya saja yang hilang. Kegiatan yang sudah terbit tetap ada sebagai kegiatan
     * biasa — sebagian sudah punya absensi, dan itu bukan milik jadwalnya.
     */
    public function destroy(Request $request, JadwalRutin $jadwalRutin): JsonResponse
    {
        $this->assertVisible($request, $jadwalRutin);
        $mendatang = $jadwalRutin->kegiatans()
            ->whereDate('tanggal', '>=', Kegiatan::sekarangLokal()->toDateString())
            ->count();
        $jadwalRutin->delete();

        return response()->json([
            'success' => true,
            'message' => "Jadwal dihapus. {$mendatang} kegiatan yang sudah terbit tetap ada — hapus sendiri kalau memang tidak jadi.",
            'data' => null,
        ]);
    }

    /** @param array{dibuat:int, sudah_ada:int, bentrok:list<string>} $hasil */
    private function ringkasan(array $hasil): string
    {
        $pesan = "{$hasil['dibuat']} kegiatan diterbitkan sampai ".JadwalRutin::HORIZON_HARI.' hari ke depan.';

        return $hasil['bentrok'] === []
            ? $pesan
            : $pesan.' '.count($hasil['bentrok']).' tanggal dilewati karena bentrok: '.implode('; ', $hasil['bentrok']);
    }
}
