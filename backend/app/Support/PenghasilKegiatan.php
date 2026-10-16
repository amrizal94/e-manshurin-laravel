<?php

namespace App\Support;

use App\Models\JadwalRutin;
use App\Models\Kegiatan;

/**
 * Menerbitkan kegiatan dari jadwal rutin.
 *
 * Dipakai dua-duanya: cron harian yang memperpanjang jadwal, dan penyimpanan jadwal
 * baru yang langsung menerbitkan sebulan ke depan. Satu jalur supaya yang dilihat
 * petugas sesudah menyimpan sama persis dengan yang dikerjakan cron besoknya.
 */
class PenghasilKegiatan
{
    /**
     * Samakan jam kegiatan yang sudah terbit dengan jadwalnya.
     *
     * Dibutuhkan sejak jadwal bisa berpatokan waktu sholat: mengubah patokan tanpa ini
     * meninggalkan 30 hari kegiatan berjam lama, dan petugas melihat jadwal baru di satu
     * layar tapi jam lama di layar sebelahnya.
     *
     * Yang sudah ada absensinya tidak disentuh. Mengubah jam kegiatan yang sudah dihadiri
     * orang berarti memalsukan catatan.
     *
     * @return int jumlah kegiatan yang jamnya berubah
     */
    public function selaraskan(JadwalRutin $jadwal): int
    {
        $diubah = 0;

        $kegiatans = Kegiatan::where('jadwal_rutin_id', $jadwal->id)
            ->whereDate('tanggal', '>=', Kegiatan::sekarangLokal()->toDateString())
            ->withCount('absensis')
            ->get();

        foreach ($kegiatans as $kegiatan) {
            if ($kegiatan->absensis_count > 0) {
                continue;
            }

            [$mulai, $selesai] = $jadwal->jamUntuk($kegiatan->tanggal);

            if (substr((string) $kegiatan->jam_mulai, 0, 5) === $mulai
                && substr((string) $kegiatan->jam_selesai, 0, 5) === $selesai) {
                continue;
            }

            $kegiatan->update(['jam_mulai' => $mulai, 'jam_selesai' => $selesai]);
            $diubah++;
        }

        return $diubah;
    }

    /**
     * @return array{dibuat: int, sudah_ada: int, bentrok: list<string>}
     */
    public function untuk(JadwalRutin $jadwal, ?int $hari = null): array
    {
        $hasil = ['dibuat' => 0, 'sudah_ada' => 0, 'bentrok' => []];

        if (! $jadwal->aktif) {
            return $hasil;
        }

        // Tanggal yang sudah punya barisnya dilewati apa pun isinya — termasuk yang
        // ditandai libur. Itulah yang bikin tanda libur bertahan: kalau dibuat ulang,
        // liburnya hilang tiap kali cron jalan.
        $sudahAda = Kegiatan::where('jadwal_rutin_id', $jadwal->id)
            ->pluck('tanggal')
            ->map(fn ($t) => $t->toDateString())
            ->flip();

        foreach ($jadwal->tanggalDalamHorizon(hari: $hari) as $tanggal) {
            if ($sudahAda->has($tanggal->toDateString())) {
                $hasil['sudah_ada']++;

                continue;
            }

            $calon = $jadwal->kegiatanUntuk($tanggal);

            // Satu tanggal yang bentrok dilewati dan dilaporkan — bukan menggagalkan
            // seluruh penerbitan. Sebulan jadwal batal gara-gara satu hari yang
            // berhimpit adalah kerugian yang jauh lebih besar daripada satu hari kosong.
            if ($lain = $calon->bentrok()) {
                $hasil['bentrok'][] = $tanggal->toDateString().': bentrok dengan "'.$lain->nama.'" ('.$lain->rentangJam().')';

                continue;
            }

            $calon->save();
            $hasil['dibuat']++;
        }

        return $hasil;
    }
}
