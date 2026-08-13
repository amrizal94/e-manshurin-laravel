<?php

namespace App\Console\Commands;

use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuatAkunStruktur extends Command
{
    protected $signature = 'akun:buat {--dry-run : Tampilkan rencananya saja, tanpa menulis apa pun}';

    protected $description = 'Buatkan akun admin dan absensi untuk desa/kelompok yang belum punya';

    /** Nama jadi bagian email: sisakan huruf dan angka saja ("Kresek 1" jadi "kresek1"). */
    private static function slug(string $nama): string
    {
        return Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $nama));
    }

    public function handle(): int
    {
        $kering = $this->option('dry-run');

        $rencana = [];
        foreach (Desa::orderBy('nama')->get() as $desa) {
            $slug = 'desa' . self::slug($desa->nama);
            $rencana[] = ['desa_id', $desa->id, 'admin', "admin{$slug}@gmail.com", "Admin Desa {$desa->nama}"];
            $rencana[] = ['desa_id', $desa->id, 'absensi', "absensi{$slug}@gmail.com", "Absensi Desa {$desa->nama}"];
        }
        foreach (Kelompok::orderBy('nama')->get() as $kelompok) {
            $slug = self::slug($kelompok->nama);
            $rencana[] = ['kelompok_id', $kelompok->id, 'admin', "admin{$slug}@gmail.com", "Admin Kelompok {$kelompok->nama}"];
            $rencana[] = ['kelompok_id', $kelompok->id, 'absensi', "absensi{$slug}@gmail.com", "Absensi Kelompok {$kelompok->nama}"];
        }

        $dibuat = [];
        $dilewati = 0;
        $emailDipakai = User::pluck('email')->all();

        foreach ($rencana as [$kolom, $id, $peran, $email, $nama]) {
            // Patokannya struktur + peran, bukan email: akun lama yang emailnya beda pola
            // tetap dihitung sudah ada, jadi perintah ini tidak bikin akun kembar.
            if (User::where($kolom, $id)->whereHas('roles', fn ($q) => $q->where('name', $peran))->exists()) {
                $dilewati++;
                continue;
            }

            if (in_array($email, $emailDipakai, true)) {
                $this->warn("{$nama} dilewati: email {$email} sudah dipakai akun lain.");
                continue;
            }

            $password = $kering ? '-' : Str::password(12, symbols: false);
            $emailDipakai[] = $email;
            $dibuat[] = [$nama, $email, $password, $peran];

            if ($kering) {
                continue;
            }

            User::create(['name' => $nama, 'email' => $email, 'password' => $password, $kolom => $id])
                ->syncRoles([$peran]);
        }

        if ($kering) {
            $this->table(['Nama', 'Email', 'Peran'], array_map(fn ($b) => [$b[0], $b[1], $b[3]], $dibuat));
            $this->line(count($dibuat) . ' akan dibuat, ' . $dilewati . ' dilewati (sudah punya akun).');

            return self::SUCCESS;
        }

        $csv = collect([['Nama', 'Email', 'Password', 'Peran'], ...$dibuat])
            ->map(fn ($kolom) => collect($kolom)->map(fn ($sel) => '"' . str_replace('"', '""', $sel) . '"')->implode(','))
            ->implode("\r\n");

        $berkas = 'akun-' . now(config('app.zona_lokal'))->format('Ymd-Hi') . '.csv';
        Storage::disk('local')->put($berkas, $csv);

        $this->info(count($dibuat) . ' akun dibuat, ' . $dilewati . ' dilewati (sudah punya akun).');
        $this->line('Password tersimpan di: ' . Storage::disk('local')->path($berkas));
        $this->warn('Unduh berkas itu, bagikan passwordnya, lalu hapus berkasnya dari server.');

        return self::SUCCESS;
    }
}
