<?php

namespace App\Support;

use App\Models\Jamaah;
use App\Models\Kelompok;
use App\Models\User;

/**
 * Membaca CSV data jamaah dan melaporkan apa yang akan terjadi — tanpa menulis apa pun.
 *
 * Sengaja CSV, bukan Excel: "Save As CSV" ada di setiap Excel, sementara membaca .xlsx
 * butuh paket tambahan yang berat cuma untuk mengurai tabel.
 */
class ImporJamaah
{
    public const WAJIB = ['desa', 'kelompok', 'nama_lengkap', 'jenis_kelamin', 'kategori_usia'];

    public const OPSIONAL = [
        'nama_panggilan', 'tempat_lahir', 'tanggal_lahir', 'alamat', 'no_hp',
        'pekerjaan', 'status_kk', 'status_mubaligh', 'aktif', 'keterangan_tidak_aktif',
    ];

    /**
     * Bukan batas teknis — 7000 baris pun masuk dalam hitungan detik. Ini batas manusia:
     * laporan kesalahan 7000 baris tidak akan dibaca siapa pun, dan file sebesar itu yang
     * salah kolom berarti 7000 data salah sekaligus. Dipecah per desa, satu kesalahan
     * cuma merusak satu desa.
     */
    public const MAKS_BARIS = 2000;

    /** Baris bermasalah yang dikirim balik ke layar. Sisanya cukup dihitung. */
    private const MAKS_LAPORAN = 200;

    public const KATEGORI_USIA = ['paud_tk', 'caberawit', 'praremaja', 'remaja', 'usman', 'menikah', 'janda', 'duda'];

    public const STATUS_KK = ['kepala_keluarga', 'suami', 'istri', 'anak', 'menantu', 'cucu', 'orang_tua', 'mertua'];

    /** Label yang tampil di layar ikut diterima — orang menyalin dari sana, bukan dari kode. */
    private const ALIAS_KATEGORI = [
        'paud' => 'paud_tk', 'tk' => 'paud_tk', 'paud_tk' => 'paud_tk',
        'sd' => 'caberawit', 'caberawit_sd' => 'caberawit',
        'smp' => 'praremaja', 'praremaja_smp' => 'praremaja',
        'sma' => 'remaja', 'remaja_sma' => 'remaja',
        'usia_mandiri' => 'usman', 'mandiri' => 'usman',
        'nikah' => 'menikah', 'sudah_menikah' => 'menikah',
    ];

    private const ALIAS_KELAMIN = [
        'l' => 'L', 'laki' => 'L', 'laki_laki' => 'L', 'lakilaki' => 'L', 'pria' => 'L', 'male' => 'L',
        'p' => 'P', 'perempuan' => 'P', 'wanita' => 'P', 'female' => 'P',
    ];

    private const ALIAS_KOLOM = [
        'no_handphone' => 'no_hp', 'nomor_hp' => 'no_hp', 'hp' => 'no_hp', 'telepon' => 'no_hp',
        'nama' => 'nama_lengkap', 'panggilan' => 'nama_panggilan',
        'jk' => 'jenis_kelamin', 'l_p' => 'jenis_kelamin',
        'kategori' => 'kategori_usia', 'usia' => 'kategori_usia',
        'mubaligh' => 'status_mubaligh', 'kk' => 'status_kk',
    ];

    /** @var array<string, Kelompok> kunci "desa|kelompok" huruf kecil */
    private array $kelompoks;

    /** @var array<string, true> kunci "kelompok_id|nama_lengkap" huruf kecil */
    private array $sudahAda;

    private string $pemisah = ',';

    public function __construct(User $actor)
    {
        $this->kelompoks = Kelompok::visibleTo($actor)->with('desa:id,nama')->get()
            ->keyBy(fn (Kelompok $k) => $this->kunci($k->desa?->nama ?? '', $k->nama))
            ->all();

        $this->sudahAda = Jamaah::visibleTo($actor)->get(['kelompok_id', 'nama_lengkap'])
            ->mapWithKeys(fn (Jamaah $j) => [$j->kelompok_id.'|'.mb_strtolower(trim($j->nama_lengkap)) => true])
            ->all();
    }

