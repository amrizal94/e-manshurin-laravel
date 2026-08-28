<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JamaahApiTest extends TestCase
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
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_login_returns_token(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token']]);
    }

    public function test_admin_can_create_and_list_jamaah(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/jamaahs', [
                'kelompok_id' => $this->kelompok->id,
                'nama_lengkap' => 'Januar Agung',
                'jenis_kelamin' => 'L',
                'tanggal_lahir' => '2000-01-15',
                'kategori_usia' => 'usman',
            ])->assertCreated();

        $response = $this->actingAs($this->admin)->getJson('/api/jamaahs')->assertOk();
        $this->assertSame('Januar Agung', $response->json('data.data.0.nama_lengkap'));
        $this->assertIsInt($response->json('data.data.0.usia'));
    }

    /** "Janda"/"duda" sudah menyebut jenis kelaminnya — kalau boleh beda, penyaring pengajian ibu/bapak ikut salah. */
    public function test_kategori_janda_duda_harus_cocok_dengan_jenis_kelamin(): void
    {
        $kirim = fn (array $ganti) => $this->actingAs($this->admin)->postJson('/api/jamaahs', $ganti + [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Uji Kategori',
        ]);

        $kirim(['jenis_kelamin' => 'L', 'kategori_usia' => 'janda'])->assertStatus(422);
        $kirim(['jenis_kelamin' => 'P', 'kategori_usia' => 'duda'])->assertStatus(422);
        $kirim(['jenis_kelamin' => 'P', 'kategori_usia' => 'janda'])->assertCreated();
    }

    public function test_kepala_keluarga_tidak_boleh_jadi_anggota_keluarga_lain(): void
    {
        $lain = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Kepala Keluarga Lain',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $this->actingAs($this->admin)->postJson('/api/jamaahs', [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Konflik',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
            'status_kk' => 'kepala_keluarga',
            'kepala_keluarga_id' => $lain->id,
        ])->assertStatus(422);
    }

    public function test_anggota_keluarga_bisa_pilih_kepala_keluarga(): void
    {
        $kepala = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Kepala Keluarga',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
            'status_kk' => 'kepala_keluarga',
        ]);

        $this->actingAs($this->admin)->postJson('/api/jamaahs', [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Cucu Satu',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'paud_tk',
            'status_kk' => 'cucu',
            'kepala_keluarga_id' => $kepala->id,
        ])->assertCreated()->assertJsonPath('data.kepala_keluarga_id', $kepala->id);
    }

    /** Dipakai form jamaah untuk mengisi pilihan kepala keluarga, lepas dari halaman tabel. */
    public function test_daftar_jamaah_bisa_disaring_per_status_kk(): void
    {
        foreach ([['Kepala Satu', 'kepala_keluarga'], ['Kepala Dua', 'kepala_keluarga'], ['Anak Satu', 'anak']] as [$nama, $status]) {
            Jamaah::create([
                'kelompok_id' => $this->kelompok->id,
                'nama_lengkap' => $nama,
                'jenis_kelamin' => 'L',
                'kategori_usia' => 'usman',
                'status_kk' => $status,
            ]);
        }

        $data = $this->actingAs($this->admin)
            ->getJson('/api/jamaahs?status_kk=kepala_keluarga')
            ->assertOk()
            ->json('data.data');

        $this->assertSame(['Kepala Dua', 'Kepala Satu'], array_column($data, 'nama_lengkap'));
    }

    public function test_kepala_keluarga_id_harus_menunjuk_orang_berstatus_kepala_keluarga(): void
    {
        $bukanKepala = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Satu',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'praremaja',
            'status_kk' => 'anak',
        ]);

        $this->actingAs($this->admin)->postJson('/api/jamaahs', [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Dua',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'praremaja',
            'status_kk' => 'anak',
            'kepala_keluarga_id' => $bukanKepala->id,
        ])->assertStatus(422);
    }

    public function test_kepala_keluarga_yang_masih_punya_anggota_tidak_bisa_diturunkan(): void
    {
        $kepala = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Winarko',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            'status_kk' => 'kepala_keluarga',
        ]);
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Winarko',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'caberawit',
            'status_kk' => 'anak',
            'kepala_keluarga_id' => $kepala->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/jamaahs/{$kepala->id}", [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Winarko',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            'status_kk' => 'anak',
        ])->assertStatus(422);
    }

    public function test_kepala_keluarga_di_luar_wilayah_ditolak(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);
        $kepalaLuar = Jamaah::create([
            'kelompok_id' => $kelompokLain->id,
            'nama_lengkap' => 'Kepala Wilayah Lain',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            'status_kk' => 'kepala_keluarga',
        ]);

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->postJson('/api/jamaahs', [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Nyasar',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'caberawit',
            'status_kk' => 'anak',
            'kepala_keluarga_id' => $kepalaLuar->id,
        ])->assertStatus(422);
    }

    public function test_scoping_hides_jamaah_outside_user_structure(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Orang Kelompok 1',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $adminKelompokLain = User::factory()->create(['kelompok_id' => $kelompokLain->id]);
        $adminKelompokLain->assignRole('admin');

        $response = $this->actingAs($adminKelompokLain)->getJson('/api/jamaahs')->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_admin_kelompok_tidak_bisa_bikin_jamaah_di_kelompok_lain(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->postJson('/api/jamaahs', [
            'kelompok_id' => $kelompokLain->id,
            'nama_lengkap' => 'Susupan',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ])->assertForbidden();
    }

    public function test_admin_kelompok_tidak_bisa_pindahkan_jamaah_ke_kelompok_lain(): void
    {
        $jamaah = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Orang Kelompok 1',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->putJson("/api/jamaahs/{$jamaah->id}", [
            'kelompok_id' => $kelompokLain->id,
            'nama_lengkap' => 'Orang Kelompok 1',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ])->assertForbidden();
    }

    public function test_absensi_role_cannot_manage_master_data(): void
    {
        $absensi = User::factory()->create();
        $absensi->assignRole('absensi');

        $this->actingAs($absensi)->postJson('/api/daerahs', ['nama' => 'X'])->assertForbidden();
    }

    /** @param array<string, mixed> $tambahan */
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

    public function test_filter_keluarga_memunculkan_serumah_lewat_kepala_keluarga_id(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $this->jamaah('Siti', ['status_kk' => 'istri', 'kepala_keluarga_id' => $sugeng->id]);
        $this->jamaah('Orang Lain');

        // Ditanya dari sisi anggota, bukan kepalanya — dari mana pun ditanya, seisi rumah yang keluar.
        $nama = collect($this->actingAs($this->admin)
            ->getJson('/api/jamaahs?keluarga_id='.Jamaah::where('nama_lengkap', 'Siti')->sole()->id)
            ->assertOk()->json('data.data'))->pluck('nama_lengkap')->sort()->values()->all();

        $this->assertSame(['Siti', 'Sugeng'], $nama);
    }

    public function test_filter_keluarga_memunculkan_serumah_lewat_kode_keluarga(): void
    {
        $this->jamaah('Sugeng', ['kode_keluarga' => 'KK-01']);
        $this->jamaah('Siti', ['kode_keluarga' => 'KK-01']);
        $this->jamaah('Orang Lain', ['kode_keluarga' => 'KK-02']);

        $this->actingAs($this->admin)
            ->getJson('/api/jamaahs?keluarga_id='.Jamaah::where('nama_lengkap', 'Sugeng')->sole()->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_filter_keluarga_untuk_jamaah_di_luar_wilayah_ditolak(): void
    {
        $lain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $lain->id, 'nama' => 'Kelompok 2']);
        $luar = Jamaah::create([
            'kelompok_id' => $kelompokLain->id, 'nama_lengkap' => 'Luar',
            'jenis_kelamin' => 'L', 'kategori_usia' => 'menikah',
        ]);

        $adminDesa = User::factory()->create(['desa_id' => $this->kelompok->desa_id]);
        $adminDesa->assignRole('admin');

        $this->actingAs($adminDesa)->getJson('/api/jamaahs?keluarga_id='.$luar->id)->assertNotFound();
    }

    public function test_pencarian_ikut_mencocokkan_kode_keluarga(): void
    {
        $this->jamaah('Sugeng', ['kode_keluarga' => 'KK-SUGENG-01']);
        $this->jamaah('Siti', ['kode_keluarga' => 'KK-SUGENG-01']);
        $this->jamaah('Orang Lain', ['kode_keluarga' => 'KK-LAIN-01']);

        $this->actingAs($this->admin)->getJson('/api/jamaahs?search=kk-sugeng')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_kode_keluarga_disimpan_huruf_besar_lewat_form(): void
    {
        $this->actingAs($this->admin)->postJson('/api/jamaahs', [
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Sugeng',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
            'kode_keluarga' => '  kk-01 ',
        ])->assertCreated();

        $this->assertSame('KK-01', Jamaah::sole()->kode_keluarga);
    }

    public function test_sambung_keluarga_borongan(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KLANDERAN-001']);
        $siti = $this->jamaah('Siti');
        $rian = $this->jamaah('Rian');

        $this->actingAs($this->admin)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$siti->id, $rian->id],
            'kepala_keluarga_id' => $sugeng->id,
        ])->assertOk()->assertJsonPath('data.disambungkan', 2);

        foreach ([$siti, $rian] as $anggota) {
            $anggota->refresh();
            $this->assertSame($sugeng->id, $anggota->kepala_keluarga_id);
            // Kode ikut kepala keluarga: pengelompokan lewat kode dan lewat rujukan harus sama isi.
            $this->assertSame('KLANDERAN-001', $anggota->kode_keluarga);
        }
    }

    public function test_sambung_keluarga_bisa_menyamakan_status_kk(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $rian = $this->jamaah('Rian');

        $this->actingAs($this->admin)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$rian->id],
            'kepala_keluarga_id' => $sugeng->id,
            'status_kk' => 'anak',
        ])->assertOk();

        $this->assertSame('anak', $rian->refresh()->status_kk);
    }

    public function test_sambung_keluarga_tidak_boleh_menyamakan_jadi_kepala_keluarga(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $rian = $this->jamaah('Rian');

        $this->actingAs($this->admin)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$rian->id],
            'kepala_keluarga_id' => $sugeng->id,
            'status_kk' => 'kepala_keluarga',
        ])->assertStatus(422);
    }

    public function test_sambung_keluarga_menolak_target_yang_bukan_kepala_keluarga(): void
    {
        $siti = $this->jamaah('Siti', ['status_kk' => 'istri']);
        $rian = $this->jamaah('Rian');

        $this->actingAs($this->admin)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$rian->id],
            'kepala_keluarga_id' => $siti->id,
        ])->assertStatus(422);

        $this->assertNull($rian->refresh()->kepala_keluarga_id);
    }

    public function test_sambung_keluarga_melewati_jamaah_di_luar_wilayah(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);

        $lain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $lain->id, 'nama' => 'Kelompok 2']);
        $luar = Jamaah::create([
            'kelompok_id' => $kelompokLain->id, 'nama_lengkap' => 'Luar',
            'jenis_kelamin' => 'L', 'kategori_usia' => 'menikah',
        ]);

        $adminDesa = User::factory()->create(['desa_id' => $this->kelompok->desa_id]);
        $adminDesa->assignRole('admin');

        $this->actingAs($adminDesa)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$luar->id],
            'kepala_keluarga_id' => $sugeng->id,
        ])->assertStatus(422);

        $this->assertNull($luar->refresh()->kepala_keluarga_id);
    }

    public function test_sambung_keluarga_tidak_menyambungkan_kepala_ke_dirinya_sendiri(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);

        $this->actingAs($this->admin)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$sugeng->id],
            'kepala_keluarga_id' => $sugeng->id,
        ])->assertStatus(422);

        $this->assertNull($sugeng->refresh()->kepala_keluarga_id);
    }

    public function test_role_absensi_tidak_boleh_menyambungkan_keluarga(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $rian = $this->jamaah('Rian');

        $petugas = User::factory()->create();
        $petugas->assignRole('absensi');

        $this->actingAs($petugas)->postJson('/api/jamaahs/sambung-keluarga', [
            'jamaah_ids' => [$rian->id],
            'kepala_keluarga_id' => $sugeng->id,
        ])->assertForbidden();
    }

    public function test_daftar_jamaah_membawa_nama_kepala_keluarganya(): void
    {
        $sugeng = $this->jamaah('Sugeng', ['status_kk' => 'kepala_keluarga']);
        $this->jamaah('Rian', ['status_kk' => 'anak', 'kepala_keluarga_id' => $sugeng->id]);

        $rows = collect($this->actingAs($this->admin)->getJson('/api/jamaahs')->assertOk()->json('data.data'));

        $this->assertSame('Sugeng', $rows->firstWhere('nama_lengkap', 'Rian')['kepala_keluarga']['nama_lengkap']);
    }
}
