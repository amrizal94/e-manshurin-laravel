<?php

namespace App\Support;

use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Jamaah yang foto-fotonya tidak saling mengenali.
 *
 * Penjaga di FaceController::enroll() baru ada belakangan, jadi foto orang lain yang
 * terlanjur masuk ke kartu yang salah tetap duduk di sana tanpa ada yang protes —
 * dan kiosk akan mencatat hadir atas nama orang yang tidak datang. Ini yang
 * memunculkannya untuk diperiksa manusia; tidak ada yang dihapus otomatis, karena
 * dari skor saja mustahil tahu foto mana dari sepasang itu yang salah.
 *
 * Ambangnya ambang kiosk, bukan ambang enroll yang longgar: pertanyaannya di sini
 * "apakah kiosk akan menganggap dua foto ini satu orang", dan kalau tidak, salah
 * satunya memang perlu dilihat.
 */
class FotoWajahJanggal
{
    /**
     * Skor terendah antar foto milik jamaah yang sama, hanya yang di bawah ambang.
     *
     * ponytail: semua descriptor didekripsi di PHP, jadi biayanya tumbuh lurus dengan
     * jumlah foto. Di bawah beberapa ribu foto ini tidak terasa; kalau sudah terasa,
     * simpan skor terendahnya saat enroll dan baca kolom itu.
     *
     * @return Collection<int, array{jamaah_id: int, nama_lengkap: string, kelompok: string|null, skor: float, jumlah_foto: int}>
     */
    public static function untuk(User $actor): Collection
    {
        $jamaahs = Jamaah::visibleTo($actor)->with('kelompok:id,nama')->get(['id', 'nama_lengkap', 'kelompok_id'])->keyBy('id');

        $ambang = (float) config('services.face.threshold');

        return JamaahFaceDescriptor::whereIn('jamaah_id', $jamaahs->keys())
            ->get(['id', 'jamaah_id', 'descriptor'])
            ->groupBy('jamaah_id')
            ->map(function (Collection $milik, int $jamaahId) use ($jamaahs) {
                // Satu foto tidak bisa berselisih dengan siapa pun.
                if ($milik->count() < 2) {
                    return null;
                }

                $jamaah = $jamaahs[$jamaahId];

                return [
                    'jamaah_id' => $jamaahId,
                    'nama_lengkap' => $jamaah->nama_lengkap,
                    'kelompok' => $jamaah->kelompok?->nama,
                    'skor' => round(self::skorTerendah($milik), 3),
                    'jumlah_foto' => $milik->count(),
                ];
            })
            ->filter(fn (?array $baris) => $baris !== null && $baris['skor'] < $ambang)
            ->sortBy('skor')
            ->values();
    }

    /** @param  Collection<int, JamaahFaceDescriptor>  $milik */
    private static function skorTerendah(Collection $milik): float
    {
        $terendah = 1.0;
        $semua = $milik->values();

        for ($i = 0; $i < $semua->count(); $i++) {
            for ($j = $i + 1; $j < $semua->count(); $j++) {
                $terendah = min($terendah, JamaahFaceDescriptor::similarity(
                    $semua[$i]->descriptor,
                    $semua[$j]->descriptor
                ));
            }
        }

        return $terendah;
    }
}
