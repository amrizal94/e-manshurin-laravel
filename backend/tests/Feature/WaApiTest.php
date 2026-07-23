<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Daerah;
use App\Models\Desa;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Kelompok;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'device-api-key-test';

    private Kelompok $kelompok;
    private Jamaah $jamaah;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wa.device_api_key' => self::SECRET,
            'services.wa.gateway_url' => 'https://wa.kreasikaryaarjuna.co.id',
        ]);

        $daerah = Daerah::create(['nama' => 'Kediri Selatan 1']);
        $desa = Desa::create(['daerah_id' => $daerah->id, 'nama' => 'Desa A']);
        $this->kelompok = Kelompok::create(['desa_id' => $desa->id, 'nama' => 'Kelompok 1']);

        $this->petugas = User::factory()->create();

        $this->jamaah = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung Hudiana',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);
    }

    private function kirimWebhook(array $payload)
    {
        $body = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);

        return $this->withHeaders(['X-Webhook-Signature' => $signature])
            ->postJson('/api/wa/webhook', $payload);
    }

    private function payloadIzin(string $message): array
    {
        return [
            'event' => 'message.received',
            'device_id' => 'device-1',
            'from' => '6281234567890',
            'type' => 'text',
            'message' => $message,
            'timestamp' => now()->timestamp * 1000,
        ];
    }

    public function test_signature_salah_ditolak(): void
    {
        $this->postJson('/api/wa/webhook', $this->payloadIzin('izin Januar Agung Hudiana Kerja'))
            ->assertUnauthorized();
    }

    public function test_pesan_bukan_izin_dibalas_panduan_format(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->kirimWebhook($this->payloadIzin('halo min'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'izin (nama lengkap) (alasan)'));
    }

    public function test_event_test_dari_tombol_dashboard_tidak_422(): void
    {
        Http::fake();

        // Tombol "Test" di dashboard gateway kirim payload tanpa field 'from'
        $this->kirimWebhook([
            'event' => 'test',
            'device_id' => 'device-1',
            'message' => 'This is a test webhook from WA Gateway',
            'timestamp' => now()->timestamp * 1000,
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_izin_tercatat_dan_balasan_dikirim_via_gateway(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana Kerja Di Semarang'))->assertOk();

        $absensi = Absensi::first();
        $this->assertSame('izin', $absensi->status);
        $this->assertSame('wa', $absensi->metode);
        $this->assertSame('Kerja Di Semarang', $absensi->keterangan);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://wa.kreasikaryaarjuna.co.id/api/send'
                && $request['target'] === '6281234567890'
                && str_contains($request['message'], 'Januar Agung Hudiana')
                && $request->hasHeader('Authorization', 'Bearer ' . self::SECRET);
        });
    }

    public function test_nama_tidak_ditemukan_tetap_membalas(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->kirimWebhook($this->payloadIzin('izin Nama Asing Tidak Ada Kerja'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'tidak ditemukan'));
    }

    public function test_tidak_ada_kegiatan_hari_ini(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana Kerja'))->assertOk();

        $this->assertSame(0, Absensi::count());
    }

    public function test_template_balasan_bisa_diatur(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);
        Setting::set(Setting::WA_REPLY_TEMPLATE, 'OK {nama}: {keterangan}');

        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana Sakit'))->assertOk();

        Http::assertSent(fn ($r) => $r['message'] === 'OK Januar Agung Hudiana: Sakit');
    }

    public function test_admin_bisa_ubah_template_via_web(): void
    {
        $this->petugas->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin'));

        $this->actingAs($this->petugas)
            ->putJson('/api/settings/wa-reply-template', ['template' => 'Halo {nama}'])
            ->assertOk();

        $this->assertSame('Halo {nama}', Setting::get(Setting::WA_REPLY_TEMPLATE));
    }
}
