<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\JadwalRutin;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JadwalRutinTest extends TestCase
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

        // Selasa 1 September 2026, 08.00 WIB — hari yang tidak dipakai jadwal Senin/Rabu,
        // jadi hitungan tanggalnya tidak pernah bergantung pada "hari ini kebetulan cocok".
        Carbon::setTestNow(Carbon::parse('2026-09-01 01:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $ubah */
    private function buat(array $ubah = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/jadwal-rutins', [
            'nama' => 'Pengajian Kelompok',
            'jenis_pengajian' => 'umum',
            'kelompok_id' => $this->kelompok->id,
            'hari' => [1, 3],
            'jam_mulai' => '18:30',
            'jam_selesai' => '20:00',
            ...$ubah,
        ]);
    }

    private function jamaah(string $nama = 'Januar'): Jamaah
    {
        return Jamaah::create([
            'kelompok_id' => $this->kelompok->id, 'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L', 'kategori_usia' => 'usman',
        ]);
    }

    public function test_jadwal_menerbitkan_kegiatan_hanya_di_hari_yang_dipilih(): void
    {
        $this->buat()->assertCreated();

        $kegiatans = Kegiatan::orderBy('tanggal')->get();

        foreach ($kegiatans as $k) {
            $this->assertContains($k->tanggal->dayOfWeek, [1, 3], "Tanggal {$k->tanggal->toDateString()} bukan Senin/Rabu");
            $this->assertStringStartsWith('18:30', $k->jam_mulai);
            $this->assertSame($this->kelompok->id, $k->kelompok_id);
        }

        // 30 hari mulai Selasa 1 September berisi 4 Senin dan 5 Rabu.
        $this->assertSame(9, $kegiatans->count());
    }

    public function test_generate_ulang_tidak_menggandakan(): void
    {
        $this->buat()->assertCreated();
        $sebelum = Kegiatan::count();

        $this->artisan('kegiatan:generate')->assertSuccessful();
        $this->artisan('kegiatan:generate')->assertSuccessful();

        $this->assertSame($sebelum, Kegiatan::count());
    }

    public function test_tanda_libur_bertahan_setelah_generate_ulang(): void
    {
        $this->buat()->assertCreated();
        $kegiatan = Kegiatan::orderBy('tanggal')->first();

        $this->actingAs($this->admin)
            ->patchJson("/api/kegiatans/{$kegiatan->id}/libur", ['libur' => true, 'keterangan_libur' => 'Libur hari raya'])
            ->assertOk();

        $this->artisan('kegiatan:generate')->assertSuccessful();

        $kegiatan->refresh();
        $this->assertTrue($kegiatan->libur);
        $this->assertSame('Libur hari raya', $kegiatan->keterangan_libur);
        $this->assertSame(9, Kegiatan::count());
    }

    public function test_kegiatan_libur_tidak_bisa_diabsen(): void
    {
        $jamaah = $this->jamaah();
        $this->buat()->assertCreated();
        $kegiatan = Kegiatan::orderBy('tanggal')->first();

        $this->actingAs($this->admin)->patchJson("/api/kegiatans/{$kegiatan->id}/libur", ['libur' => true])->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/kegiatans/{$kegiatan->id}/absensi", ['jamaah_id' => $jamaah->id, 'status' => 'hadir'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'ditandai libur'));
    }

    public function test_kegiatan_libur_tidak_membuka_kiosk(): void
    {
        $kegiatan = Kegiatan::create([
            'nama' => 'Pengajian', 'jenis_pengajian' => 'umum', 'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-09-01', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00',
            'created_by' => $this->admin->id,
        ]);

        $this->assertCount(1, Kegiatan::sedangBerlangsung($this->admin));

        $kegiatan->update(['libur' => true]);
        $this->assertCount(0, Kegiatan::sedangBerlangsung($this->admin));
        $this->assertNull(Kegiatan::berikutnya($this->admin));
    }

    public function test_tanda_libur_bisa_dilepas(): void
    {
        $this->buat()->assertCreated();
        $kegiatan = Kegiatan::orderBy('tanggal')->first();

        $this->actingAs($this->admin)->patchJson("/api/kegiatans/{$kegiatan->id}/libur", ['libur' => true, 'keterangan_libur' => 'Salah'])->assertOk();
        $this->actingAs($this->admin)->patchJson("/api/kegiatans/{$kegiatan->id}/libur", ['libur' => false])->assertOk();

        $kegiatan->refresh();
        $this->assertFalse($kegiatan->libur);
        $this->assertNull($kegiatan->keterangan_libur);
    }

    public function test_tanggal_yang_bentrok_dilewati_sisanya_tetap_terbit(): void
    {
        $this->jamaah();

        // Senin 7 September, jam yang sama, peserta yang sama persis.
        Kegiatan::create([
            'nama' => 'Musyawarah', 'jenis_pengajian' => 'umum', 'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-09-07', 'jam_mulai' => '18:30', 'jam_selesai' => '20:00',
            'created_by' => $this->admin->id,
        ]);

        $this->buat()
            ->assertCreated()
            ->assertJsonPath('data.dibuat', 8)
            ->assertJsonPath('data.bentrok.0', fn ($p) => str_contains($p, '2026-09-07'))
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'dilewati karena bentrok'));

        $this->assertSame(0, Kegiatan::whereNotNull('jadwal_rutin_id')->whereDate('tanggal', '2026-09-07')->count());
    }

    public function test_jadwal_tidak_aktif_tidak_menerbitkan_apa_pun(): void
    {
        $this->buat(['aktif' => false])->assertCreated()->assertJsonPath('data.dibuat', 0);

        $this->assertSame(0, Kegiatan::count());
    }

    public function test_pondok_empat_kegiatan_tiap_hari(): void
    {
        $jam = [['05:00', '06:00'], ['08:00', '10:00'], ['13:00', '15:00'], ['19:00', '21:00']];

        foreach ($jam as $i => [$mulai, $selesai]) {
            $this->buat([
                'nama' => 'Pondok sesi '.($i + 1),
                'hari' => [0, 1, 2, 3, 4, 5, 6],
                'jam_mulai' => $mulai,
                'jam_selesai' => $selesai,
            ])->assertCreated()->assertJsonPath('data.dibuat', 30);
        }

        $this->assertSame(120, Kegiatan::count());
        $this->assertSame(4, Kegiatan::whereDate('tanggal', '2026-09-05')->count());
    }

    public function test_menghapus_jadwal_menyisakan_kegiatannya(): void
    {
        $id = $this->buat()->json('data.jadwal.id');
        $jumlah = Kegiatan::count();

        $this->actingAs($this->admin)->deleteJson("/api/jadwal-rutins/{$id}")
            ->assertOk()
            ->assertJsonPath('message', fn ($p) => str_contains($p, 'tetap ada'));

        $this->assertSame($jumlah, Kegiatan::count());
        $this->assertSame($jumlah, Kegiatan::whereNull('jadwal_rutin_id')->count());
    }

    public function test_mengubah_jadwal_tidak_menggeser_kegiatan_yang_sudah_terbit(): void
    {
        $id = $this->buat()->json('data.jadwal.id');

        $this->actingAs($this->admin)->putJson("/api/jadwal-rutins/{$id}", [
            'nama' => 'Pengajian Kelompok',
            'jenis_pengajian' => 'umum',
            'kelompok_id' => $this->kelompok->id,
            'hari' => [1, 3],
            'jam_mulai' => '19:00',
            'jam_selesai' => '20:30',
        ])->assertOk();

        $jam = Kegiatan::pluck('jam_mulai')->map(fn ($j) => substr((string) $j, 0, 5));
        $this->assertSame(9, $jam->filter(fn ($j) => $j === '18:30')->count());
        $this->assertSame(0, $jam->filter(fn ($j) => $j === '19:00')->count());
    }

    public function test_hari_kosong_ditolak(): void
    {
        $this->buat(['hari' => []])->assertStatus(422);
    }

    public function test_jam_selesai_harus_sesudah_jam_mulai(): void
    {
        $this->buat(['jam_mulai' => '20:00', 'jam_selesai' => '18:30'])->assertStatus(422);
    }

    public function test_target_di_luar_wilayah_ditolak(): void
    {
        $lain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Mangurejo']);
        $kelompokLain = Kelompok::create(['desa_id' => $lain->id, 'nama' => 'Sumberagung']);

        $adminDesa = User::factory()->create(['desa_id' => $this->kelompok->desa_id]);
        $adminDesa->assignRole('admin');

        $this->actingAs($adminDesa)->postJson('/api/jadwal-rutins', [
            'nama' => 'Pengajian', 'jenis_pengajian' => 'umum', 'kelompok_id' => $kelompokLain->id,
            'hari' => [1], 'jam_mulai' => '18:30', 'jam_selesai' => '20:00',
        ])->assertForbidden();

        $this->assertSame(0, JadwalRutin::count());
    }

    public function test_dua_target_sekaligus_ditolak(): void
    {
        $this->buat(['desa_id' => $this->kelompok->desa_id])->assertStatus(422);
    }

    public function test_role_absensi_tidak_boleh_mengatur_jadwal(): void
    {
        $petugas = User::factory()->create();
        $petugas->assignRole('absensi');

        $this->actingAs($petugas)->getJson('/api/jadwal-rutins')->assertForbidden();
    }

    public function test_admin_desa_tidak_melihat_jadwal_desa_lain(): void
    {
        $this->buat()->assertCreated();

        $lain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Mangurejo']);
        $adminLain = User::factory()->create(['desa_id' => $lain->id]);
        $adminLain->assignRole('admin');

        $this->actingAs($adminLain)->getJson('/api/jadwal-rutins')->assertOk()->assertJsonCount(0, 'data');
    }
}
