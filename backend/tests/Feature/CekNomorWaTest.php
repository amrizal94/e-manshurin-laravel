<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kelompok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CekNomorWaTest extends TestCase
{
    use RefreshDatabase;

    public function test_menghitung_jamaah_yang_terjangkau_wa(): void
    {
        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $buat = fn (array $atribut) => Jamaah::create($atribut + [
            'kelompok_id' => $kelompok->id,
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $ayah = $buat(['nama_lengkap' => 'Ayah', 'no_hp' => '081234567890']);
        $buat(['nama_lengkap' => 'Anak', 'kategori_usia' => 'caberawit', 'kepala_keluarga_id' => $ayah->id]);
        $buat(['nama_lengkap' => 'Sendirian']);

        $this->artisan('wa:cek-nomor')
            ->expectsOutputToContain('Punya no_hp sendiri      : 1')
            ->expectsOutputToContain('Terwakili kepala keluarga: 1')
            ->expectsOutputToContain('Total terjangkau WA      : 2 dari 3')
            ->expectsOutputToContain('1 jamaah belum punya jalur WA')
            ->assertSuccessful();
    }
}
