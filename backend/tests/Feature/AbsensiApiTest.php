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

    public function test_akun_absensi_membuat_kegiatan_di_kelompoknya(): void
    {
        $this->actingAs($this->petugas)->postJson('/api/kegiatans', [
            'nama' => 'Pengajian Remaja Malam',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
        ])->assertCreated();
    }

    public function test_kegiatan_di_luar_scope_ditolak(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $this->actingAs($this->petugas)->postJson('/api/kegiatans', [
            'nama' => 'Pengajian Ilegal',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $kelompokLain->id,
            'tanggal' => '2026-07-21',
        ])->assertForbidden();
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
