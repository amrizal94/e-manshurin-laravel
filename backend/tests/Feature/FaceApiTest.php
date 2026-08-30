<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Kelompok $kelompok;
    private Jamaah $jamaah;

    /** Vektor dummy ternormalisasi: 1.0 di satu posisi, sisanya 0. */
    private function vektor(int $posisi): array
    {
        $v = array_fill(0, 512, 0.0);
        $v[$posisi] = 1.0;

        return $v;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Waktu dibekukan di siang hari WIB supaya jendela jam relatif tidak pernah melewati tengah malam.
        Carbon::setTestNow(Carbon::parse('2026-07-21 05:00:00', 'UTC'));

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->jamaah = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Remaja Satu',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'remaja',
        ]);
    }

    public function test_enroll_menyimpan_foto_dan_descriptor_terenkripsi(): void
    {
        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(0), 'confidence' => 0.99])]);

        $this->actingAs($this->admin)
            ->post("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('wajah.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_foto', 1);

        $descriptor = JamaahFaceDescriptor::first();
        $this->assertSame($this->jamaah->id, $descriptor->jamaah_id);
        $this->assertEquals(1.0, $descriptor->descriptor[0]); // cast decrypt balik ke array
        $this->assertStringStartsWith('eyJpdiI6', $descriptor->getRawOriginal('descriptor')); // terenkripsi at-rest
    }

    /**
     * Foto kedua yang wajahnya orang lain ditolak: kalau lolos, kiosk akan mencatat
     * hadir atas nama jamaah ini setiap kali si orang lain lewat.
     */
    public function test_enroll_menolak_wajah_yang_beda_dengan_foto_tersimpan(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);

        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(1), 'confidence' => 0.99])]);

        $this->actingAs($this->admin)
            ->postJson("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('orang-lain.jpg'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, $this->jamaah->photos()->count());
        $this->assertSame(1, JamaahFaceDescriptor::count());
    }

    /** Wajah yang sama tetap masuk walau posenya beda — skornya di atas ambang. */
    public function test_enroll_menerima_foto_kedua_dari_orang_yang_sama(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);

        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(0), 'confidence' => 0.97])]);

        $this->actingAs($this->admin)
            ->post("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('pose-lain.jpg'),
            ])
            ->assertCreated();

        $this->assertSame(2, JamaahFaceDescriptor::count());
    }

    public function test_identify_mencatat_hadir_metode_face(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);
        $kegiatan = Kegiatan::create([
            'nama' => 'Pengajian Remaja',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
            'created_by' => $this->admin->id,
        ]);

        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(0), 'confidence' => 0.98])]);

        $this->actingAs($this->admin)
            ->post("/api/kegiatans/{$kegiatan->id}/absensi-wajah", [
                'photo' => UploadedFile::fake()->image('scan.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Remaja Satu')
            ->assertJsonPath('data.score', fn ($score) => abs($score - 1.0) < 0.001);

        $absensi = Absensi::first();
        $this->assertSame('hadir', $absensi->status);
        $this->assertSame('face', $absensi->metode);
    }

    public function test_identify_wajah_asing_ditolak(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);
        $kegiatan = Kegiatan::create([
            'nama' => 'Pengajian Remaja',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
            'created_by' => $this->admin->id,
        ]);

        // wajah orthogonal -> similarity 0 < threshold
        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(1), 'confidence' => 0.98])]);

        $this->actingAs($this->admin)
            ->post("/api/kegiatans/{$kegiatan->id}/absensi-wajah", [
                'photo' => UploadedFile::fake()->image('asing.jpg'),
            ])
            ->assertNotFound();

        $this->assertSame(0, Absensi::count());
    }

    /** Kegiatan hari ini menurut zona lokal, dengan jendela jam relatif terhadap sekarang. */
    private function kegiatanHariIni(string $jenis, ?int $mulaiMenitDariSekarang = null, ?int $selesaiMenitDariSekarang = null): Kegiatan
    {
        $sekarang = Kegiatan::sekarangLokal();

        return Kegiatan::create([
            'nama' => "Pengajian {$jenis} " . fake()->unique()->numberBetween(1, 9999),
            'jenis_pengajian' => $jenis,
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => $sekarang->toDateString(),
            'jam_mulai' => $mulaiMenitDariSekarang === null ? null
                : $sekarang->copy()->addMinutes($mulaiMenitDariSekarang)->format('H:i'),
            'jam_selesai' => $selesaiMenitDariSekarang === null ? null
                : $sekarang->copy()->addMinutes($selesaiMenitDariSekarang)->format('H:i'),
            'created_by' => $this->admin->id,
        ]);
    }

    private function scan(int $posisi = 0): \Illuminate\Testing\TestResponse
    {
        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor($posisi), 'confidence' => 0.98])]);

        return $this->actingAs($this->admin)
            ->post('/api/absensi-wajah', ['photo' => UploadedFile::fake()->image('scan.jpg')]);
    }

    public function test_standby_mencatat_hadir_di_kegiatan_yang_sedang_berlangsung(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);
        $kegiatan = $this->kegiatanHariIni('remaja', -10, 60);

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Remaja Satu')
            ->assertJsonPath('data.kegiatan.id', $kegiatan->id);

        $this->assertSame($kegiatan->id, Absensi::first()->kegiatan_id);
    }

    public function test_standby_menyapa_tanpa_mencatat_kalau_belum_ada_kegiatan(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);
        $this->kegiatanHariIni('remaja', 180, 300); // masih 3 jam lagi, di luar toleransi

        $this->scan()
            ->assertOk()
            ->assertJsonPath('message', 'Belum ada pengajian saat ini')
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Remaja Satu')
            ->assertJsonPath('data.kegiatan', null)
            ->assertJsonPath('data.absensi', null);

        $this->assertSame(0, Absensi::count());
    }

    public function test_standby_diam_saat_wajah_asing_dan_belum_ada_kegiatan(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);

        $this->scan(7)->assertNotFound(); // vektor orthogonal — tidak dikenali
        $this->assertSame(0, Absensi::count());
    }

    public function test_standby_menyapa_jamaah_di_luar_kategori_kegiatan_hari_itu(): void
    {
        // orang ini bukan peserta kegiatan mana pun hari ini, tapi tetap harus dikenali saat idle
        $anak = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Satu',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'caberawit',
        ]);
        JamaahFaceDescriptor::create(['jamaah_id' => $anak->id, 'descriptor' => $this->vektor(5)]);

        $this->scan(5)
            ->assertOk()
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Anak Satu')
            ->assertJsonPath('data.kegiatan', null);
    }

    public function test_standby_menerima_absen_dalam_toleransi_sebelum_mulai(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);
        $this->kegiatanHariIni('remaja', Kegiatan::TOLERANSI_MENIT - 5, 120);

        $this->scan()->assertOk();
    }

    public function test_standby_pilih_kegiatan_sesuai_kategori_usia_saat_dua_kegiatan_bersamaan(): void
    {
        $anak = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Anak Satu',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'caberawit',
        ]);
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);
        JamaahFaceDescriptor::create(['jamaah_id' => $anak->id, 'descriptor' => $this->vektor(5)]);

        $this->kegiatanHariIni('remaja', -10, 60);
        $kegiatanAnak = $this->kegiatanHariIni('caberawit', -10, 60);

        // wajah anak -> masuk kegiatan caberawit, bukan kegiatan remaja yang jamnya sama
        $this->scan(5)
            ->assertOk()
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Anak Satu')
            ->assertJsonPath('data.kegiatan.id', $kegiatanAnak->id);
    }

    public function test_standby_utamakan_kegiatan_kelompok_daripada_kegiatan_daerah(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);

        // pengajian daerah mulai lebih awal, tapi kiosk ini milik kelompok
        $daerah = $this->kegiatanHariIni('remaja', -60, 60);
        $daerah->update(['kelompok_id' => null, 'daerah_id' => $this->kelompok->desa->daerah_id]);
        $kelompok = $this->kegiatanHariIni('remaja', -10, 60);

        $this->scan()
            ->assertOk()
            ->assertJsonPath('data.kegiatan.id', $kelompok->id);
    }

    public function test_kegiatan_aktif_sebutkan_wilayah_kiosk(): void
    {
        $petugas = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $petugas->assignRole('absensi');

        $this->actingAs($petugas)
            ->getJson('/api/kegiatan-aktif')
            ->assertOk()
            ->assertJsonPath('data.wilayah', 'Kelompok Kelompok 1');

        $this->actingAs($this->admin)
            ->getJson('/api/kegiatan-aktif')
            ->assertJsonPath('data.wilayah', 'Seluruh wilayah');
    }

    public function test_kegiatan_tanpa_jam_dianggap_berlaku_sepanjang_hari(): void
    {
        JamaahFaceDescriptor::create(['jamaah_id' => $this->jamaah->id, 'descriptor' => $this->vektor(0)]);
        $this->kegiatanHariIni('remaja');

        $this->scan()->assertOk();
    }

    public function test_endpoint_kegiatan_aktif_beri_jadwal_berikutnya_saat_idle(): void
    {
        $nanti = $this->kegiatanHariIni('remaja', 180, 300);

        $this->actingAs($this->admin)
            ->getJson('/api/kegiatan-aktif')
            ->assertOk()
            ->assertJsonCount(0, 'data.aktif')
            ->assertJsonPath('data.berikutnya.id', $nanti->id);
    }

    public function test_endpoint_kegiatan_aktif_daftarkan_kegiatan_berjalan(): void
    {
        $kegiatan = $this->kegiatanHariIni('remaja', -10, 60);

        $this->actingAs($this->admin)
            ->getJson('/api/kegiatan-aktif')
            ->assertOk()
            ->assertJsonCount(1, 'data.aktif')
            ->assertJsonPath('data.aktif.0.id', $kegiatan->id)
            ->assertJsonPath('data.berikutnya', null);
    }

    public function test_face_service_mati_kembalikan_503(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->admin)
            ->post("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('wajah.jpg'),
            ])
            ->assertStatus(503);
    }
}
