export const KATEGORI_USIA: Record<string, string> = {
  paud_tk: "PAUD & TK",
  caberawit: "Caberawit (SD)",
  praremaja: "Praremaja (SMP)",
  remaja: "Remaja (SMA)",
  usman: "Usia Mandiri",
  menikah: "Menikah",
  janda: "Janda",
  duda: "Duda",
};

export const JENIS_PENGAJIAN: Record<string, string> = {
  umum: "Umum",
  caberawit: "Caberawit",
  praremaja: "Praremaja",
  remaja: "Remaja",
  usman: "Usman",
  ibu: "Ibu-ibu",
  bapak: "Bapak-bapak",
  pengurus_4s: "Pengurus 4S",
};

export const STATUS_KK: Record<string, string> = {
  kepala_keluarga: "Kepala Keluarga",
  suami: "Suami",
  istri: "Istri",
  anak: "Anak",
  menantu: "Menantu",
  cucu: "Cucu",
  orang_tua: "Orang Tua",
  mertua: "Mertua",
};

/**
 * Urutan baris rekap: dewasa dulu, turun sampai yang paling muda. Sengaja beda dengan
 * KATEGORI_USIA yang urut muda-ke-tua untuk daftar pilihan — ini urutan membaca laporan,
 * dan yang dicari lebih dulu di laporan biasanya jamaah dewasanya.
 */
export const URUTAN_REKAP = [
  "menikah", "janda", "duda", "usman", "remaja", "praremaja", "caberawit", "paud_tk",
];

export const STATUS_ABSENSI: Record<string, string> = {
  hadir: "Hadir",
  izin: "Izin",
  alpha: "Alpha",
};

export const ROLE_LABEL: Record<string, string> = {
  super_admin: "Super Admin",
  admin: "Admin",
  absensi: "Absensi",
};
