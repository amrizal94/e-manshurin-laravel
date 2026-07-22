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

    public function test_absensi_role_cannot_manage_master_data(): void
    {
        $absensi = User::factory()->create();
        $absensi->assignRole('absensi');

        $this->actingAs($absensi)->postJson('/api/daerahs', ['nama' => 'X'])->assertForbidden();
    }
}
