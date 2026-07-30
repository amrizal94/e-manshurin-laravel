<?php

namespace App\Console\Commands;

use App\Models\Jamaah;
use Illuminate\Console\Command;

class CekNomorWa extends Command
{
    protected $signature = 'wa:cek-nomor';

    protected $description = 'Ringkas kesiapan izin lewat WA: berapa jamaah punya no_hp, dan berapa yang terbantu nomor kepala keluarga';

    public function handle(): int
    {
        $jamaah = Jamaah::where('aktif', true)->get(['id', 'no_hp', 'kategori_usia', 'kepala_keluarga_id']);
        $adaHp = fn ($j) => trim((string) $j->no_hp) !== '';

        $baris = $jamaah->groupBy('kategori_usia')->map(fn ($g, $kategori) => [
            $kategori,
            $g->count(),
            $g->filter($adaHp)->count(),
            $g->count() > 0 ? round($g->filter($adaHp)->count() / $g->count() * 100) . '%' : '-',
        ])->sortBy(0)->values();

        $this->table(['Kategori', 'Total', 'Ada no_hp', 'Persen'], $baris);

        $punyaHp = $jamaah->filter($adaHp);
        $idPunyaHp = $punyaHp->pluck('id');

        // Anak / lansia tanpa WA sendiri: izinnya bisa lewat nomor kepala keluarga
        $terwakili = $jamaah->filter(fn ($j) => ! $adaHp($j)
            && $j->kepala_keluarga_id
            && $idPunyaHp->contains($j->kepala_keluarga_id));

        $terjangkau = $punyaHp->count() + $terwakili->count();

        $this->newLine();
        $this->line('Jamaah aktif             : ' . $jamaah->count());
        $this->line('Punya no_hp sendiri      : ' . $punyaHp->count());
        $this->line('Terwakili kepala keluarga: ' . $terwakili->count());
        $this->line('Total terjangkau WA      : ' . $terjangkau . ' dari ' . $jamaah->count());

        $nomorGanda = $punyaHp->groupBy(fn ($j) => preg_replace('/\D/', '', (string) $j->no_hp))
            ->filter(fn ($g) => $g->count() > 1);

        if ($nomorGanda->isNotEmpty()) {
            $this->newLine();
            $this->warn($nomorGanda->count() . ' nomor dipakai lebih dari satu jamaah (izin dari nomor itu akan dibalas daftar nama).');
        }

        $tanpaJalur = $jamaah->count() - $terjangkau;
        if ($tanpaJalur > 0) {
            $this->newLine();
            $this->line("{$tanpaJalur} jamaah belum punya jalur WA: harus ditulis namanya oleh orang lain.");
            $this->line('Isi kepala_keluarga_id mereka biasanya lebih cepat daripada mengejar no_hp satu per satu.');
        }

        return self::SUCCESS;
    }
}
