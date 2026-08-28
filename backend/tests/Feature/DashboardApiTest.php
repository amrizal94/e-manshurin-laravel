<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_scoped_ke_desa(): void
    {
        Role::findOrCreate('admin');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desaA = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $desaB = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa B']);
        $kelompokA1 = Kelompok::create(['desa_id' => $desaA->id, 'nama' => 'A1']);
        $kelompokA2 = Kelompok::create(['desa_id' => $desaA->id, 'nama' => 'A2']);
        $kelompokB1 = Kelompok::create(['desa_id' => $desaB->id, 'nama' => 'B1']);

        foreach ([$kelompokA1, $kelompokA2, $kelompokB1] as $i => $kelompok) {
            Jamaah::create([
                'kelompok_id' => $kelompok->id,
                'nama_lengkap' => "Jamaah {$i}",
                'jenis_kelamin' => 'L',
                'kategori_usia' => 'remaja',
            ]);
        }

        $adminDesaA = User::factory()->create(['desa_id' => $desaA->id]);
        $adminDesaA->assignRole('admin');

        $response = $this->actingAs($adminDesaA)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(2, $response->json('data.total_jamaah'));
        $this->assertSame(2, $response->json('data.jumlah_kelompok'));
        $this->assertNull($response->json('data.jumlah_desa'));
        $this->assertSame(2, $response->json('data.per_kategori_usia.remaja'));
    }

    public function test_angka_orang_dihitung_dari_jamaah_aktif_saja(): void
    {
        Role::findOrCreate('admin');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'A1']);

        $buat = fn (string $nama, array $tambahan = []) => Jamaah::create([
            'kelompok_id' => $kelompok->id,
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            ...$tambahan,
        ]);

        $sugeng = $buat('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $buat('Siti', ['jenis_kelamin' => 'P', 'status_kk' => 'istri', 'kepala_keluarga_id' => $sugeng->id]);
        $buat('Lepas Satu');
        $buat('Lepas Dua', ['jenis_kelamin' => 'P']);
        // Yang tidak aktif tidak boleh ikut angka mana pun, termasuk KK.
        $buat('Pindah', ['status_kk' => 'kepala_keluarga', 'aktif' => false]);

        $admin = User::factory()->create(['desa_id' => $desa->id]);
        $admin->assignRole('admin');

        $data = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(4, $data['total_jamaah']);
        $this->assertSame(2, $data['total_laki']);
        $this->assertSame(2, $data['total_perempuan']);
        $this->assertSame(1, $data['total_kk']);
        $this->assertSame(2, $data['belum_masuk_keluarga']);
        // Inilah yang gampang rusak diam-diam waktu saringan diubah.
        $this->assertSame($data['total_jamaah'], $data['total_laki'] + $data['total_perempuan']);
    }

    /** Tanggal 1 dini hari WIB, UTC masih di bulan sebelumnya. */
    public function test_kegiatan_bulan_ini_ikut_zona_lokal_bukan_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 18:00:00', 'UTC')); // 1 September 01.00 WIB
        Role::findOrCreate('admin');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'A1']);

        $admin = User::factory()->create(['desa_id' => $desa->id]);
        $admin->assignRole('admin');

        Kegiatan::create([
            'nama' => 'Pengajian 1 September',
            'jenis_pengajian' => 'umum',
            'kelompok_id' => $kelompok->id,
            'tanggal' => '2026-09-01',
            'jam_mulai' => '19:00',
            'jam_selesai' => '21:00',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk();
        $this->assertSame(1, $response->json('data.kegiatan_bulan_ini'));
    }
}
