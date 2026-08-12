<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WaHealthcheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(); // jeda antar-percobaan jangan bikin test ikut menunggu

        config([
            'services.wa.gateway_url' => 'https://wa.test',
            'services.wa.device_api_key' => 'kunci',
            'services.wa.healthcheck_target' => '6282322278296',
            'services.telegram.bot_token' => 'token',
            'services.telegram.chat_id' => '123',
        ]);
    }

    public function test_gateway_sehat_tidak_membunyikan_alarm(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'wa_id' => 'abc'])]);

        $this->artisan('wa:healthcheck')->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
    }

    /** Gateway mati: alarm Telegram berbunyi sekali per insiden, bukan tiap jam. */
    public function test_gateway_mati_memicu_alarm_telegram_sekali(): void
    {
        Http::fake([
            'wa.test/*' => Http::response(['success' => false, 'message' => 'Device not connected'], 400),
            '*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('wa:healthcheck')->assertFailed();
        $this->artisan('wa:healthcheck')->assertFailed();

        Http::assertSentCount(5); // 2x percobaan kirim per run, plus 1 alarm Telegram
    }
}
