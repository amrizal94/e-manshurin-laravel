<?php

namespace App\Console\Commands;

use App\Models\Kegiatan;
use App\Support\WaGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Deteksi device WA yang mati diam-diam: proses gateway tetap hidup dan dashboard
 * tetap hijau, tapi sesi device E-Manshurin sudah logout — izin lewat WA berhenti
 * dibalas tanpa ada yang tahu sampai jamaah mengeluh. Satu-satunya tes yang bisa
 * dipercaya adalah mengirim pesan sungguhan ke nomor device itu sendiri.
 *
 * Alarmnya lewat Telegram, bukan WA: percuma mengabari lewat jalur yang sedang mati.
 */
class WaHealthcheck extends Command
{
    protected $signature = 'wa:healthcheck';

    protected $description = 'Kirim pesan tes ke nomor device sendiri; alarm Telegram kalau WA mati';

    private const FLAG_MATI = 'wa_gateway_down';

    public function handle(): int
    {
        $target = config('services.wa.healthcheck_target');

        if (! $target || ! config('services.wa.device_api_key')) {
            $this->info('WA belum dikonfigurasi — healthcheck dilewati.');

            return self::SUCCESS;
        }

        $pesan = '✅ healthcheck ' . Kegiatan::sekarangLokal()->format('d/m H:i');

        // Sekali gagal belum tentu mati — gateway sesekali sibuk. Yang dilaporkan hanya
        // gagal dua kali berturut-turut.
        foreach ([0, 5] as $jeda) {
            if ($jeda) {
                Sleep::for($jeda)->seconds();
            }

            if (WaGateway::kirim($target, $pesan)) {
                if (Cache::pull(self::FLAG_MATI)) {
                    $this->kabari('✅ WA E-Manshurin PULIH (' . Kegiatan::sekarangLokal()->format('d/m H:i') . ').');
                }
                $this->info('Gateway sehat.');

                return self::SUCCESS;
            }
        }

        // Sekali per insiden: cron jalan tiap jam, alarm tiap jam cuma bikin diabaikan.
        if (! Cache::has(self::FLAG_MATI)) {
            $this->kabari('🔴 WA E-Manshurin DOWN (' . Kegiatan::sekarangLokal()->format('d/m H:i') . ").\n"
                . "Izin lewat WA tidak dicatat dan tidak dibalas. Cek dashboard gateway, mungkin perlu scan QR ulang:\n"
                . config('services.wa.gateway_url') . '/dashboard');
            Cache::put(self::FLAG_MATI, now()->toDateTimeString(), now()->addDay());
        }

        $this->error('Gateway tidak merespons.');

        return self::FAILURE;
    }

    /**
     * Alarm lewat Telegram — jalur yang tidak ikut mati waktu WA mati.
     * Tetap masuk log supaya jejaknya ada meski bot belum dikonfigurasi.
     */
    private function kabari(string $pesan): void
    {
        Log::error($pesan);

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $pesan,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
