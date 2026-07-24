<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StrukturApiTest extends TestCase
{
    use RefreshDatabase;

    private Daerah $daerah;
    private Desa $desa;
    private Kelompok $kelompok;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        $this->daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $this->desa = Desa::create(['daerah_id' => $this->daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $this->desa->id, 'nama' => 'Kelompok 1']);
    }

    public function test_admin_desa_tidak_bisa_lihat_desa_lain(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->daerah->id, 'nama' => 'Desa Lain']);

        $adminDesa = User::factory()->create(['desa_id' => $this->desa->id]);
        $adminDesa->assignRole('admin');

        $response = $this->actingAs($adminDesa)->getJson('/api/desas')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->desa->id));
        $this->assertFalse($ids->contains($desaLain->id));
    }

    public function test_admin_desa_tidak_bisa_bikin_kelompok_di_desa_lain(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->daerah->id, 'nama' => 'Desa Lain']);

        $adminDesa = User::factory()->create(['desa_id' => $this->desa->id]);
        $adminDesa->assignRole('admin');

        $this->actingAs($adminDesa)->postJson('/api/kelompoks', [
            'desa_id' => $desaLain->id,
            'nama' => 'Kelompok Susupan',
        ])->assertForbidden();
    }

    public function test_admin_kelompok_tidak_bisa_edit_kelompok_lain(): void
    {
        $kelompokLain = Kelompok::create(['desa_id' => $this->desa->id, 'nama' => 'Kelompok Lain']);

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->putJson("/api/kelompoks/{$kelompokLain->id}", [
            'desa_id' => $this->desa->id,
            'nama' => 'Diganti',
        ])->assertForbidden();
    }

    public function test_admin_kelompok_tidak_bisa_pindahkan_kelompok_ke_desa_lain(): void
    {
        $desaLain = Desa::create(['daerah_id' => $this->daerah->id, 'nama' => 'Desa Lain']);

        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->putJson("/api/kelompoks/{$this->kelompok->id}", [
            'desa_id' => $desaLain->id,
            'nama' => 'Kelompok 1',
        ])->assertForbidden();
    }

    public function test_admin_kelompok_tidak_bisa_edit_desa_ancestornya(): void
    {
        $adminKelompok = User::factory()->create(['kelompok_id' => $this->kelompok->id]);
        $adminKelompok->assignRole('admin');

        $this->actingAs($adminKelompok)->putJson("/api/desas/{$this->desa->id}", [
            'daerah_id' => $this->daerah->id,
            'nama' => 'Diganti',
        ])->assertForbidden();
    }

    public function test_admin_desa_tidak_bisa_edit_daerah(): void
    {
        $adminDesa = User::factory()->create(['desa_id' => $this->desa->id]);
        $adminDesa->assignRole('admin');

        $this->actingAs($adminDesa)->putJson("/api/daerahs/{$this->daerah->id}", [
            'nama' => 'Diganti',
        ])->assertForbidden();
    }

    public function test_super_admin_bisa_kelola_semua_struktur(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->postJson('/api/kelompoks', [
            'desa_id' => $this->desa->id,
            'nama' => 'Kelompok Baru',
        ])->assertCreated();
    }
}
