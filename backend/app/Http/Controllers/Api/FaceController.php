<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\Kegiatan;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class FaceController extends Controller
{
    /** Kirim gambar ke face-service, kembalikan descriptor 512-dim. */
    private function extract(Request $request): array
    {
        $file = $request->file('photo');

        try {
            $response = Http::timeout(15)
                ->attach('image', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
                ->post(config('services.face.url').'/extract');
        } catch (ConnectionException) {
            abort(503, 'Face service tidak tersedia');
        }

        if ($response->status() === 422) {
            abort(422, 'Wajah tidak terdeteksi pada gambar');
        }
        abort_unless($response->successful(), 502, 'Face service error');

        $json = $response->json();
        abort_unless(is_array($json['descriptor'] ?? null), 502, 'Face service mengembalikan data tidak valid');

        return $json;
    }

    /** Cosine similarity — embedding sudah L2-normalized, jadi cukup dot product. */
    private function similarity(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0.0);
        }

        return $dot;
    }

    /** Enroll wajah jamaah: simpan foto + descriptor. Minimal 3 foto sesuai rencana. */
    public function enroll(Request $request, Jamaah $jamaah): JsonResponse
    {
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $extracted = $this->extract($request);

        $path = $request->file('photo')->store("jamaah/{$jamaah->id}", 'public');
        $photo = $jamaah->photos()->create(['path' => $path]);

        JamaahFaceDescriptor::create([
            'jamaah_id' => $jamaah->id,
            'jamaah_photo_id' => $photo->id,
            'descriptor' => $extracted['descriptor'],
            'confidence' => $extracted['confidence'] ?? null,
        ]);

        $total = $jamaah->photos()->count();

        return response()->json([
            'success' => true,
            'message' => $total < 3 ? "Foto ke-{$total} tersimpan, minimal 3 foto" : 'Enroll wajah lengkap',
            'data' => ['photo' => $photo, 'total_foto' => $total],
        ], 201);
    }

    /** Absen via wajah pada kegiatan tertentu (kiosk dikunci ke satu kegiatan). */
    public function identify(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        abort_unless(Kegiatan::visibleTo($request->user())->whereKey($kegiatan->id)->exists(), 403);
        abort_if($kegiatan->libur, 422, 'Kegiatan ini ditandai libur.');
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        return $this->cocokkanDanCatat($this->extract($request)['descriptor'], collect([$kegiatan]));
    }

    /**
     * Absen via wajah tanpa memilih kegiatan: kiosk standby seharian, kegiatan
     * ditentukan dari jam sekarang. Kalau ada beberapa yang jendelanya terbuka
     * bersamaan, yang menentukan adalah siapa yang wajahnya cocok — anak masuk
     * kegiatan caberawit, dewasa masuk kegiatan umum.
     */
    public function identifyStandby(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $probe = $this->extract($request)['descriptor'];
        $kegiatans = Kegiatan::sedangBerlangsung($request->user());

        return $kegiatans->isEmpty()
            ? $this->sapaSaja($probe, $request->user())
            : $this->cocokkanDanCatat($probe, $kegiatans);
    }

    /**
     * Di luar jam kegiatan kiosk tetap mengenali wajah, tapi hanya menyapa — tidak ada
     * absensi yang dicatat dan tidak ada kegiatan untuk dicatati. Kandidatnya seluruh
     * jamaah dalam wilayah akun kiosk, bukan peserta satu kegiatan.
     */
    private function sapaSaja(array $probe, User $user): JsonResponse
    {
        $jamaahIds = Jamaah::visibleTo($user)->where('aktif', true)->pluck('id');
        $best = $this->skorTerbaik($probe, JamaahFaceDescriptor::whereIn('jamaah_id', $jamaahIds)->get());

        // wajah asing saat idle bukan kesalahan siapa-siapa — kiosk cukup diam
        abort_if($best['jamaah_id'] === null, 404, 'Belum ada pengajian saat ini');

        return response()->json([
            'success' => true,
            'message' => 'Belum ada pengajian saat ini',
            'data' => [
                'jamaah' => $this->ringkasJamaah(Jamaah::find($best['jamaah_id'])),
                'kegiatan' => null,
                'score' => round($best['score'], 4),
                'absensi' => null,
            ],
        ]);
    }

    /** @param  Collection<int, Kegiatan>  $kegiatans */
    private function cocokkanDanCatat(array $probe, Collection $kegiatans): JsonResponse
    {
        $pesertaIds = $kegiatans->flatMap(fn (Kegiatan $k) => $k->pesertaQuery()->pluck('id'))->unique();
        $descriptors = JamaahFaceDescriptor::whereIn('jamaah_id', $pesertaIds)->get();

        abort_if($descriptors->isEmpty(), 422, 'Belum ada peserta yang terdaftar wajahnya');

        $best = $this->skorTerbaik($probe, $descriptors);
        if ($best['jamaah_id'] === null) {
            return response()->json([
                'success' => false,
                'message' => 'Wajah tidak dikenali sebagai peserta kegiatan ini',
                'data' => ['score' => round($best['score'], 4)],
            ], 404);
        }

        $jamaah = Jamaah::find($best['jamaah_id']);
        $kegiatan = $kegiatans->first(fn (Kegiatan $k) => $k->pesertaQuery()->whereKey($jamaah->id)->exists());

        $absensi = Absensi::updateOrCreate(
            ['kegiatan_id' => $kegiatan->id, 'jamaah_id' => $jamaah->id],
            // keterangan dikosongkan: kalau sebelumnya izin lewat WA, alasannya sudah tidak berlaku
            ['status' => 'hadir', 'keterangan' => null, 'metode' => 'face', 'waktu_absen' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => "Absensi tercatat: {$jamaah->nama_lengkap}",
            'data' => [
                'jamaah' => $this->ringkasJamaah($jamaah),
                'kegiatan' => ['id' => $kegiatan->id, 'nama' => $kegiatan->nama],
                'score' => round($best['score'], 4),
                'absensi' => $absensi,
            ],
        ]);
    }

    /**
     * Skor tertinggi di antara semua descriptor (tiap jamaah punya beberapa foto).
     * jamaah_id null berarti tidak ada yang melewati ambang.
     *
     * @param  Collection<int, JamaahFaceDescriptor>  $descriptors
     * @return array{jamaah_id: int|null, score: float}
     */
    private function skorTerbaik(array $probe, Collection $descriptors): array
    {
        $best = ['jamaah_id' => null, 'score' => 0.0];
        foreach ($descriptors as $d) {
            $score = $this->similarity($probe, $d->descriptor);
            if ($score > $best['score']) {
                $best = ['jamaah_id' => $d->jamaah_id, 'score' => $score];
            }
        }

        if ($best['score'] < (float) config('services.face.threshold')) {
            $best['jamaah_id'] = null;
        }

        return $best;
    }

    /** Kolom yang dipakai kiosk untuk menyapa (sapaan ditentukan dari jenis kelamin + usia). */
    private function ringkasJamaah(Jamaah $jamaah): array
    {
        return [
            'id' => $jamaah->id,
            'nama_lengkap' => $jamaah->nama_lengkap,
            'nama_panggilan' => $jamaah->nama_panggilan,
            'jenis_kelamin' => $jamaah->jenis_kelamin,
            'usia' => $jamaah->usia,
            'kategori_usia' => $jamaah->kategori_usia,
        ];
    }
}
