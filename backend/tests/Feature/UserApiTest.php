<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
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

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_super_admin_bisa_buat_pengguna_scoped_ke_kelompok(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/users', [
            'name' => 'Petugas Absensi',
            'email' => 'petugas@e-manshurin.test',
            'password' => 'password123',
            'role' => 'absensi',
            'kelompok_id' => $this->kelompok->id,
        ])->assertCreated();

        $this->assertSame('absensi', $response->json('data.roles.0.name'));

        $user = User::where('email', 'petugas@e-manshurin.test')->first();
        $this->assertTrue($user->hasRole('absensi'));
        $this->assertSame($this->kelompok->id, $user->kelompok_id);
    }

    public function test_admin_hanya_bisa_buat_pengguna_di_dalam_wilayahnya(): void
    {
        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $this->actingAs($adminKelompok)->postJson('/api/users', [
            'name' => 'Petugas Luar',
            'email' => 'luar@e-manshurin.test',
            'password' => 'password123',
            'role' => 'absensi',
            'kelompok_id' => $kelompokLain->id,
        ])->assertForbidden();
    }

    public function test_admin_tidak_bisa_menetapkan_peran_super_admin(): void
    {
        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->postJson('/api/users', [
            'name' => 'Coba Jadi Super',
            'email' => 'coba@e-manshurin.test',
            'password' => 'password123',
            'role' => 'super_admin',
            'kelompok_id' => $this->kelompok->id,
        ])->assertForbidden();
    }

    public function test_scoping_hides_user_outside_wilayah(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->kelompok->desa->daerah_id, 'nama' => 'Desa B']);
        $kelompokLain = Kelompok::create(['desa_id' => $desaLain->id, 'nama' => 'Kelompok X']);

        $adminKelompokLain = User::factory()->create(['kelompok_id' => $kelompokLain->id]);
        $adminKelompokLain->assignRole('admin');

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $response = $this->actingAs($adminKelompok)->getJson('/api/users')->assertOk();
        // Daftarnya berhalaman sejak 120-an akun: barisnya ada di data.data.
        $emails = collect($response->json('data.data'))->pluck('email');
        $this->assertTrue($emails->contains($adminKelompok->email));
        $this->assertFalse($emails->contains($adminKelompokLain->email));
    }

    /**
     * Tiap wilayah punya akun Absensi dan Admin, jadi daftarnya tumbuh lurus dengan jumlah
     * kelompok. Yang dicari petugas selalu satu akun, bukan seluruh daftarnya.
     */
    public function test_daftar_pengguna_bisa_disaring_dan_dicari(): void
    {
        $absensi = User::factory()->create(['name' => 'Absensi Kelompok Beji', 'kelompok_id' => $this->kelompok->id]);
        $absensi->assignRole('absensi');

        $admin = User::factory()->create(['name' => 'Admin Kelompok Beji', 'kelompok_id' => $this->kelompok->id]);
        $admin->assignRole('admin');

        $nama = fn (string $kueri) => collect(
            $this->actingAs($this->superAdmin)->getJson("/api/users?{$kueri}")->assertOk()->json('data.data')
        )->pluck('name');

        $this->assertTrue($nama('peran=absensi')->contains('Absensi Kelompok Beji'));
        $this->assertFalse($nama('peran=absensi')->contains('Admin Kelompok Beji'));

        // Dicari lewat nama wilayahnya, bukan nama akun: itu yang diingat petugas.
        $lewatWilayah = $nama('search='.urlencode($this->kelompok->nama));
        $this->assertTrue($lewatWilayah->contains('Absensi Kelompok Beji'));
        $this->assertTrue($lewatWilayah->contains('Admin Kelompok Beji'));
    }

    public function test_update_password_opsional(): void
    {
        $user = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $user->assignRole('absensi');
        $hashSebelum = $user->password;

        $this->actingAs($this->superAdmin)->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'absensi',
            'kelompok_id' => $this->kelompok->id,
        ])->assertOk();

        $this->assertSame($hashSebelum, $user->fresh()->password);
    }

    public function test_tidak_bisa_hapus_akun_sendiri(): void
    {
        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/users/{$this->superAdmin->id}")
            ->assertStatus(422);
    }
}
