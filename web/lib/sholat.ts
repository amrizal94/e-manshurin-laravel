import { Coordinates, CalculationMethod, PrayerTimes } from "adhan";

export interface WaktuSholat {
  nama: string;
  waktu: Date;
}

/**
 * Jadwal sholat dihitung di perangkat, bukan menumpang layanan luar.
 *
 * Kiosk di masjid jaringannya putus-putus, dan layar kosong tiap internet ngadat itu
 * lebih buruk daripada tidak ada jadwalnya sama sekali. Perhitungannya astronomi murni,
 * jadi tidak butuh jaringan apa pun.
 *
 * Rumusnya dipinjam dari pustaka yang sudah teruji, bukan ditulis sendiri: salah hitung
 * waktu sholat di aplikasi masjid itu memalukan, dan ini satu-satunya tempat yang tidak
 * pantas dihemat barisnya.
 */
export function jadwalSholat(koordinat: string, tanggal = new Date()): WaktuSholat[] {
  const [lintang, bujur] = koordinat.split(",").map((n) => Number(n.trim()));

  if (!Number.isFinite(lintang) || !Number.isFinite(bujur)) return [];

  // Sudut Kemenag: Subuh 20 derajat, Isya 18 derajat — beda dari semua metode bawaan
  // pustaka ini, jadi diisi sendiri.
  const parameter = CalculationMethod.Other();
  parameter.fajrAngle = 20;
  parameter.ishaAngle = 18;
  // Ihtiyati dua menit, seperti jadwal cetak Kemenag. Tanpa ini hasilnya selalu lebih
  // awal dua menit dari jadwal yang ditempel di dinding masjid, dan yang disalahkan
  // aplikasinya.
  parameter.adjustments = { fajr: 2, sunrise: 2, dhuhr: 2, asr: 2, maghrib: 2, isha: 2 };

  const waktu = new PrayerTimes(new Coordinates(lintang, bujur), tanggal, parameter);

  return [
    { nama: "Subuh", waktu: waktu.fajr },
    { nama: "Dzuhur", waktu: waktu.dhuhr },
    { nama: "Ashar", waktu: waktu.asr },
    { nama: "Maghrib", waktu: waktu.maghrib },
    { nama: "Isya", waktu: waktu.isha },
  ];
}

/** Yang paling dekat di depan. Sesudah Isya lewat, yang berikutnya Subuh besok. */
export function sholatBerikutnya(koordinat: string, sekarang = new Date()): WaktuSholat | null {
  const nanti = jadwalSholat(koordinat, sekarang).find((s) => s.waktu > sekarang);

  if (nanti) return nanti;

  const besok = new Date(sekarang);
  besok.setDate(besok.getDate() + 1);

  return jadwalSholat(koordinat, besok)[0] ?? null;
}

export function jam(waktu: Date): string {
  return waktu.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}
