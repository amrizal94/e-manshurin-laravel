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
        $this->assertSame(2, $response->json('data.per_kategori_usia.remaja.L'));
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

    /**
     * Angka yang bisa diklik harus mendarat di daftar yang jumlahnya sama. Saringan di
     * sini salinan persis dari link di web/app/(app)/dashboard/page.tsx — kalau salah
     * satunya diubah, yang lain ikut diubah, dan test ini yang mengingatkan.
     */
    public function test_setiap_angka_mendarat_di_daftar_yang_jumlahnya_sama(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 05:00:00', 'UTC'));
        Role::findOrCreate('admin');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'A1']);
        $luar = Kelompok::create([
            'desa_id' => Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa B'])->id,
            'nama' => 'B1',
        ]);

        $buat = fn (string $jk, string $kategori, array $tambahan = []) => Jamaah::create([
            'kelompok_id' => $kelompok->id,
            'nama_lengkap' => uniqid('J'),
            'jenis_kelamin' => $jk,
            'kategori_usia' => $kategori,
            ...$tambahan,
        ]);

        $buat('L', 'remaja');
        $buat('L', 'remaja', ['status_mubaligh' => true]);
        $buat('P', 'remaja');
        $buat('P', 'caberawit');
        $buat('P', 'janda');
        $buat('L', 'menikah', ['status_mubaligh' => true]);
        // Yang paling sering bikin angka meleset: tidak aktif dan di luar wilayah.
        $buat('L', 'remaja', ['aktif' => false, 'status_mubaligh' => true]);
        $buat('P', 'remaja', ['kelompok_id' => $luar->id]);

        $admin = User::factory()->create(['desa_id' => $desa->id]);
        $admin->assignRole('admin');

        foreach (['2026-09-01', '2026-09-30', '2026-08-31', '2026-10-01'] as $tanggal) {
            Kegiatan::create([
                'nama' => "Pengajian {$tanggal}",
                'jenis_pengajian' => 'umum',
                'kelompok_id' => $kelompok->id,
                'tanggal' => $tanggal,
                'created_by' => $admin->id,
            ]);
        }

        $data = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json('data');
        $jumlah = fn (string $url) => $this->actingAs($admin)->getJson($url)->assertOk()->json('data.total');

        $this->assertSame($data['total_jamaah'], $jumlah('/api/jamaahs?aktif=1'));
        $this->assertSame($data['total_laki'], $jumlah('/api/jamaahs?aktif=1&jenis_kelamin=L'));
        $this->assertSame($data['total_perempuan'], $jumlah('/api/jamaahs?aktif=1&jenis_kelamin=P'));
        $this->assertSame($data['total_mubaligh'], $jumlah('/api/jamaahs?aktif=1&status_mubaligh=1'));
        $this->assertSame($data['total_tidak_aktif'], $jumlah('/api/jamaahs?aktif=0'));
        $this->assertSame($data['kegiatan_bulan_ini'], $jumlah('/api/kegiatans?dari=2026-09-01&sampai=2026-09-30'));

        foreach ($data['per_kategori_usia'] as $kategori => $jk) {
            $this->assertSame(
                ($jk['L'] ?? 0) + ($jk['P'] ?? 0),
                $jumlah("/api/jamaahs?aktif=1&kategori_usia={$kategori}"),
                "kategori {$kategori}"
            );
        }

        // Angka yang semuanya nol akan lolos semua pemeriksaan di atas tanpa membuktikan apa-apa.
        $this->assertSame(['L' => 2, 'P' => 1], $data['per_kategori_usia']['remaja']);
        $this->assertSame(2, $data['kegiatan_bulan_ini']);
        $this->assertSame(2, $data['total_mubaligh']);
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