    /**
     * @return array{
     *     ringkasan: array{total:int, siap:int, perhatian:int, error:int},
     *     catatan: list<string>,
     *     gagal: ?string,
     *     baris: list<array>,
     *     dipotong: int
     * }
     */
    public function periksa(string $isi): array
    {
        $kosong = ['ringkasan' => ['total' => 0, 'siap' => 0, 'perhatian' => 0, 'error' => 0], 'catatan' => [], 'gagal' => null, 'baris' => [], 'dipotong' => 0];

        $handle = $this->bukaCsv($isi);
        $judul = $this->baca($handle);

        if ($judul === false) {
            return [...$kosong, 'gagal' => 'File kosong atau bukan CSV.'];
        }

        $kolom = $this->petakanJudul($judul);
        $hilang = array_diff(self::WAJIB, $kolom);

        if ($hilang !== []) {
            return [...$kosong, 'gagal' => 'Kolom wajib belum ada: '.implode(', ', $hilang).'. Unduh templatnya dan salin data ke situ.'];
        }

        $catatan = [];
        $baris = [];
        $hitung = ['siap' => 0, 'perhatian' => 0, 'error' => 0];
        $nomor = 1;
        $dalamFile = [];
        $adaTanggalGaris = false;

        while (($mentah = $this->baca($handle)) !== false) {
            $nomor++;

            // fgetcsv memberi [null] untuk baris kosong; Excel gemar menyimpan puluhan di akhir file.
            if (trim(implode('', array_map(strval(...), $mentah))) === '') {
                continue;
            }

            if ($hitung['siap'] + $hitung['perhatian'] + $hitung['error'] >= self::MAKS_BARIS) {
                fclose($handle);

                return [...$kosong, 'gagal' => 'File berisi lebih dari '.self::MAKS_BARIS.' baris. Pecah per desa dulu — laporan kesalahan sepanjang itu tidak mungkin diperiksa.'];
            }

            $hasil = $this->periksaBaris($nomor, $this->ambilNilai($kolom, $mentah), $dalamFile, $adaTanggalGaris);
            $hitung[$hasil['status']]++;

            if ($hasil['status'] !== 'siap' || count($baris) < 5) {
                $baris[] = $hasil;
            }
        }

        fclose($handle);

        if ($this->pemisah !== ',') {
            $catatan[] = 'File dibaca dengan pemisah "'.$this->pemisah.'".';
        }
        if ($adaTanggalGaris) {
            $catatan[] = 'Ada tanggal lahir berformat 30/11/1990. Dibaca sebagai tanggal/bulan/tahun — '
                .'jadi 30/11/1990 berarti 30 November 1990. Periksa contoh di bawah; kalau file Anda bulan dulu, perbaiki file dulu.';
        }

        $dipotong = max(0, count($baris) - self::MAKS_LAPORAN);

        return [
            'ringkasan' => ['total' => array_sum($hitung), ...$hitung],
            'catatan' => $catatan,
            'gagal' => null,
            'baris' => array_slice($baris, 0, self::MAKS_LAPORAN),
            'dipotong' => $dipotong,
        ];
    }

    /**
     * Excel Indonesia sering menyimpan CSV dengan titik koma, dan menambahkan BOM di depan
     * yang bikin kolom pertama terbaca "\u{FEFF}desa". Keduanya diberesi di sini, bukan
     * dijadikan syarat yang harus dipatuhi petugas.
     */
    private function bukaCsv(string $isi): mixed
    {
        // Excel yang disimpan sebagai "CSV" biasa (bukan "CSV UTF-8") memakai Windows-1252.
        // Tanpa ini, nama ber-aksen jadi byte rusak dan json_encode gagal diam-diam.
        if (! mb_check_encoding($isi, 'UTF-8')) {
            $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
        }

        $isi = preg_replace('/^\x{FEFF}/u', '', $isi) ?? $isi;
        // Baris "sep=;" adalah petunjuk untuk Excel, bukan data. Templat kita sendiri memakainya.
        if (preg_match('/^sep=(.)\r?\n/', $isi, $m)) {
            $isi = substr($isi, strlen($m[0]));
        }

        $judul = strtok($isi, "\n") ?: '';
        $this->pemisah = collect([',', ';', "\t"])->sortByDesc(fn ($p) => substr_count($judul, $p))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $isi);
        rewind($handle);

