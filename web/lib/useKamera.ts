"use client";

import { useCallback, useEffect, useState, type RefObject } from "react";

// Pilihan kamera melekat ke perangkatnya, bukan ke akunnya: satu akun absensi bisa
// dipakai di beberapa laptop dengan kamera berbeda. Jadi disimpan di browser saja.
const KUNCI = "kamera-terpilih";
const UKURAN = { width: 640, height: 480 };

/**
 * Nyalakan kamera ke `videoRef`, plus daftar kamera yang bisa dipilih petugas.
 * Laptop dengan webcam USB biasanya punya dua: tanpa pilihan, browser hampir
 * selalu memakai yang bawaan layar.
 */
export function useKamera(videoRef: RefObject<HTMLVideoElement | null>, aktif: boolean) {
  const [daftar, setDaftar] = useState<MediaDeviceInfo[]>([]);
  // localStorage tidak ada saat halaman dirender di server — di sana selalu kosong,
  // dan itu aman: daftar kameranya juga baru terisi setelah jalan di browser.
  const [dipilih, setDipilih] = useState(() =>
    typeof window === "undefined" ? "" : (localStorage.getItem(KUNCI) ?? "")
  );
  const [siap, setSiap] = useState(false);
  // navigator.mediaDevices cuma ada di secure context — kiosk yang dibuka lewat
  // http://<ip-lan> sampai sini, dan tanpa pesan ini layarnya cuma hitam tanpa sebab.
  const [error, setError] = useState(() =>
    typeof window !== "undefined" && !navigator.mediaDevices
      ? "Kamera butuh HTTPS. Buka kiosk lewat alamat https, bukan alamat IP biasa."
      : ""
  );
  const [ulang, setUlang] = useState(0);

  const pilih = useCallback((id: string) => {
    localStorage.setItem(KUNCI, id);
    setDipilih(id);
  }, []);

  useEffect(() => {
    if (!aktif || !navigator.mediaDevices) return;

    const video = videoRef.current;
    let batal = false;

    const minta = (v: MediaTrackConstraints) =>
      navigator.mediaDevices.getUserMedia({ video: { ...UKURAN, ...v } });

    // deviceId exact supaya browser tidak diam-diam ganti ke kamera lain — kalau kameranya
    // memang sudah tidak ada, ditangani catch di bawah.
    minta(dipilih ? { deviceId: { exact: dipilih } } : { facingMode: "user" })
      // Kamera pilihan hilang (USB tercabut, atau id-nya berubah setelah dicabut-pasang):
      // kiosk jangan ikut mati, pakai kamera bawaan supaya petugas bisa memilih ulang.
      .catch(() => minta({ facingMode: "user" }))
      .then(async (s) => {
        // komponen sudah unmount sebelum kamera siap — jangan nyalakan, langsung matikan lagi
        if (batal || !video) {
          s.getTracks().forEach((t) => t.stop());
          return;
        }
        video.srcObject = s;
        setSiap(true);
        setError("");

        // Kamera dicabut saat kiosk jalan: stream mati diam-diam dan gambarnya membeku
        // tanpa error. Ini memicu effect jalan lagi supaya jatuh ke kamera bawaan.
        s.getVideoTracks()[0]?.addEventListener("ended", () => setUlang((n) => n + 1));

        // Nama kamera baru terisi setelah izin diberikan, jadi daftarnya diambil setelah
        // stream jalan — kalau lebih awal, isinya cuma "Kamera 1", "Kamera 2".
        const semua = (await navigator.mediaDevices.enumerateDevices()).filter(
          (d) => d.kind === "videoinput"
        );
        if (batal) return;
        setDaftar(semua);
        if (dipilih && !semua.some((d) => d.deviceId === dipilih)) pilih("");
      })
      .catch(() => setError("Kamera tidak dapat diakses. Izinkan akses kamera di browser."));

    return () => {
      batal = true;
      setSiap(false);
      const stream = video?.srcObject;
      if (stream instanceof MediaStream) stream.getTracks().forEach((t) => t.stop());
    };
  }, [aktif, dipilih, ulang, videoRef, pilih]);

  // Kamera USB kerap baru ditancapkan setelah halaman kiosk terbuka.
  useEffect(() => {
    if (!aktif || !navigator.mediaDevices) return;
    const segarkan = () =>
      navigator.mediaDevices
        .enumerateDevices()
        .then((d) => setDaftar(d.filter((x) => x.kind === "videoinput")));
    navigator.mediaDevices.addEventListener("devicechange", segarkan);
    return () => navigator.mediaDevices.removeEventListener("devicechange", segarkan);
  }, [aktif]);

  return { daftar, dipilih, pilih, siap: aktif && siap, error };
}
