<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_admin_bisa_baca_dan_ubah_template_wa(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->putJson('/api/settings/wa-reply-template', [
            'template' => 'Terima kasih, izin {nama} tercatat.',
        ])->assertOk()->assertJsonPath('data.template', 'Terima kasih, izin {nama} tercatat.');

        $this->actingAs($admin)->getJson('/api/settings/wa-reply-template')
            ->assertOk()->assertJsonPath('data.template', 'Terima kasih, izin {nama} tercatat.');
    }

    public function test_absensi_role_tidak_bisa_ubah_template_wa(): void
    {
        $absensi = User::factory()->create();
        $absensi->assignRole('absensi');

        $this->actingAs($absensi)->putJson('/api/settings/wa-reply-template', [
            'template' => 'Susupan',
        ])->assertForbidden();
    }
}