        return $handle;
    }

    /** fgetcsv butuh pemisahnya di tiap panggilan, dan escape="" supaya backslash di alamat tidak menelan kolom. */
    private function baca(mixed $handle): array|false
    {
        return fgetcsv($handle, separator: $this->pemisah, escape: '');
    }

    /** @return array<int, string> indeks kolom => nama kanonik */
    private function petakanJudul(array $judul): array
    {
        $kenal = [...self::WAJIB, ...self::OPSIONAL];
        $kolom = [];

        foreach ($judul as $i => $nama) {
            $bersih = $this->normalkan((string) $nama);
            $bersih = self::ALIAS_KOLOM[$bersih] ?? $bersih;

            if (in_array($bersih, $kenal, true)) {
                $kolom[$i] = $bersih;
            }
        }

        return $kolom;
    }

    /** @return array<string, string> nama kanonik => nilai (sudah di-trim) */
    private function ambilNilai(array $kolom, array $mentah): array
    {
        $nilai = [];

        foreach ($kolom as $i => $nama) {
            $nilai[$nama] = trim((string) ($mentah[$i] ?? ''));
        }

        return $nilai;
    }

    private function periksaBaris(int $nomor, array $nilai, array &$dalamFile, bool &$adaTanggalGaris): array
    {
        $pesan = [];
        $peringatan = [];
        $data = [];

        $nama = $nilai['nama_lengkap'] ?? '';
        if ($nama === '') {
            $pesan[] = 'nama_lengkap kosong';
        }
        $data['nama_lengkap'] = $nama;

        $kelompok = $this->kelompoks[$this->kunci($nilai['desa'] ?? '', $nilai['kelompok'] ?? '')] ?? null;
        if (! $kelompok) {
            $pesan[] = 'kelompok "'.($nilai['kelompok'] ?? '').'" di desa "'.($nilai['desa'] ?? '').'" tidak ada di wilayah Anda';
        }
        $data['kelompok_id'] = $kelompok?->id;

        $kelamin = self::ALIAS_KELAMIN[$this->normalkan($nilai['jenis_kelamin'] ?? '')] ?? null;
        if (! $kelamin) {
            $pesan[] = 'jenis_kelamin "'.($nilai['jenis_kelamin'] ?? '').'" tidak dikenal (isi L atau P)';
        }
        $data['jenis_kelamin'] = $kelamin;

        $kategoriMentah = $this->normalkan($nilai['kategori_usia'] ?? '');
        $kategori = self::ALIAS_KATEGORI[$kategoriMentah] ?? (in_array($kategoriMentah, self::KATEGORI_USIA, true) ? $kategoriMentah : null);
        if (! $kategori) {
            $pesan[] = 'kategori_usia "'.($nilai['kategori_usia'] ?? '').'" tidak dikenal';
        }
        $data['kategori_usia'] = $kategori;

        // Aturan yang sama dengan form: "janda"/"duda" sudah menyebut jenis kelaminnya sendiri,
        // dan pengajian ibu-ibu/bapak-bapak menyaring keduanya sekaligus.
        $harus = ['janda' => 'P', 'duda' => 'L'][$kategori] ?? null;
        if ($harus && $kelamin && $kelamin !== $harus) {
            $pesan[] = 'kategori "'.$kategori.'" tidak cocok dengan jenis_kelamin '.$kelamin;
        }

        if (($nilai['tanggal_lahir'] ?? '') !== '') {
            [$iso, $salah, $pakaiGaris] = $this->tanggal($nilai['tanggal_lahir']);
            $adaTanggalGaris = $adaTanggalGaris || $pakaiGaris;
            $salah === null ? $data['tanggal_lahir'] = $iso : $pesan[] = $salah;
        }

        if (($nilai['status_kk'] ?? '') !== '') {
            $kk = $this->normalkan($nilai['status_kk']);
            in_array($kk, self::STATUS_KK, true)
                ? $data['status_kk'] = $kk
                : $pesan[] = 'status_kk "'.$nilai['status_kk'].'" tidak dikenal';
        }

        if (($nilai['no_hp'] ?? '') !== '') {
            $hp = $this->noHp($nilai['no_hp']);
            $data['no_hp'] = $hp;
            if ($hp !== null && (strlen($hp) < 10 || strlen($hp) > 15)) {
                $peringatan[] = 'no_hp "'.$nilai['no_hp'].'" tidak seperti nomor HP';
            }
        }

        foreach (['status_mubaligh' => false, 'aktif' => true] as $kunci => $bawaan) {
            $data[$kunci] = ($nilai[$kunci] ?? '') === '' ? $bawaan : $this->boolean($nilai[$kunci]);
            if ($data[$kunci] === null) {
                $pesan[] = $kunci.' "'.$nilai[$kunci].'" tidak dikenal (isi ya atau tidak)';
            }
        }

        foreach (['nama_panggilan', 'tempat_lahir', 'alamat', 'pekerjaan', 'keterangan_tidak_aktif'] as $kunci) {
            $data[$kunci] = ($nilai[$kunci] ?? '') === '' ? null : $nilai[$kunci];
        }

        // Dua orang memang boleh senama — di data yang ada pun sudah ada beberapa pasang.
        // Jadi ini peringatan, bukan penolakan: yang dicegah satu orang masuk dua kali.
        if ($nama !== '' && $kelompok) {
            $kunciNama = $kelompok->id.'|'.mb_strtolower($nama);
            if (isset($this->sudahAda[$kunciNama])) {
                $peringatan[] = 'sudah ada jamaah bernama sama di kelompok ini';
            }
            if (isset($dalamFile[$kunciNama])) {
                $peringatan[] = 'nama ini muncul dua kali di file yang sama (baris '.$dalamFile[$kunciNama].')';
            }
            $dalamFile[$kunciNama] ??= $nomor;
        }

        return [
            'baris' => $nomor,
            'nama_lengkap' => $nama,
            'kelompok' => $kelompok ? ($kelompok->desa?->nama.' / '.$kelompok->nama) : '-',
            'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
            'status' => $pesan !== [] ? 'error' : ($peringatan !== [] ? 'perhatian' : 'siap'),
            'pesan' => [...$pesan, ...$peringatan],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: bool} [tanggal ISO, pesan salah, dibaca sebagai hari/bulan]
     */
    private function tanggal(string $nilai): array
    {
        // Excel gemar menempelkan jam pada kolom tanggal: "30/11/1990 0:00:00".
        $nilai = trim(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $nilai) ?? $nilai);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $nilai, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), null, false]
                : [null, 'tanggal_lahir "'.$nilai.'" bukan tanggal yang ada', false];
        }

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $nilai, $m)) {
            [, $hari, $bulan, $tahun] = array_map(intval(...), $m);

            return checkdate($bulan, $hari, $tahun)
                ? [sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari), null, true]
                // Bulan di atas 12 berarti file-nya bulan-dulu (11/30/1990). Ditolak, bukan
                // ditukar diam-diam: kalau ditukar, baris 05/11 yang juga bulan-dulu ikut
                // masuk sebagai 5 November tanpa ada yang tahu.
                : [null, 'tanggal_lahir "'.$nilai.'" tidak terbaca sebagai tanggal/bulan/tahun', true];
        }

        return [null, 'tanggal_lahir "'.$nilai.'" harus format YYYY-MM-DD', false];
    }

    /** Excel membuang nol di depan nomor HP dan menyimpannya sebagai angka. */
    private function noHp(string $nilai): ?string
    {
        $angka = preg_replace('/[^0-9]/', '', $nilai) ?? '';

        return match (true) {
            $angka === '' => null,
            str_starts_with($angka, '62') => '0'.substr($angka, 2),
            str_starts_with($angka, '8') => '0'.$angka,
            default => $angka,
        };
    }

    private function boolean(string $nilai): ?bool
    {
        return match ($this->normalkan($nilai)) {
            'ya', 'y', '1', 'true', 'benar', 'aktif', 'iya' => true,
            'tidak', 'n', '0', 'false', 'salah', 'bukan' => false,
            default => null,
        };
    }

    private function normalkan(string $nilai): string
    {
        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($nilai))) ?? '') ?? '', '_');
    }

    private function kunci(string $desa, string $kelompok): string
    {
        return mb_strtolower(trim($desa)).'|'.mb_strtolower(trim($kelompok));
    }
}
