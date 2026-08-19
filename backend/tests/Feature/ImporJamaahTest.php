<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImporJamaahTest extends TestCase
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

    private function periksa(string $isi, string $nama = 'data.csv'): TestResponse
    {
        return $this->actingAs($this->admin)->post('/api/jamaahs/impor/periksa', [
            'file' => UploadedFile::fake()->createWithContent($nama, $isi),
        ], ['Accept' => 'application/json']);
    }

    private function csv(string ...$baris): string
    {
        return implode("\n", ['desa,kelompok,nama_lengkap,jenis_kelamin,kategori_usia,tanggal_lahir,no_hp', ...$baris]);
    }

    public function test_template_berisi_desa_dan_kelompok_nyata(): void
    {
        $isi = $this->actingAs($this->admin)->get('/api/jamaahs/impor/template')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="template-impor-jamaah.csv"')
            ->getContent();

        $this->assertStringStartsWith("\u{FEFF}sep=,\r\n", $isi);
        $this->assertStringContainsString('desa,kelompok,nama_lengkap,jenis_kelamin,kategori_usia', $isi);
        $this->assertStringContainsString('Wonokasian,Klanderan', $isi);
    }

    public function test_template_bisa_langsung_diperiksa_balik(): void
    {
        $template = $this->actingAs($this->admin)->get('/api/jamaahs/impor/template')->getContent();

        $this->periksa($template)->assertOk()->assertJsonPath('data.ringkasan.error', 0);
    }

    public function test_baris_benar_dinyatakan_siap(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar Agung,L,usman,2000-01-15,081234567890'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.total', 1)
            ->assertJsonPath('data.ringkasan.siap', 1);

        $this->assertSame(0, Jamaah::count(), 'periksa tidak boleh menulis apa pun');
    }

    public function test_kolom_wajib_hilang_ditolak_dengan_pesan_jelas(): void
    {
        $this->periksa("nama,jenis_kelamin\nJanuar,L")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'desa') && str_contains($p, 'kelompok'));
    }

    public function test_kelompok_di_luar_wilayah_jadi_error(): void
    {
        $lain = Desa::create(['daerah_id' => Daerah::create(['nama' => 'Daerah Lain'])->id, 'nama' => 'Desa Lain']);
        Kelompok::create(['desa_id' => $lain->id, 'nama' => 'Kelompok Lain']);

        $this->admin->update(['desa_id' => $this->kelompok->desa_id]);

        $this->periksa($this->csv('Desa Lain,Kelompok Lain,Januar,L,usman,,'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 1)
            ->assertJsonPath('data.baris.0.pesan.0', fn ($p) => str_contains($p, 'tidak ada di wilayah Anda'));
    }

    /** Nama kelompok dobel antar desa di data nyata (LAMBATAN, CEPATAN, ...), jadi desa ikut menentukan. */
    public function test_kelompok_senama_dibedakan_oleh_desanya(): void
    {
        $desaB = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Mangurejo']);
        $kelompokB = Kelompok::create(['desa_id' => $desaB->id, 'nama' => 'Klanderan']);

        $this->periksa($this->csv('Mangurejo,Klanderan,Januar,L,usman,,'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.siap', 1)
            ->assertJsonPath('data.baris.0.kelompok', 'Mangurejo / Klanderan');

        $this->assertNotSame($this->kelompok->id, $kelompokB->id);
    }

    public function test_pemisah_titik_koma_dan_bom_terbaca(): void
    {
        $isi = "\u{FEFF}sep=;\r\n".str_replace(',', ';', $this->csv('Wonokasian;Klanderan;Januar;L;usman;;'));

        $this->periksa($isi)->assertOk()->assertJsonPath('data.ringkasan.siap', 1);
    }

    public function test_tanggal_dibaca_hari_dulu_dan_dilaporkan(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar,L,usman,30/11/1990,'))
            ->assertOk()
            ->assertJsonPath('data.baris.0.tanggal_lahir', '1990-11-30')
            ->assertJsonPath('data.catatan.0', fn ($c) => str_contains($c, '30 November 1990'));
    }

    /** 11/30/1990 (bulan dulu) ditolak, bukan ditukar diam-diam. */
    public function test_tanggal_bulan_dulu_jadi_error(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar,L,usman,11/30/1990,'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 1);
    }

    public function test_nol_depan_nomor_hp_yang_dimakan_excel_dikembalikan(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar,L,usman,,81234567890'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.siap', 1);
    }

    public function test_janda_dengan_jenis_kelamin_laki_jadi_error(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar,L,janda,,'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 1);
    }

    public function test_nama_yang_sudah_ada_jadi_perhatian_bukan_error(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $this->periksa($this->csv('Wonokasian,Klanderan,Januar Agung,L,usman,,'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 0)
            ->assertJsonPath('data.ringkasan.perhatian', 1);
    }

    public function test_nama_kembar_di_dalam_file_yang_sama_diperingatkan(): void
    {
        $this->periksa($this->csv(
            'Wonokasian,Klanderan,Januar Agung,L,usman,,',
            'Wonokasian,Klanderan,Januar Agung,L,usman,,'
        ))->assertOk()->assertJsonPath('data.ringkasan.perhatian', 1);
    }

    public function test_file_kelewat_besar_ditolak_dan_diminta_dipecah(): void
    {
        $baris = array_fill(0, 2001, 'Wonokasian,Klanderan,Januar,L,usman,,');

        $this->periksa($this->csv(...$baris))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'Pecah per desa'));
    }

    public function test_role_absensi_tidak_boleh_mengimpor(): void
    {
        $petugas = User::factory()->create();
        $petugas->assignRole('absensi');

        $this->actingAs($petugas)->getJson('/api/jamaahs/impor/template')->assertForbidden();
    }
}
