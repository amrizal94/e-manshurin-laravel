<?php

namespace Tests\Feature;

use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BuatAkunStrukturTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        Storage::fake('local');

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Kresek Selatan']);
        Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kresek 1']);
    }

    public function test_membuat_akun_admin_dan_absensi_per_struktur(): void
    {
        $this->artisan('akun:buat')->assertSuccessful();

        $desa = Desa::first();
        $kelompok = Kelompok::first();

        foreach ([
            ['adminkresek1@gmail.com', 'admin', 'kelompok_id', $kelompok->id],
            ['absensikresek1@gmail.com', 'absensi', 'kelompok_id', $kelompok->id],
            ['admindesakresekselatan@gmail.com', 'admin', 'desa_id', $desa->id],
            ['absensidesakresekselatan@gmail.com', 'absensi', 'desa_id', $desa->id],
        ] as [$email, $peran, $kolom, $id]) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, "Akun {$email} tidak dibuat");
            $this->assertTrue($user->hasRole($peran));
            $this->assertSame($id, $user->{$kolom});
        }
    }

    /** Dijalankan lagi tiap ada desa baru, jadi struktur lama tidak boleh dapat akun kembar. */
    public function test_tidak_membuat_ulang_struktur_yang_sudah_punya_akun(): void
    {
        $this->artisan('akun:buat')->assertSuccessful();
        $this->artisan('akun:buat')->assertSuccessful();

        $this->assertSame(4, User::count());
    }

    /** Akun lama beremail lain tetap dihitung: patokannya struktur + peran, bukan emailnya. */
    public function test_akun_lama_beremail_beda_pola_tetap_dilewati(): void
    {
        $kelompok = Kelompok::first();
        User::create([
            'name' => 'Admin Lama',
            'email' => 'kresek1@gmail.com',
            'password' => 'password',
            'kelompok_id' => $kelompok->id,
        ])->syncRoles(['admin']);

        $this->artisan('akun:buat')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'adminkresek1@gmail.com']);
        $this->assertDatabaseHas('users', ['email' => 'absensikresek1@gmail.com']);
    }

    public function test_dry_run_tidak_menulis_apa_pun(): void
    {
        $this->artisan('akun:buat', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}
