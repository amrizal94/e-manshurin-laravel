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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AbsensiApiTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;
    private Kelompok $kelompok;
    private Jamaah $remaja;
    private Jamaah $caberawit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $this->petugas = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $this->petugas->assignRole('absensi');

        $this->remaja = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Remaja Satu',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'remaja',
        ]);
        $this->caberawit = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Caberawit',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'caberawit',
        ]);
    }

    private function buatKegiatan(string $jenis = 'remaja', string $tanggal = '2026-07-21'): Kegiatan
    {
        return Kegiatan::create([
            'nama' => 'Pengajian ' . $jenis,
            'jenis_pengajian' => $jenis,
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => $tanggal,
            'created_by' => $this->petugas->id,
        ]);
    }

    /** @param  array<string, mixed>  $ganti */
    private function payloadKegiatan(array $ganti = []): array
    {
        return array_merge([
            'nama' => 'Pengajian Remaja Malam',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
            'jam_mulai' => '19:30',
            'jam_selesai' => '21:00',
        ], $ganti);
    }

    /**
     * Pengajian ibu-ibu/bapak-bapak menyaring dua hal sekaligus: kategori (menikah atau
     * pernah menikah) dan jenis kelamin — "menikah" sendiri berisi suami dan istri.
     */
    public function test_pengajian_ibu_dan_bapak_menyaring_kategori_sekaligus_jenis_kelamin(): void
    {
        $buat = fn (string $nama, string $kelamin, string $kategori) => Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => $nama,
            'jenis_kelamin' => $kelamin,
            'kategori_usia' => $kategori,
        ]);

        $buat('Istri', 'P', 'menikah');
        $buat('Janda', 'P', 'janda');
        $buat('Suami', 'L', 'menikah');
        $buat('Duda', 'L', 'duda');
        $buat('Gadis Usman', 'P', 'usman');

        $peserta = fn (Kegiatan $k) => $k->pesertaQuery()->orderBy('nama_lengkap')->pluck('nama_lengkap')->all();

        $this->assertSame(['Istri', 'Janda'], $peserta($this->buatKegiatan('ibu')));
        $this->assertSame(['Duda', 'Suami'], $peserta($this->buatKegiatan('bapak', '2026-07-22')));

        // Janda dan duda tidak boleh lenyap dari pengajian umum begitu statusnya diubah
        $this->assertSame(
            ['Duda', 'Gadis Usman', 'Istri', 'Janda', 'Remaja Satu', 'Suami'],
            $peserta($this->buatKegiatan('umum', '2026-07-23'))
        );
    }

    /**
     * Pengajian pengurus 4S disaring benderanya, bukan usianya: yang dicentang ikut walau
     * kategori usianya apa pun, yang tidak dicentang tetap di luar walau seumuran.
     */
    public function test_pengajian_pengurus_4s_menyaring_bendera_bukan_usia(): void
    {
        $buat = fn (string $nama, string $kategori, bool $pengurus) => Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'kategori_usia' => $kategori,
            'pengurus_4s' => $pengurus,
        ]);

        $buat('Pengurus Menikah', 'menikah', true);
        $buat('Pengurus Remaja', 'remaja', true);
        $buat('Bukan Pengurus', 'menikah', false);

        $kegiatan = $this->buatKegiatan('pengurus_4s');

        $this->assertSame(
            ['Pengurus Menikah', 'Pengurus Remaja'],
            $kegiatan->pesertaQuery()->orderBy('nama_lengkap')->pluck('nama_lengkap')->all()
        );
    }

    /**
     * Klien yang mengirim target baru tanpa menyertakan kolom target lama tidak boleh
     * menyisakan dua target sekaligus — kegiatan harus tetap punya tepat satu.
     */
    public function test_ganti_target_mengosongkan_target_lama(): void
    {
        $adminDesa = User::factory()->create(['desa_id' => $this->kelompok->desa_id]);
        $adminDesa->assignRole('admin');

        $kegiatan = Kegiatan::create($this->payloadKegiatan([
            'kelompok_id' => null,
            'desa_id' => $this->kelompok->desa_id,
            'created_by' => $adminDesa->id,
        ]));

        // payloadKegiatan() hanya berisi kelompok_id — desa_id memang tidak dikirim sama sekali
        $this->actingAs($adminDesa)
            ->putJson("/api/kegiatans/{$kegiatan->id}", $this->payloadKegiatan())
            ->assertOk();

        $kegiatan->refresh();
        $this->assertNull($kegiatan->desa_id);
        $this->assertSame($this->kelompok->id, $kegiatan->kelompok_id);
    }

    public function test_akun_absensi_membuat_kegiatan_di_kelompoknya(): void
    {
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan())
            ->assertCreated();
    }

    public function test_jam_wajib_diisi_dan_harus_urut(): void
    {
        // jendela absen kiosk dihitung dari rentang ini; tanpa jam, kamera menyala sepanjang hari
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', ['nama' => 'Tanpa Jam', 'jenis_pengajian' => 'remaja',
                'kelompok_id' => $this->kelompok->id, 'tanggal' => '2026-07-21'])
            ->assertJsonValidationErrors(['jam_mulai', 'jam_selesai']);

        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan(['jam_selesai' => '10:30']))
            ->assertJsonValidationErrors('jam_selesai');
    }

    public function test_kegiatan_bentrok_dengan_peserta_beririsan_ditolak(): void
    {
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan())
            ->assertCreated();

        // jenisnya beda nama, tapi "umum" mencakup remaja — peserta beririsan
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Pengajian Umum',
                'jenis_pengajian' => 'umum',
                'jam_mulai' => '20:00',
                'jam_selesai' => '22:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'Bentrok dengan "Pengajian Remaja Malam"'));

        // jam tidak bertumpuk lagi (termasuk toleransi 30 menit di kedua sisi)
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Pengajian Umum',
                'jenis_pengajian' => 'umum',
                'jam_mulai' => '22:30',
                'jam_selesai' => '23:30',
            ]))
            ->assertCreated();
    }

    /**
     * Maghrib dan Isya di pondok: 17.30-18.00 lalu 19.00-19.30. Dengan toleransi 30 menit
     * di kedua sisi, jendelanya berakhir dan mulai sama-sama di 18.30 — bersentuhan, bukan
     * bertumpuk. Dulu keduanya dianggap bentrok, dan jadwal Isya tidak pernah menerbitkan
     * satu kegiatan pun: tertulis 0 sementara jadwal lain 30, tanpa ada yang tahu kenapa.
     */
    public function test_jendela_yang_cuma_bersentuhan_bukan_bentrok(): void
    {
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Sholat Maghrib',
                'jenis_pengajian' => 'umum',
                'jam_mulai' => '17:30',
                'jam_selesai' => '18:00',
            ]))
            ->assertCreated();

        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Sholat Isya',
                'jenis_pengajian' => 'umum',
                'jam_mulai' => '19:00',
                'jam_selesai' => '19:30',
            ]))
            ->assertCreated();

        // Semenit lebih dekat dan jendelanya benar-benar bertumpuk — itu tetap ditolak.
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Sholat Isya Kedua',
                'jenis_pengajian' => 'umum',
                'jam_mulai' => '18:59',
                'jam_selesai' => '19:30',
            ]))
            ->assertStatus(422);
    }

    public function test_kegiatan_beda_kategori_usia_boleh_berbarengan(): void
    {
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan())
            ->assertCreated();

        // caberawit dan remaja di jam sama: ruang berbeda, tidak ada jamaah di keduanya
        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Pengajian Caberawit',
                'jenis_pengajian' => 'caberawit',
            ]))
            ->assertCreated();
    }

    public function test_edit_kegiatan_tidak_bentrok_dengan_dirinya_sendiri(): void
    {
        $kegiatan = $this->buatKegiatan('remaja');
        $kegiatan->update(['jam_mulai' => '19:30', 'jam_selesai' => '21:00']);

        $this->actingAs($this->petugas)
            ->putJson("/api/kegiatans/{$kegiatan->id}", $this->payloadKegiatan([
                'tanggal' => $kegiatan->tanggal->toDateString(),
                'nama' => 'Nama Baru',
            ]))
            ->assertOk();
    }

    public function test_kegiatan_di_luar_scope_ditolak(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $this->actingAs($this->petugas)
            ->postJson('/api/kegiatans', $this->payloadKegiatan([
                'nama' => 'Pengajian Ilegal',
                'kelompok_id' => $kelompokLain->id,
            ]))
            ->assertForbidden();
    }

    public function test_peserta_terfilter_kategori_usia(): void
    {
        $kegiatan = $this->buatKegiatan('remaja');

        $response = $this->actingAs($this->petugas)->getJson("/api/kegiatans/{$kegiatan->id}/peserta")->assertOk();
        $names = array_column($response->json('data'), 'nama_lengkap');
        $this->assertSame(['Remaja Satu'], $names);
    }

    public function test_usman_menikah_keluar_dari_peserta_usman_tapi_masuk_umum(): void
    {
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Usman Lajang',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Usman Menikah',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'menikah',
        ]);

        $kegiatanUsman = $this->buatKegiatan('usman');
        $namesUsman = array_column(
            $this->actingAs($this->petugas)->getJson("/api/kegiatans/{$kegiatanUsman->id}/peserta")->json('data'),
            'nama_lengkap'
        );
        $this->assertSame(['Usman Lajang'], $namesUsman);

        $kegiatanUmum = $this->buatKegiatan('umum');
        $namesUmum = array_column(
            $this->actingAs($this->petugas)->getJson("/api/kegiatans/{$kegiatanUmum->id}/peserta")->json('data'),
            'nama_lengkap'
        );
        $this->assertContains('Usman Lajang', $namesUmum);
        $this->assertContains('Usman Menikah', $namesUmum);
    }

    public function test_absensi_jamaah_di_luar_kategori_ditolak(): void
    {
        $kegiatan = $this->buatKegiatan('remaja');

        $this->actingAs($this->petugas)->postJson("/api/kegiatans/{$kegiatan->id}/absensi", [
            'jamaah_id' => $this->caberawit->id,
            'status' => 'hadir',
        ])->assertStatus(422);
    }

    public function test_absensi_upsert_tidak_duplikat(): void
    {
        $kegiatan = $this->buatKegiatan('remaja');

        $payload = ['jamaah_id' => $this->remaja->id, 'status' => 'hadir'];
        $this->actingAs($this->petugas)->postJson("/api/kegiatans/{$kegiatan->id}/absensi", $payload)->assertOk();
        $this->actingAs($this->petugas)->postJson("/api/kegiatans/{$kegiatan->id}/absensi", ['jamaah_id' => $this->remaja->id, 'status' => 'izin', 'keterangan' => 'kerja'])->assertOk();

        $this->assertSame(1, Absensi::count());
        $this->assertSame('izin', Absensi::first()->status);
    }

    /**
     * Pengajian desa pesertanya sedesa, dan kegiatan itu terlihat juga oleh akun kelompok.
     * Rekapnya tetap laporan kelompok sendiri: nama kelompok tetangga tidak boleh ikut
     * terbaca, walau kolom pengajian desanya tetap ada.
     */
    public function test_rekap_akun_kelompok_tidak_memuat_jamaah_kelompok_lain(): void
    {
        $tetangga = Kelompok::create([
            'desa_id' => $this->kelompok->desa_id,
            'nama' => 'Kelompok 2',
        ]);
        Jamaah::create([
            'kelompok_id' => $tetangga->id,
            'nama_lengkap' => 'Remaja Tetangga',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'remaja',
        ]);

        $kegiatanDesa = Kegiatan::create([
            'nama' => 'Pengajian Remaja Sedesa',
            'jenis_pengajian' => 'remaja',
            'desa_id' => $this->kelompok->desa_id,
            'tanggal' => '2026-07-21',
            'created_by' => $this->petugas->id,
        ]);

        $data = $this->actingAs($this->petugas)
            ->getJson('/api/rekap?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk()->json('data');

        $this->assertSame(['Remaja Satu'], array_column(array_column($data['rows'], 'jamaah'), 'nama_lengkap'));
        // Kegiatannya sendiri tetap muncul: yang disaring barisnya, bukan kolomnya.
        $this->assertContains($kegiatanDesa->id, array_column($data['kegiatans'], 'id'));
    }

    /**
     * Urutan baris dan jumlah H/I/A dihitung di server supaya layar, berkas Excel, dan
     * cetakan membaca angka yang sama. Kalau salah satunya menghitung sendiri, yang
     * ketahuan belakangan justru cetakan yang sudah terlanjur dibagikan.
     */
    public function test_rekap_urut_dewasa_dulu_dan_membawa_jumlah(): void
    {
        // Namanya sengaja mendahului secara abjad: kalau urutannya masih ikut nama,
        // "Ahmad" yang remaja akan berdiri di atas yang menikah, dan test ini gagal.
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Zainal Menikah',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'menikah',
        ]);

        $kegiatan = $this->buatKegiatan('umum');
        Absensi::create([
            'kegiatan_id' => $kegiatan->id,
            'jamaah_id' => $this->remaja->id,
            'status' => 'hadir',
            'metode' => 'manual',
        ]);

        $data = $this->actingAs($this->petugas)
            ->getJson('/api/rekap?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk()->json('data');

        // Yang menikah berdiri lebih dulu walau namanya berawalan Z.
        $this->assertSame(
            ['menikah', 'remaja'],
            array_column(array_column($data['rows'], 'jamaah'), 'kategori_usia')
        );
        $this->assertSame(['hadir' => 0, 'izin' => 0, 'alpha' => 1], $data['rows'][0]['jumlah']);
        $this->assertSame(['hadir' => 1, 'izin' => 0, 'alpha' => 0], $data['rows'][1]['jumlah']);
    }

    /**
     * Satu sheet per jenis pengajian, dan judul kolomnya tinggal tanggal — jenisnya sudah
     * jadi nama sheet, menuliskannya lagi di tiap kolom cuma memakan lebar.
     */
    public function test_ekspor_rekap_satu_sheet_per_jenis(): void
    {
        $umum = $this->buatKegiatan('umum');
        $this->buatKegiatan('caberawit', '2026-07-22');
        Absensi::create([
            'kegiatan_id' => $umum->id,
            'jamaah_id' => $this->remaja->id,
            'status' => 'izin',
            'metode' => 'manual',
        ]);

        $isi = $this->actingAs($this->petugas)
            ->get('/api/rekap/ekspor?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk()
            ->assertDownload('rekap-2026-07-01-sd-2026-07-31.xlsx')
            ->streamedContent();

        $teks = $this->bacaXlsx($isi);

        $this->assertStringContainsString('Remaja Satu', $teks);
        $this->assertStringContainsString('Anak Caberawit', $teks);
        // Nama sheet memikul jenisnya; kolomnya cuma tanggal.
        $this->assertStringContainsString('name="Umum"', $teks);
        $this->assertStringContainsString('name="Caberawit"', $teks);
        $this->assertStringContainsString('21/07', $teks);
        $this->assertStringNotContainsString('21/07 Umum', $teks);
        $this->assertStringNotContainsString('Pengajian umum', $teks);
    }

    /** Ambil seluruh teks dari xlsx tanpa membongkar strukturnya. */
    private function bacaXlsx(string $isi): string
    {
        $berkas = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($berkas, $isi);

        $zip = new \ZipArchive;
        $zip->open($berkas);
        $teks = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $teks .= $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($berkas);

        return $teks;
    }

    public function test_rekap_alpha_tiga_kali_berturut_flag(): void
    {
        foreach (['2026-07-01', '2026-07-08', '2026-07-15'] as $tanggal) {
            $this->buatKegiatan('remaja', $tanggal);
        }

        $response = $this->actingAs($this->petugas)
            ->getJson('/api/rekap?dari=2026-07-01&sampai=2026-07-31&jenis_pengajian=remaja')
            ->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('jamaah.nama_lengkap', 'Remaja Satu');
        $this->assertTrue($row['perlu_perhatian']);
        $this->assertSame(['alpha', 'alpha', 'alpha'], array_values($row['statuses']));
    }

    public function test_rekap_hadir_memutus_streak(): void
    {
        $kegiatans = collect(['2026-07-01', '2026-07-08', '2026-07-15'])->map(fn ($t) => $this->buatKegiatan('remaja', $t));

        $this->actingAs($this->petugas)->postJson("/api/kegiatans/{$kegiatans[1]->id}/absensi", [
            'jamaah_id' => $this->remaja->id,
            'status' => 'hadir',
        ])->assertOk();

        $response = $this->actingAs($this->petugas)
            ->getJson('/api/rekap?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk();

        $row = collect($response->json('data.rows'))->firstWhere('jamaah.nama_lengkap', 'Remaja Satu');
        $this->assertFalse($row['perlu_perhatian']);
    }
}
