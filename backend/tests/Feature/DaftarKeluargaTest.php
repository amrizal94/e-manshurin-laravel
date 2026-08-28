<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DaftarKeluargaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Kelompok $kelompok;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Wonokasian']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Klanderan']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** @param  array<string, mixed>  $tambahan */
    private function jamaah(string $nama, array $tambahan = []): Jamaah
    {
        return Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            ...$tambahan,
        ]);
    }

    private function cari(string $query = ''): TestResponse
    {
        return $this->actingAs($this->admin)->getJson('/api/jamaahs/keluarga?'.$query);
    }

    /** @return array{0: Jamaah, 1: Jamaah, 2: Jamaah} bapak, ibu, anak */
    private function keluargaSugeng(): array
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KK-01']);
        $siti = $this->jamaah('Siti', ['status_kk' => 'istri', 'kode_keluarga' => 'KK-01', 'kepala_keluarga_id' => $sugeng->id]);
        $januar = $this->jamaah('Januar Agung', ['status_kk' => 'anak', 'kode_keluarga' => 'KK-01', 'kepala_keluarga_id' => $sugeng->id]);

        return [$sugeng, $siti, $januar];
    }

    public function test_mencari_nama_bapak_memunculkan_seisi_rumah(): void
    {
        $this->keluargaSugeng();
        $this->jamaah('Orang Lain');

        $res = $this->cari('search=Sugeng')->assertOk();

        $res->assertJsonPath('data.total', 1);
        $this->assertSame(
            ['Januar Agung', 'Siti', 'Sugeng'],
            collect($res->json('data.data.0.anggota'))->pluck('nama_lengkap')->all()
        );
    }

    public function test_mencari_nama_anak_memunculkan_seisi_rumah_juga(): void
    {
        $this->keluargaSugeng();

        $res = $this->cari('search=Januar')->assertOk();

        $res->assertJsonPath('data.total', 1);
        $this->assertCount(3, $res->json('data.data.0.anggota'));
        $this->assertSame('KK-01', $res->json('data.data.0.kode_keluarga'));
    }

    public function test_anggota_tanpa_kode_ikut_kelompok_kepalanya(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KK-01']);
        // Ditambahkan lewat form, jadi cuma menunjuk kepala keluarga tanpa kode.
        $this->jamaah('Rian', ['status_kk' => 'anak', 'kepala_keluarga_id' => $sugeng->id]);

        $res = $this->cari('search=Rian')->assertOk();

        $res->assertJsonPath('data.total', 1);
        $this->assertCount(2, $res->json('data.data.0.anggota'));
    }

    public function test_keluarga_tanpa_kepala_keluarga_ditandai(): void
    {
        $this->jamaah('Siti', ['status_kk' => 'istri', 'kode_keluarga' => 'KK-09']);
        $this->jamaah('Rian', ['status_kk' => 'anak', 'kode_keluarga' => 'KK-09']);

        $this->cari('search=KK-09')
            ->assertOk()
            ->assertJsonPath('data.data.0.masalah.0', 'Belum ada yang berstatus Kepala Keluarga')
            ->assertJsonPath('data.data.0.kepala_keluarga_id', null);
    }

    public function test_jamaah_lepas_ditandai_belum_masuk_keluarga(): void
    {
        $this->jamaah('Sendirian');

        $this->cari('search=Sendirian')
            ->assertOk()
            ->assertJsonPath('data.data.0.masalah.0', 'Belum masuk keluarga mana pun');
    }

    public function test_status_kk_terisi_tapi_belum_tersambung_ditandai_khusus(): void
    {
        $this->jamaah('Menggantung', ['status_kk' => 'anak']);

        $this->cari('search=Menggantung')
            ->assertOk()
            ->assertJsonPath('data.data.0.masalah.0', fn ($p) => str_contains($p, 'belum tersambung'));
    }

    public function test_anggota_yang_belum_diisi_status_kk_dihitung(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KK-01']);
        $this->jamaah('Siti', ['kode_keluarga' => 'KK-01', 'kepala_keluarga_id' => $sugeng->id]);

        $this->cari('search=Sugeng')
            ->assertOk()
            ->assertJsonPath('data.data.0.masalah.0', '1 anggota belum diisi status KK-nya');
    }

    public function test_keluarga_lengkap_tidak_punya_masalah(): void
    {
        $this->keluargaSugeng();

        $this->cari('search=Sugeng')->assertOk()->assertJsonPath('data.data.0.masalah', []);
    }

    public function test_saringan_belum_masuk_keluarga(): void
    {
        $this->keluargaSugeng();
        $this->jamaah('Lepas Satu');
        $this->jamaah('Lepas Dua');

        $res = $this->cari('tanpa_keluarga=1')->assertOk();

        $this->assertSame(2, $res->json('data.total'));
        $this->assertSame(2, $res->json('data.belum_masuk_keluarga'));
        $this->assertSame(
            ['Lepas Dua', 'Lepas Satu'],
            collect($res->json('data.data'))->pluck('anggota.0.nama_lengkap')->all()
        );
    }

    public function test_kepala_keluarga_tidak_terhitung_belum_masuk_keluarga(): void
    {
        $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);

        $this->cari('tanpa_keluarga=1')->assertOk()->assertJsonPath('data.belum_masuk_keluarga', 0);
    }

    public function test_dipaginasi_per_keluarga_bukan_per_orang(): void
    {
        foreach (range(1, 3) as $i) {
            $kepala = $this->jamaah("Bapak {$i}", ['status_kk' => 'kepala_keluarga', 'kode_keluarga' => "KK-0{$i}"]);
            $this->jamaah("Anak {$i}", ['status_kk' => 'anak', 'kode_keluarga' => "KK-0{$i}", 'kepala_keluarga_id' => $kepala->id]);
        }

        $res = $this->cari('per_page=2&page=1')->assertOk();
        $this->assertSame(3, $res->json('data.total'));
        $this->assertSame(2, $res->json('data.last_page'));
        $this->assertCount(2, $res->json('data.data'));

        $this->assertCount(1, $this->cari('per_page=2&page=2')->json('data.data'));
    }

    public function test_saringan_kelompok_ikut_berlaku(): void
    {
        $this->keluargaSugeng();

        $lain = Kelompok::create(['desa_id' => $this->kelompok->desa_id, 'nama' => 'Mangurejo']);
        Jamaah::create([
            'kelompok_id' => $lain->id, 'nama_lengkap' => 'Kelompok Lain',
            'jenis_kelamin' => 'L', 'kategori_usia' => 'menikah',
        ]);

        $this->cari('kelompok_id='.$lain->id)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_jamaah_di_luar_wilayah_tidak_ikut(): void
    {
        $this->keluargaSugeng();

        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Mangurejo']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Sumberagung']);
        Jamaah::create([
            'kelompok_id' => $kelompokLain->id, 'nama_lengkap' => 'Luar Wilayah',
            'jenis_kelamin' => 'L', 'kategori_usia' => 'menikah', 'kode_keluarga' => 'KK-99',
        ]);

        $adminDesa = User::factory()->create(['desa_id' => $this->kelompok->desa_id]);
        $adminDesa->assignRole('admin');

        $res = $this->actingAs($adminDesa)->getJson('/api/jamaahs/keluarga')->assertOk();

        $this->assertSame(1, $res->json('data.total'));
        $this->assertNotContains(
            'Luar Wilayah',
            collect($res->json('data.data.0.anggota'))->pluck('nama_lengkap')->all()
        );
    }

    public function test_role_absensi_tidak_boleh_membuka_daftar_keluarga(): void
    {
        $petugas = User::factory()->create();
        $petugas->assignRole('absensi');

        $this->actingAs($petugas)->getJson('/api/jamaahs/keluarga')->assertForbidden();
    }
}
