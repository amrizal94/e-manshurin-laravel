<?php

namespace Tests\Feature;

use App\Models\Setting;
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

    private function user(string $peran): User
    {
        $user = User::factory()->create();
        $user->assignRole($peran);

        return $user;
    }

    public function test_admin_bisa_baca_dan_ubah_template_wa(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->putJson('/api/settings/' . Setting::WA_REPLY_TEMPLATE, [
            'value' => 'Terima kasih, izin {nama} tercatat.',
        ])->assertOk();

        $this->actingAs($admin)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.' . Setting::WA_REPLY_TEMPLATE, 'Terima kasih, izin {nama} tercatat.');
    }

    public function test_pengaturan_yang_belum_pernah_disimpan_memakai_bawaannya(): void
    {
        $this->actingAs($this->user('admin'))->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.' . Setting::SUARA_ABSEN, Setting::BAWAAN[Setting::SUARA_ABSEN])
            ->assertJsonPath('data.' . Setting::SUARA_IDLE, Setting::BAWAAN[Setting::SUARA_IDLE]);
    }

    public function test_kiosk_boleh_membaca_teks_suara(): void
    {
        Setting::set(Setting::SUARA_ABSEN, '{sapaan} {nama}, matur nuwun');

        $this->actingAs($this->user('absensi'))->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.' . Setting::SUARA_ABSEN, '{sapaan} {nama}, matur nuwun');
    }

    public function test_absensi_role_tidak_bisa_mengubah_pengaturan(): void
    {
        $this->actingAs($this->user('absensi'))->putJson('/api/settings/' . Setting::WA_REPLY_TEMPLATE, [
            'value' => 'Susupan',
        ])->assertForbidden();
    }

    public function test_key_di_luar_daftar_ditolak(): void
    {
        $this->actingAs($this->user('admin'))->putJson('/api/settings/app_key', [
            'value' => 'apa saja',
        ])->assertNotFound();

        $this->assertDatabaseMissing('settings', ['key' => 'app_key']);
    }
}
