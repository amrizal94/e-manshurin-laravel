<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Kelompok $kelompok;
    private Jamaah $jamaah;

    /** Vektor dummy ternormalisasi: 1.0 di satu posisi, sisanya 0. */
    private function vektor(int $posisi): array
    {
        $v = array_fill(0, 512, 0.0);
        $v[$posisi] = 1.0;

        return $v;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach (['super_admin', 'admin', 'absensi'] as $role) {
            Role::findOrCreate($role);
        }

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->jamaah = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Remaja Satu',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'remaja',
        ]);
    }

    public function test_enroll_menyimpan_foto_dan_descriptor_terenkripsi(): void
    {
        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(0), 'confidence' => 0.99])]);

        $this->actingAs($this->admin)
            ->post("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('wajah.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_foto', 1);

        $descriptor = JamaahFaceDescriptor::first();
        $this->assertSame($this->jamaah->id, $descriptor->jamaah_id);
        $this->assertEquals(1.0, $descriptor->descriptor[0]); // cast decrypt balik ke array
        $this->assertStringStartsWith('eyJpdiI6', $descriptor->getRawOriginal('descriptor')); // terenkripsi at-rest
    }

    public function test_identify_mencatat_hadir_metode_face(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);
        $kegiatan = Kegiatan::create([
            'nama' => 'Pengajian Remaja',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
            'created_by' => $this->admin->id,
        ]);

        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(0), 'confidence' => 0.98])]);

        $this->actingAs($this->admin)
            ->post("/api/kegiatans/{$kegiatan->id}/absensi-wajah", [
                'photo' => UploadedFile::fake()->image('scan.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.jamaah.nama_lengkap', 'Remaja Satu')
            ->assertJsonPath('data.score', fn ($score) => abs($score - 1.0) < 0.001);

        $absensi = Absensi::first();
        $this->assertSame('hadir', $absensi->status);
        $this->assertSame('face', $absensi->metode);
    }

    public function test_identify_wajah_asing_ditolak(): void
    {
        JamaahFaceDescriptor::create([
            'jamaah_id' => $this->jamaah->id,
            'descriptor' => $this->vektor(0),
        ]);
        $kegiatan = Kegiatan::create([
            'nama' => 'Pengajian Remaja',
            'jenis_pengajian' => 'remaja',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => '2026-07-21',
            'created_by' => $this->admin->id,
        ]);

        // wajah orthogonal -> similarity 0 < threshold
        Http::fake(['*/extract' => Http::response(['descriptor' => $this->vektor(1), 'confidence' => 0.98])]);

        $this->actingAs($this->admin)
            ->post("/api/kegiatans/{$kegiatan->id}/absensi-wajah", [
                'photo' => UploadedFile::fake()->image('asing.jpg'),
            ])
            ->assertNotFound();

        $this->assertSame(0, Absensi::count());
    }

    public function test_face_service_mati_kembalikan_503(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->admin)
            ->post("/api/jamaahs/{$this->jamaah->id}/face-enroll", [
                'photo' => UploadedFile::fake()->image('wajah.jpg'),
            ])
            ->assertStatus(503);
    }
}
