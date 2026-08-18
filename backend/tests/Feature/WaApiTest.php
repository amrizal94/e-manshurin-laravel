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

    public function test_nama_bertanda_baca_dan_kurang_lengkap_tetap_cocok(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['nama_lengkap' => 'MOCHAMAD BAYU AJI S.P.']);
        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $variasi = [
            'izin Mochamad Bayu Aji Sp ada acara keluarga' => 'ada acara keluarga',
            'izin Mochamad Bayu aji ada acara keluarga' => 'ada acara keluarga',
            'IZIN MOCHAMAD BAYU AJI S.P. SAKIT' => 'SAKIT',
            'izin Mochamad  Bayu   Aji S.P. kerja' => 'kerja',
        ];

        foreach ($variasi as $pesan => $keterangan) {
            Absensi::query()->delete();

            $this->kirimWebhook($this->payloadIzin($pesan))->assertOk();

            $absensi = Absensi::first();
            $this->assertNotNull($absensi, "Nama tidak cocok untuk pesan: {$pesan}");
            $this->assertSame($this->jamaah->id, $absensi->jamaah_id);
            $this->assertSame($keterangan, $absensi->keterangan, "Keterangan salah untuk pesan: {$pesan}");
        }
    }

    private function kegiatanHariIni(): Kegiatan
    {
        return Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);
    }

    public function test_nomor_terdaftar_bisa_izin_tanpa_menulis_nama(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        // Disimpan format lokal, pengirim datang format internasional
        $this->jamaah->update(['no_hp' => '0812-3456-7890']);
        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin sakit'))->assertOk();

        $absensi = Absensi::first();
        $this->assertNotNull($absensi);
        $this->assertSame($this->jamaah->id, $absensi->jamaah_id);
        $this->assertSame('sakit', $absensi->keterangan);
    }

    public function test_anggota_keluarga_tanpa_wa_diwakili_nomor_kepala_keluarga(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '081234567890', 'status_kk' => 'kepala_keluarga']);
        $anak = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Aisyah Nur Hudiana',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'caberawit',
            'status_kk' => 'anak',
            'kepala_keluarga_id' => $this->jamaah->id,
        ]);
        $this->kegiatanHariIni();
        Kegiatan::create([
            'nama' => 'Pengajian Caberawit', 'jenis_pengajian' => 'caberawit',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        // Nama anak ditulis walau anak tidak punya WA sendiri
        $this->kirimWebhook($this->payloadIzin('izin Aisyah Nur Hudiana demam'))->assertOk();
        $this->assertSame($anak->id, Absensi::first()->jamaah_id);

        // Tanpa nama: nomor mewakili 2 orang, sistem minta pertegas — bukan menebak
        Absensi::query()->delete();
        $this->kirimWebhook($this->payloadIzin('izin demam'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'terdaftar untuk beberapa jamaah')
            && str_contains($r['message'], 'Aisyah Nur Hudiana'));
    }

    public function test_nomor_tak_dikenal_tetap_wajib_nama(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);
        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin sakit'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'tidak ditemukan'));
    }

    public function test_nama_ditulis_menang_atas_nomor_pengirim(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '081234567890']);
        $lain = Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Slamet Riyadi',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);
        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin Slamet Riyadi sakit'))->assertOk();

        $this->assertSame($lain->id, Absensi::first()->jamaah_id);
    }

    public function test_typo_nama_dimaafkan_kalau_nomor_pengirim_terdaftar(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['nama_lengkap' => 'MOCHAMAD BAYU AJI S.P.', 'no_hp' => '081234567890']);
        $this->kegiatanHariIni();

        // "Mochammad" salah di kata pertama, jadi pencocokan prefix pasti gagal
        $this->kirimWebhook($this->payloadIzin('izin Mochammad Bayu Aji Sp sakit'))->assertOk();

        $absensi = Absensi::first();
        $this->assertNotNull($absensi);
        $this->assertSame($this->jamaah->id, $absensi->jamaah_id);
        $this->assertSame('sakit', $absensi->keterangan, 'Nama typo harus dikupas dari keterangan');
    }

    public function test_typo_tidak_dimaafkan_kalau_nomor_pengirim_tak_dikenal(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['nama_lengkap' => 'MOCHAMAD BAYU AJI S.P.']);
        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin Mochammad Bayu Aji Sp sakit'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'tidak ditemukan'));
    }

    public function test_typo_pada_keluarga_yang_namanya_mirip_tidak_ditebak(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['nama_lengkap' => 'Ahmad Nur Hudiana', 'no_hp' => '081234567890']);
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Ahmed Nur Hudiana',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
            'kepala_keluarga_id' => $this->jamaah->id,
        ]);
        $this->kegiatanHariIni();

        // "Ahmod" sama dekatnya ke dua nama: harus tanya, bukan menebak
        $this->kirimWebhook($this->payloadIzin('izin Ahmod Nur Hudiana sakit'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'terdaftar untuk beberapa jamaah'));
    }

    public function test_izin_wa_tercatat_di_log_aktivitas_dengan_nomor_pengirim(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '089999999999']);
        $this->kegiatanHariIni();

        // Dikirim dari nomor lain (nomor pengirim di payload: 6281234567890)
        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana sakit'))->assertOk();

        $log = \Spatie\Activitylog\Models\Activity::latest('id')->first();
        $this->assertSame('Izin via WhatsApp', $log->description);
        $this->assertSame($this->jamaah->id, $log->subject_id);
        $this->assertSame('6281234567890', $log->properties['nomor_pengirim']);
        $this->assertSame('nomor orang lain', $log->properties['asal_nomor']);

        $this->jamaah->update(['no_hp' => '081234567890']);
        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana sakit'))->assertOk();

        $this->assertSame('nomor sendiri', \Spatie\Activitylog\Models\Activity::latest('id')->first()->properties['asal_nomor']);
    }

    public function test_jamaah_dikabari_kalau_izinnya_dikirim_dari_nomor_lain(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '089999999999']);
        $this->kegiatanHariIni();

        // Pengirim 6281234567890, nomor jamaah 6289999999999
        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana sakit'))->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['target'] === '6289999999999'
            && str_contains($r['message'], 'Januar Agung Hudiana')
            && str_contains($r['message'], 'dikirim dari nomor lain'));
    }

    public function test_tidak_dikabari_kalau_izin_dari_nomornya_sendiri(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '081234567890']);
        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin sakit'))->assertOk();

        // Cuma balasan ke pengirim, tanpa pemberitahuan ke diri sendiri
        Http::assertSentCount(1);
    }

    public function test_anak_tanpa_nomor_dikabari_lewat_kepala_keluarga(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $this->jamaah->update(['no_hp' => '089999999999']);
        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Aisyah Nur Hudiana',
            'jenis_kelamin' => 'P',
            'kategori_usia' => 'caberawit',
            'kepala_keluarga_id' => $this->jamaah->id,
        ]);
        Kegiatan::create([
            'nama' => 'Pengajian Caberawit', 'jenis_pengajian' => 'caberawit',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Aisyah Nur Hudiana demam'))->assertOk();

        Http::assertSent(fn ($r) => $r['target'] === '6289999999999'
            && str_contains($r['message'], 'Aisyah Nur Hudiana'));
    }

    public function test_izin_wa_tidak_menimpa_status_hadir(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $kegiatan = $this->kegiatanHariIni();
        Absensi::create([
            'kegiatan_id' => $kegiatan->id, 'jamaah_id' => $this->jamaah->id,
            'status' => 'hadir', 'metode' => 'face', 'waktu_absen' => now(),
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana sakit'))->assertOk();

        $absensi = Absensi::first();
        $this->assertSame('hadir', $absensi->status);
        $this->assertSame('face', $absensi->metode);
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'sudah tercatat hadir'));
    }

    public function test_izin_diterima_untuk_kegiatan_besok_kalau_hari_ini_tidak_ada(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        $besok = now('Asia/Jakarta')->addDay();
        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => $besok->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana kerja'))->assertOk();

        $absensi = Absensi::first();
        $this->assertNotNull($absensi, 'Izin H-1 harus diterima');
        $this->assertSame('izin', $absensi->status);

        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'pengajian besok')
            && str_contains($r['message'], $besok->format('d/m/Y')));
    }

    public function test_tanpa_kegiatan_hari_ini_dan_besok_tetap_membalas(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id,
            'tanggal' => now('Asia/Jakarta')->addDays(3)->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana kerja'))->assertOk();

        $this->assertSame(0, Absensi::count());
        Http::assertSent(fn ($r) => str_contains($r['message'] ?? '', 'hari ini atau besok'));
    }

    public function test_gateway_mati_tidak_bikin_webhook_gagal(): void
    {
        // Gateway timeout: izin tetap tercatat dan webhook tetap 200, biar gateway tidak retry
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        $this->kegiatanHariIni();

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana sakit'))->assertOk();

        $this->assertSame('izin', Absensi::first()->status);
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

    public function test_nama_kembar_dibalas_minta_diperjelas_tanpa_menyebut_nama(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung Hudiana',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        Kegiatan::create([
            'nama' => 'Pengajian Usman', 'jenis_pengajian' => 'usman',
            'kelompok_id' => $this->kelompok->id, 'tanggal' => now()->toDateString(),
            'created_by' => $this->petugas->id,
        ]);

        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Hudiana Sakit'))->assertOk();

        Http::assertSent(fn ($r) => str_contains($r['message'], 'Ada lebih dari satu jamaah')
            && ! str_contains($r['message'], 'Januar'));
        $this->assertSame(0, Absensi::count());
    }

    public function test_nama_yang_jadi_awalan_nama_lain_dianggap_kembar(): void
    {
        Http::fake(['*/api/send' => Http::response(['success' => true])]);

        Jamaah::create([
            'kelompok_id' => $this->kelompok->id,
            'nama_lengkap' => 'Januar Agung Hudiana Putra',
            'jenis_kelamin' => 'L',
            'kategori_usia' => 'usman',
        ]);

        // "Januar Agung" cocok ke dua-duanya — dulu dibalas "tidak ditemukan", padahal ketemu dua
        $this->kirimWebhook($this->payloadIzin('izin Januar Agung Sakit'))->assertOk();

        Http::assertSent(fn ($r) => str_contains($r['message'], 'Ada lebih dari satu jamaah'));
    }

    public function test_admin_bisa_ubah_template_via_web(): void
    {
        $this->petugas->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin'));

        $this->actingAs($this->petugas)
            ->putJson('/api/settings/' . Setting::WA_REPLY_TEMPLATE, ['value' => 'Halo {nama}'])
            ->assertOk();

        $this->assertSame('Halo {nama}', Setting::get(Setting::WA_REPLY_TEMPLATE));
    }
}
