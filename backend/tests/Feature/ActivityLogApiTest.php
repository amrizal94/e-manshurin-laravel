<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_membuat_daerah_tercatat_di_activity_log(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->postJson('/api/daerahs', ['nama' => 'Daerah Baru'])
            ->assertCreated();

        $response = $this->actingAs($superAdmin)->getJson('/api/activity-logs')->assertOk();

        $log = collect($response->json('data.data'))->firstWhere('subject_type', Daerah::class);
        $this->assertNotNull($log);
        $this->assertSame($superAdmin->id, $log['causer']['id']);
    }

    public function test_admin_tidak_bisa_akses_activity_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->getJson('/api/activity-logs')->assertForbidden();
    }

    public function test_login_gagal_tercatat_di_activity_log(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->postJson('/api/auth/login', ['email' => 'salah@e-manshurin.test', 'password' => 'salah'])
            ->assertUnauthorized();

        $response = $this->actingAs($superAdmin)->getJson('/api/activity-logs')->assertOk();
        $log = collect($response->json('data.data'))->firstWhere('description', 'Percobaan login gagal');
        $this->assertNotNull($log);
    }
}
