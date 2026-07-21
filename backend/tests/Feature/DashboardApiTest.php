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

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_scoped_ke_desa(): void
    {
        Role::findOrCreate('admin');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desaA = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $desaB = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa B']);
        $kelompokA1 = Kelompok::create(['desa_id' => $desaA->id, 'nama' => 'A1']);
        $kelompokA2 = Kelompok::create(['desa_id' => $desaA->id, 'nama' => 'A2']);
        $kelompokB1 = Kelompok::create(['desa_id' => $desaB->id, 'nama' => 'B1']);

        foreach ([$kelompokA1, $kelompokA2, $kelompokB1] as $i => $kelompok) {
            Jamaah::create([
                'kelompok_id' => $kelompok->id,
                'nama_lengkap' => "Jamaah {$i}",
                'jenis_kelamin' => 'L',
                'kategori_usia' => 'remaja',
            ]);
        }

        $adminDesaA = User::factory()->create(['desa_id' => $desaA->id]);
        $adminDesaA->assignRole('admin');

        $response = $this->actingAs($adminDesaA)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(2, $response->json('data.total_jamaah'));
        $this->assertSame(2, $response->json('data.jumlah_kelompok'));
        $this->assertNull($response->json('data.jumlah_desa'));
        $this->assertSame(2, $response->json('data.per_kategori_usia.remaja'));
    }
}
