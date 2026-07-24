<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\Kegiatan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                ->post(config('services.face.url') . '/extract');
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

    /** Absen via wajah: identifikasi peserta kegiatan, catat hadir. */
    public function identify(Request $request, Kegiatan $kegiatan): JsonResponse
    {
        abort_unless(Kegiatan::visibleTo($request->user())->whereKey($kegiatan->id)->exists(), 403);
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $extracted = $this->extract($request);
        $probe = $extracted['descriptor'];

        $pesertaIds = $kegiatan->pesertaQuery()->pluck('id');
        $descriptors = JamaahFaceDescriptor::whereIn('jamaah_id', $pesertaIds)->get();

        abort_if($descriptors->isEmpty(), 422, 'Belum ada peserta yang terdaftar wajahnya');

        // skor terbaik per jamaah (tiap jamaah punya beberapa descriptor)
        $best = ['jamaah_id' => null, 'score' => 0.0];
        foreach ($descriptors as $d) {
            $score = $this->similarity($probe, $d->descriptor);
            if ($score > $best['score']) {
                $best = ['jamaah_id' => $d->jamaah_id, 'score' => $score];
            }
        }

        $threshold = (float) config('services.face.threshold');
        if ($best['score'] < $threshold) {
            return response()->json([
                'success' => false,
                'message' => 'Wajah tidak dikenali sebagai peserta kegiatan ini',
                'data' => ['score' => round($best['score'], 4)],
            ], 404);
        }

        $jamaah = Jamaah::find($best['jamaah_id']);

        $absensi = Absensi::updateOrCreate(
            ['kegiatan_id' => $kegiatan->id, 'jamaah_id' => $jamaah->id],
            ['status' => 'hadir', 'metode' => 'face', 'waktu_absen' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => "Absensi tercatat: {$jamaah->nama_lengkap}",
            'data' => [
                'jamaah' => [
                    'id' => $jamaah->id,
                    'nama_lengkap' => $jamaah->nama_lengkap,
                    'nama_panggilan' => $jamaah->nama_panggilan,
                    'jenis_kelamin' => $jamaah->jenis_kelamin,
                    'usia' => $jamaah->usia,
                    'kategori_usia' => $jamaah->kategori_usia,
                ],
                'score' => round($best['score'], 4),
                'absensi' => $absensi,
            ],
        ]);
    }
}
