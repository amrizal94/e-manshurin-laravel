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
