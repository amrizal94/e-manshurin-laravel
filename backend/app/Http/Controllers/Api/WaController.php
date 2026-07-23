<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WaController extends Controller
{
    private const FORMAT_BANTUAN = "Halo 👋 Untuk izin tidak hadir pengajian, kirim pesan dengan format:\n\n"
        . "izin (nama lengkap) (alasan)\n\n"
        . "Contoh:\n"
        . "izin Budi Santoso ada acara keluarga\n\n"
        . "Nama harus sama persis dengan yang terdaftar ya. Terima kasih 🙏";

    /**
     * Webhook dari WA Gateway (D:\Projects\wa) — event "message.received".
     * Pesan berawalan "izin " (case-insensitive) diproses sebagai izin; pesan teks lain
     * dibalas panduan format supaya jamaah (termasuk yang lanjut usia) langsung paham caranya.
     */
    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'from' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
        ]);

        // event=test (tombol "Test" di dashboard gateway) tidak kirim 'from'
        if ($data['event'] !== 'message.received' || ! $data['from'] || ($data['type'] ?? 'text') !== 'text') {
            return response()->json(['success' => true, 'message' => 'Diabaikan']);
        }

        if (! preg_match('/^izin\s+(.+)/is', trim($data['message'] ?? ''), $m)) {
            $this->balas($data['from'], self::FORMAT_BANTUAN);

            return response()->json(['success' => true, 'message' => 'Kirim panduan format']);
        }

        $balasan = $this->prosesIzin(trim($m[1]));
        $this->balas($data['from'], $balasan);

        return response()->json(['success' => true, 'message' => $balasan]);
    }

    /** Cocokkan nama + catat izin, kembalikan teks balasan siap kirim. */
    private function prosesIzin(string $pesan): string
    {
        [$jamaah, $keterangan] = $this->cocokkanJamaah($pesan);

        if (! $jamaah) {
            return 'Nama jamaah tidak ditemukan. Pastikan menulis nama lengkap sesuai data.';
        }

        $kegiatans = Kegiatan::whereDate('tanggal', now()->toDateString())
            ->get()
            ->filter(fn ($k) => $k->pesertaQuery()->whereKey($jamaah->id)->exists());

        if ($kegiatans->isEmpty()) {
            return "Tidak ada kegiatan hari ini untuk {$jamaah->nama_lengkap}.";
        }

        foreach ($kegiatans as $kegiatan) {
            Absensi::updateOrCreate(
                ['kegiatan_id' => $kegiatan->id, 'jamaah_id' => $jamaah->id],
                ['status' => 'izin', 'keterangan' => $keterangan, 'metode' => 'wa', 'waktu_absen' => now()]
            );
        }

        $template = Setting::get(Setting::WA_REPLY_TEMPLATE, Setting::DEFAULT_WA_REPLY_TEMPLATE);

        return strtr($template, [
            '{nama}' => $jamaah->nama_panggilan ?: $jamaah->nama_lengkap,
            '{keterangan}' => $keterangan ?: '-',
            '{kegiatan}' => $kegiatans->pluck('nama')->implode(', '),
        ]);
    }

    /** Kirim balasan lewat WA Gateway: POST {gateway_url}/api/send. */
    private function balas(string $target, string $message): void
    {
        $gateway = config('services.wa.gateway_url');
        $apiKey = config('services.wa.device_api_key');

        if (! $gateway || ! $apiKey) {
            return;
        }

        Http::withToken($apiKey)
            ->post("{$gateway}/api/send", [
                'target' => $target,
                'message' => $message,
                'type' => 'text',
            ]);
    }

    /** @return array{0: ?Jamaah, 1: string} */
    private function cocokkanJamaah(string $pesan): array
    {
        $lower = Str::lower($pesan);

        $kandidat = Jamaah::where('aktif', true)
            ->get(['id', 'nama_lengkap', 'nama_panggilan', 'kelompok_id'])
            ->filter(fn ($j) => Str::startsWith($lower, Str::lower($j->nama_lengkap)))
            ->sortByDesc(fn ($j) => strlen($j->nama_lengkap));

        $jamaah = $kandidat->first();
        if (! $jamaah) {
            return [null, ''];
        }

        $keterangan = trim(substr($pesan, strlen($jamaah->nama_lengkap)));

        return [$jamaah, $keterangan];
    }
}
