<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WaGateway
{
    /**
     * Kirim satu pesan teks lewat WA Gateway: POST {gateway_url}/api/send.
     *
     * Gateway lambat jangan sampai bikin webhook menggantung sampai timeout default (30s):
     * gateway bisa mengirim ulang webhook-nya, dan izin yang sama tercatat/dinotifikasi dobel.
     * Gagal kirim juga tidak boleh melempar keluar — izinnya sudah tercatat, mengulang
     * seluruh proses cuma bikin log dan notifikasi dobel.
     *
     * @return bool true hanya kalau gateway benar-benar mengirimkannya.
     */
    public static function kirim(string $target, string $message): bool
    {
        $gateway = config('services.wa.gateway_url');
        $apiKey = config('services.wa.device_api_key');

        if (! $gateway || ! $apiKey) {
            return false;
        }

        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout(5)
                ->timeout(10)
                ->post("{$gateway}/api/send", [
                    'target' => $target,
                    'message' => $message,
                    'type' => 'text',
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (ConnectionException $e) {
            report($e);

            return false;
        }
    }
}
