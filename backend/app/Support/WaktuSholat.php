<?php

namespace App\Support;

use App\Models\Setting;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Carbon;
use IslamicNetwork\PrayerTimes\Method;
use IslamicNetwork\PrayerTimes\PrayerTimes;

/**
 * Waktu sholat versi server, kembaran dari web/lib/sholat.ts.
 *
 * Dua salinan karena dua tempat yang berbeda membutuhkannya: kiosk menghitung sendiri di
 * perangkatnya supaya tetap jalan tanpa jaringan, sedangkan penerbit kegiatan jalan di
 * server tengah malam. Keduanya memakai angka yang sama persis — sudut Kemenag dan
 * ihtiyati dua menit — dan WaktuSholatTest menjaga supaya tidak diam-diam berbeda.
 *
 * Rumusnya dari pustaka, bukan ditulis sendiri: salah hitung waktu sholat di aplikasi
 * masjid itu memalukan, dan ini satu-satunya tempat yang tidak pantas dihemat barisnya.
 */
class WaktuSholat
{
    /** Sudut Kemenag: Subuh 20 derajat, Isya 18 derajat. */
    private const SUDUT_SUBUH = 20.0;

    private const SUDUT_ISYA = 18.0;

    /**
     * Ihtiyati, seperti jadwal cetak Kemenag. Tanpa ini hasilnya selalu lebih awal dua
     * menit dari jadwal yang ditempel di dinding masjid, dan yang disalahkan aplikasinya.
     */
    private const IHTIYATI_MENIT = 2;

    public const PATOKAN = ['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'];

    /**
     * Jam waktu sholat pada satu tanggal, dalam waktu lokal.
     *
     * @return array<string, Carbon>
     */
    public static function untuk(Carbon $tanggal, ?string $koordinat = null): array
    {
        [$lintang, $bujur] = self::koordinat($koordinat);

        $zona = config('app.zona_lokal', 'Asia/Jakarta');

        $metode = new Method('Kemenag');
        $metode->setFajrAngle(self::SUDUT_SUBUH);
        $metode->setIshaAngleOrMins(self::SUDUT_ISYA);

        $penghitung = new PrayerTimes(Method::METHOD_CUSTOM);
        $penghitung->setCustomMethod($metode);

        $hasil = $penghitung->getTimes(
            new DateTime($tanggal->toDateString(), new DateTimeZone($zona)),
            $lintang,
            $bujur,
        );

        $jadi = fn (string $jam) => Carbon::parse("{$tanggal->toDateString()} {$jam}", $zona)
            ->addMinutes(self::IHTIYATI_MENIT);

        return [
            'subuh' => $jadi($hasil['Fajr']),
            'dzuhur' => $jadi($hasil['Dhuhr']),
            'ashar' => $jadi($hasil['Asr']),
            'maghrib' => $jadi($hasil['Maghrib']),
            'isya' => $jadi($hasil['Isha']),
        ];
    }

    /** @return array{0: float, 1: float} */
    private static function koordinat(?string $koordinat): array
    {
        $isi = $koordinat ?? Setting::get(Setting::KOORDINAT, Setting::BAWAAN[Setting::KOORDINAT]);
        $bagian = array_map(trim(...), explode(',', (string) $isi));

        // Koordinat ngawur jangan sampai menerbitkan kegiatan berjam aneh tanpa ada yang
        // tahu — jatuh ke bawaan, yang setidaknya masih Kediri.
        if (count($bagian) !== 2 || ! is_numeric($bagian[0]) || ! is_numeric($bagian[1])) {
            $bagian = explode(',', Setting::BAWAAN[Setting::KOORDINAT]);
        }

        return [(float) $bagian[0], (float) $bagian[1]];
    }
}
