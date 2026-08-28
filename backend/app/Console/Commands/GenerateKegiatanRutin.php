<?php

namespace App\Console\Commands;

use App\Models\JadwalRutin;
use App\Support\PenghasilKegiatan;
use Illuminate\Console\Command;

/**
 * Memperpanjang kegiatan dari seluruh jadwal rutin yang aktif.
 *
 * Aman dijalankan berkali-kali sehari: tanggal yang barisnya sudah ada dilewati.
 */
class GenerateKegiatanRutin extends Command
{
    protected $signature = 'kegiatan:generate {--hari= : Berapa hari ke depan (bawaan '.JadwalRutin::HORIZON_HARI.')}';

    protected $description = 'Menerbitkan kegiatan dari jadwal rutin yang aktif';

    public function handle(PenghasilKegiatan $penghasil): int
    {
        $hari = $this->option('hari') !== null ? (int) $this->option('hari') : null;
        $total = ['dibuat' => 0, 'sudah_ada' => 0];

        foreach (JadwalRutin::where('aktif', true)->get() as $jadwal) {
            $hasil = $penghasil->untuk($jadwal, $hari);
            $total['dibuat'] += $hasil['dibuat'];
            $total['sudah_ada'] += $hasil['sudah_ada'];

            // Bentrok tidak menggagalkan apa pun, tapi harus kelihatan di log — jadwal
            // yang diam-diam bolong tiap Rabu tidak akan ada yang menyadari.
            foreach ($hasil['bentrok'] as $pesan) {
                $this->warn("[{$jadwal->nama}] {$pesan}");
            }
        }

        $this->info("Kegiatan dibuat: {$total['dibuat']}, sudah ada: {$total['sudah_ada']}");

        return self::SUCCESS;
    }
}
