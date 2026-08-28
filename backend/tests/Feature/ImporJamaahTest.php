<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
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

    private function simpan(string $isi, array $tambahan = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/api/jamaahs/impor', [
            'file' => UploadedFile::fake()->createWithContent('data.csv', $isi),
            ...$tambahan,
        ], ['Accept' => 'application/json']);
    }

    public function test_impor_menyimpan_semua_kolom(): void
    {
        $isi = "desa,kelompok,nama_lengkap,nama_panggilan,jenis_kelamin,tempat_lahir,tanggal_lahir,alamat,no_hp,pekerjaan,kategori_usia,status_kk,status_mubaligh,aktif\n"
            .'Wonokasian,Klanderan,Januar Agung,Januar,L,Kediri,30/11/1990,Jl. Contoh 1,81234567890,Wiraswasta,menikah,kepala_keluarga,ya,ya';

        $this->simpan($isi)->assertCreated()->assertJsonPath('data.disimpan', 1);

        $jamaah = Jamaah::sole();
        $this->assertSame('Januar Agung', $jamaah->nama_lengkap);
        $this->assertSame('Januar', $jamaah->nama_panggilan);
        $this->assertSame('Kediri', $jamaah->tempat_lahir);
        $this->assertSame('1990-11-30', $jamaah->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('081234567890', $jamaah->no_hp);
        $this->assertSame('kepala_keluarga', $jamaah->status_kk);
        $this->assertTrue($jamaah->status_mubaligh);
        $this->assertSame($this->kelompok->id, $jamaah->kelompok_id);
        $this->assertNotNull($jamaah->impor_id);
    }

    /** Impor separuh jalan adalah keadaan yang paling sulit dibereskan — tidak ada yang boleh masuk. */
    public function test_satu_baris_error_membatalkan_seluruh_impor(): void
    {
        $this->simpan($this->csv(
            'Wonokasian,Klanderan,Januar,L,usman,,',
            'Wonokasian,Kelompok Hantu,Budi,L,usman,,'
        ))->assertStatus(422)->assertJsonPath('message', fn ($p) => str_contains($p, '1 baris yang error'));

        $this->assertSame(0, Jamaah::count());
    }

    public function test_nama_yang_sudah_ada_dilewati_secara_bawaan(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $this->simpan($this->csv(
            'Wonokasian,Klanderan,Januar Agung,L,usman,,',
            'Wonokasian,Klanderan,Budi Santoso,L,usman,,'
        ))->assertCreated()->assertJsonPath('data.disimpan', 1)->assertJsonPath('data.dilewati', 1);

        $this->assertSame(1, Jamaah::where('nama_lengkap', 'Januar Agung')->count());
    }

    public function test_kembar_ikut_disimpan_kalau_diminta(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $this->simpan($this->csv('Wonokasian,Klanderan,Januar Agung,L,usman,,'), ['lewati_kembar' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.disimpan', 1);

        $this->assertSame(2, Jamaah::where('nama_lengkap', 'Januar Agung')->count());
    }

    /** Nomor HP aneh cuma peringatan, bukan kembar — jangan ikut terbuang oleh "lewati yang sudah ada". */
    public function test_peringatan_selain_kembar_tetap_disimpan(): void
    {
        $this->simpan($this->csv('Wonokasian,Klanderan,Januar Agung,L,usman,,123'))
            ->assertCreated()
            ->assertJsonPath('data.disimpan', 1);
    }

    public function test_batal_impor_menghapus_persis_baris_impor_itu(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Data Lama',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        $imporId = $this->simpan($this->csv(
            'Wonokasian,Klanderan,Januar Agung,L,usman,,',
            'Wonokasian,Klanderan,Budi Santoso,L,usman,,'
        ))->assertCreated()->json('data.impor_id');

        $this->actingAs($this->admin)->deleteJson("/api/jamaahs/impor/{$imporId}")
            ->assertOk()
            ->assertJsonPath('data.dihapus', 2);

        $this->assertSame(['Data Lama'], Jamaah::pluck('nama_lengkap')->all());
    }

    public function test_batal_impor_yang_sudah_dibatalkan_jadi_404(): void
    {
        $imporId = $this->simpan($this->csv('Wonokasian,Klanderan,Januar,L,usman,,'))->json('data.impor_id');

        $this->actingAs($this->admin)->deleteJson("/api/jamaahs/impor/{$imporId}")->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/jamaahs/impor/{$imporId}")->assertNotFound();
    }

    /** Absensi tidak ada di file CSV mana pun — menghapusnya berarti membuang data yang tak bisa dikembalikan. */
    public function test_batal_impor_ditolak_kalau_sudah_ada_absensi(): void
    {
        $imporId = $this->simpan($this->csv('Wonokasian,Klanderan,Januar,L,usman,,'))->json('data.impor_id');

        $kegiatan = Kegiatan::create([
            'kelompok_id' => $this->kelompok->id,
            'nama' => 'Pengajian Malam',
            'jenis_pengajian' => 'umum',
            'tanggal' => '2026-08-19',
            'jam_mulai' => '19:00',
            'jam_selesai' => '21:00',
            'created_by' => $this->admin->id,
        ]);
        Absensi::create([
            'kegiatan_id' => $kegiatan->id,
            'jamaah_id' => Jamaah::sole()->id,
            'status' => 'hadir',
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/jamaahs/impor/{$imporId}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'sudah punya absensi'));

        $this->assertSame(1, Jamaah::count());
    }

    /**
     * @param  list<list<mixed>>  $baris
     * @param  array<int, Style>  $gayaKolom
     */
    private function xlsx(array $baris, array $gayaKolom = []): UploadedFile
    {
        $berkas = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($berkas);
        foreach ($baris as $i => $isi) {
            $writer->addRow(Row::fromValuesWithStyles($isi, null, $i === 0 ? [] : $gayaKolom));
        }
        $writer->close();

        return new UploadedFile($berkas, 'data.xlsx', null, null, true);
    }

    private function unggahXlsx(UploadedFile $berkas, string $ke = '/api/jamaahs/impor/periksa'): TestResponse
    {
        return $this->actingAs($this->admin)->post($ke, ['file' => $berkas], ['Accept' => 'application/json']);
    }

    public function test_template_xlsx_bisa_langsung_diperiksa_balik(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'unduh').'.xlsx';
        file_put_contents($berkas, $this->actingAs($this->admin)
            ->get('/api/jamaahs/impor/template-xlsx')->assertOk()->streamedContent());

        $this->actingAs($this->admin)->post('/api/jamaahs/impor/periksa', [
            'file' => new UploadedFile($berkas, 'template-impor-jamaah.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 0)
            ->assertJsonPath('data.ringkasan.total', 1);
    }

    /**
     * Inti dari menerima .xlsx: sel tanggal sampai sebagai tanggal betulan, jadi tidak ada
     * lagi tebak-tebakan hari-dulu/bulan-dulu maupun catatan peringatannya.
     */
    public function test_tanggal_xlsx_terbaca_tanpa_peringatan_urutan(): void
    {
        $berkas = $this->xlsx([
            ['desa', 'kelompok', 'nama_lengkap', 'jenis_kelamin', 'kategori_usia', 'tanggal_lahir', 'no_hp'],
            ['Wonokasian', 'Klanderan', 'Januar Agung', 'L', 'usman', new \DateTimeImmutable('1990-11-30'), 81234567890],
        ], [5 => (new Style)->setFormat('yyyy-mm-dd')]);

        $this->unggahXlsx($berkas)
            ->assertOk()
            ->assertJsonPath('data.ringkasan.siap', 1)
            ->assertJsonPath('data.baris.0.tanggal_lahir', '1990-11-30')
            ->assertJsonPath('data.catatan', []);
    }

    /** Berkas yang diunggah orang tidak boleh menjatuhkan permintaan jadi 500. */
    public function test_kolom_berformat_tanggal_berisi_bukan_tanggal_ditolak_dengan_pesan(): void
    {
        $berkas = $this->xlsx([
            ['desa', 'kelompok', 'nama_lengkap', 'jenis_kelamin', 'kategori_usia', 'no_hp'],
            ['Wonokasian', 'Klanderan', 'Januar Agung', 'L', 'usman', 81234567890],
        ], [5 => (new Style)->setFormat('yyyy-mm-dd')]);

        $this->unggahXlsx($berkas)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'format Tanggal'));
    }

    /**
     * Sel tanggal yang kehilangan format angkanya sampai sebagai nomor seri Excel. Itu tetap
     * satu tanggal yang pasti, jadi dibaca — bukan ditolak dengan pesan yang membingungkan
     * orang yang di layarnya jelas-jelas melihat "30/11/1990".
     */
    public function test_nomor_seri_tanggal_excel_terbaca(): void
    {
        $berkas = $this->xlsx([
            ['desa', 'kelompok', 'nama_lengkap', 'jenis_kelamin', 'kategori_usia', 'tanggal_lahir'],
            ['Wonokasian', 'Klanderan', 'Januar Agung', 'L', 'usman', new \DateTimeImmutable('1990-11-30')],
        ]);

        $this->unggahXlsx($berkas)
            ->assertOk()
            ->assertJsonPath('data.baris.0.tanggal_lahir', '1990-11-30');
    }

    /** Nomor HP yang tersimpan sebagai angka jangan sampai jadi notasi ilmiah. */
    public function test_impor_xlsx_menyimpan_nomor_hp_utuh(): void
    {
        $berkas = $this->xlsx([
            ['desa', 'kelompok', 'nama_lengkap', 'jenis_kelamin', 'kategori_usia', 'no_hp'],
            ['Wonokasian', 'Klanderan', 'Januar Agung', 'L', 'usman', 81234567890],
        ]);

        $this->unggahXlsx($berkas, '/api/jamaahs/impor')
            ->assertCreated()
            ->assertJsonPath('data.disimpan', 1);

        $this->assertSame('081234567890', Jamaah::sole()->no_hp);
    }

    public function test_xls_lama_ditolak_dengan_jalan_keluarnya(): void
    {
        $this->periksa('apa saja', 'data.xls')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($p) => str_contains($p, 'Save As → Excel Workbook (.xlsx)'));
    }

    public function test_admin_desa_tidak_bisa_membatalkan_impor_desa_lain(): void
    {
        $imporId = $this->simpan($this->csv('Wonokasian,Klanderan,Januar,L,usman,,'))->json('data.impor_id');

        $lain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Mangurejo']);
        $adminLain = User::factory()->create(['desa_id' => $lain->id]);
        $adminLain->assignRole('admin');

        $this->actingAs($adminLain)->deleteJson("/api/jamaahs/impor/{$imporId}")->assertNotFound();
        $this->assertSame(1, Jamaah::count());
    }

    private function csvKeluarga(string ...$baris): string
    {
        return implode('
', ['desa,kelompok,nama_lengkap,jenis_kelamin,kategori_usia,status_kk,kode_keluarga', ...$baris]);
    }

    public function test_kode_keluarga_menautkan_anggota_ke_kepala_keluarga(): void
    {
        $this->simpan($this->csvKeluarga(
            'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KK-01',
            'Wonokasian,Klanderan,Siti,P,menikah,istri,KK-01',
            'Wonokasian,Klanderan,Rian,L,remaja,anak,KK-01',
        ))->assertCreated()->assertJsonPath('data.disimpan', 3);

        $sugeng = Jamaah::where('nama_lengkap', 'Sugeng')->sole();
        $this->assertNull($sugeng->kepala_keluarga_id);
        $this->assertSame(2, $sugeng->anggotaKeluarga()->count());
        $this->assertSame('KK-01', Jamaah::where('nama_lengkap', 'Rian')->sole()->kode_keluarga);
    }

    public function test_kode_keluarga_disamakan_jadi_huruf_besar(): void
    {
        $this->simpan($this->csvKeluarga(
            'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KK-01',
            'Wonokasian,Klanderan,Siti,P,menikah,istri,kk-01',
        ))->assertCreated();

        $this->assertSame(
            Jamaah::where('nama_lengkap', 'Sugeng')->sole()->id,
            Jamaah::where('nama_lengkap', 'Siti')->sole()->kepala_keluarga_id,
        );
    }

    public function test_dua_kepala_keluarga_dalam_satu_kode_ditolak(): void
    {
        $isi = $this->csvKeluarga(
            'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KK-01',
            'Wonokasian,Klanderan,Bambang,L,menikah,kepala_keluarga,KK-01',
        );

        $this->periksa($isi)
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 2)
            ->assertJsonPath('data.baris.0.pesan.0', fn ($p) => str_contains($p, 'lebih dari satu kepala_keluarga'));

        $this->simpan($isi)->assertStatus(422);
        $this->assertSame(0, Jamaah::count());
    }

    public function test_kode_keluarga_tanpa_kepala_keluarga_cuma_diperingatkan(): void
    {
        $this->periksa($this->csvKeluarga('Wonokasian,Klanderan,Siti,P,menikah,istri,KK-09'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 0)
            ->assertJsonPath('data.ringkasan.perhatian', 1);

        $this->simpan($this->csvKeluarga('Wonokasian,Klanderan,Siti,P,menikah,istri,KK-09'))->assertCreated();

        $siti = Jamaah::sole();
        $this->assertSame('KK-09', $siti->kode_keluarga);
        $this->assertNull($siti->kepala_keluarga_id);
    }

    public function test_kepala_keluarga_yang_sudah_tersimpan_dipakai_impor_berikutnya(): void
    {
        $sugeng = Jamaah::create([
            'kelompok_id' => $this->kelompok->id, 'nama_lengkap' => 'Sugeng', 'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah', 'status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KK-01',
        ]);

        $this->periksa($this->csvKeluarga('Wonokasian,Klanderan,Rian,L,remaja,anak,KK-01'))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.perhatian', 0);

        $this->simpan($this->csvKeluarga('Wonokasian,Klanderan,Rian,L,remaja,anak,KK-01'))->assertCreated();

        $this->assertSame($sugeng->id, Jamaah::where('nama_lengkap', 'Rian')->sole()->kepala_keluarga_id);
    }

    public function test_kode_keluarga_kepanjangan_ditolak(): void
    {
        $this->periksa($this->csvKeluarga('Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,'.str_repeat('X', 51)))
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 1)
            ->assertJsonPath('data.baris.0.pesan.0', fn ($p) => str_contains($p, 'terlalu panjang'));
    }

    public function test_judul_no_kk_dibaca_sebagai_kode_keluarga(): void
    {
        $isi = 'desa,kelompok,nama_lengkap,jenis_kelamin,kategori_usia,kk,no_kk
'
            .'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KK-77';

        $this->simpan($isi)->assertCreated();
        $this->assertSame('KK-77', Jamaah::sole()->kode_keluarga);
    }

    public function test_batal_impor_ikut_menghapus_tautan_keluarganya(): void
    {
        $imporId = $this->simpan($this->csvKeluarga(
            'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KK-01',
            'Wonokasian,Klanderan,Siti,P,menikah,istri,KK-01',
        ))->json('data.impor_id');

        $this->actingAs($this->admin)->deleteJson("/api/jamaahs/impor/{$imporId}")->assertOk();
        $this->assertSame(0, Jamaah::count());
    }

    public function test_ringkasan_keluarga_dihitung_sebelum_impor(): void
    {
        $res = $this->periksa($this->csvKeluarga(
            'Wonokasian,Klanderan,Sugeng,L,menikah,kepala_keluarga,KLANDERAN-001',
            'Wonokasian,Klanderan,Siti,P,menikah,istri,KLANDERAN-001',
            'Wonokasian,Klanderan,Rian,L,remaja,anak,KLANDERAN-001',
            'Wonokasian,Klanderan,Bambang,L,menikah,kepala_keluarga,KLANDERAN-002',
            'Wonokasian,Klanderan,Yatim,L,remaja,anak,KLANDERAN-003',
            'Wonokasian,Klanderan,Lepas,L,usman,,',
        ))->assertOk();

        $res->assertJsonPath('data.keluarga.total', 3)
            ->assertJsonPath('data.keluarga.tanpa_kode', 1)
            ->assertJsonPath('data.keluarga.tanpa_kepala', 1)
            ->assertJsonPath('data.keluarga.terbesar', 3);

        $this->assertEqualsWithDelta(1.7, $res->json('data.keluarga.rata_rata'), 0.05);
    }

    public function test_ringkasan_keluarga_kosong_kalau_kolomnya_tidak_diisi(): void
    {
        $this->periksa($this->csv('Wonokasian,Klanderan,Januar Agung,L,usman,,'))
            ->assertOk()
            ->assertJsonPath('data.keluarga.total', 0)
            ->assertJsonPath('data.keluarga.tanpa_kode', 1)
            ->assertJsonPath('data.keluarga.tanpa_kepala', 0);
    }

    public function test_kode_yang_sudah_dipakai_keluarga_lain_ditolak(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id, 'nama_lengkap' => 'Sugeng Lama', 'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah', 'status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KLANDERAN-001',
        ]);

        $isi = $this->csvKeluarga('Wonokasian,Klanderan,Bambang,L,menikah,kepala_keluarga,KLANDERAN-001');

        $this->periksa($isi)
            ->assertOk()
            ->assertJsonPath('data.ringkasan.error', 1)
            ->assertJsonPath('data.baris.0.pesan.0', fn ($p) => str_contains($p, 'sudah dipakai keluarga lain'));

        $this->simpan($isi)->assertStatus(422);
        $this->assertSame(1, Jamaah::count());
    }

    public function test_menambah_anggota_ke_keluarga_yang_sudah_ada_tetap_boleh(): void
    {
        $sugeng = Jamaah::create([
            'kelompok_id' => $this->kelompok->id, 'nama_lengkap' => 'Sugeng', 'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah', 'status_kk' => 'kepala_keluarga', 'kode_keluarga' => 'KLANDERAN-001',
        ]);

        // Tanpa baris kepala keluarga — inilah yang membedakannya dari tabrakan kode.
        $this->simpan($this->csvKeluarga('Wonokasian,Klanderan,Rian,L,remaja,anak,KLANDERAN-001'))
            ->assertCreated();

        $this->assertSame($sugeng->id, Jamaah::where('nama_lengkap', 'Rian')->sole()->kepala_keluarga_id);
    }

    public function test_contoh_templat_memakai_awalan_nama_kelompok(): void
    {
        $isi = $this->actingAs($this->admin)->get('/api/jamaahs/impor/template')->assertOk()->getContent();

        $this->assertStringContainsString('KLANDERAN-001', $isi);
    }
}
