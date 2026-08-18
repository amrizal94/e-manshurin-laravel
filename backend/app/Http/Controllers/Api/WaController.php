<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Jamaah;
use App\Models\Kegiatan;
use App\Models\Setting;
use App\Support\WaGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WaController extends Controller
{
    private const FORMAT_BANTUAN = "Halo 👋 Untuk izin tidak hadir pengajian, kirim pesan dengan format:\n\n"
        . "izin (nama lengkap) (alasan)\n\n"
        . "Contoh:\n"
        . "izin Budi Santoso ada acara keluarga\n\n"
        . "Nama harus sama persis dengan yang terdaftar ya. Terima kasih 🙏";

    /**
     * Nama yang ditulis kena ke beberapa jamaah. Sengaja tidak menyebut nama-nama yang
     * cocok: pengirimnya bisa siapa saja, dan membalas daftar nama ke nomor asing berarti
     * membocorkan data jamaah ke orang yang cuma menebak-nebak nama.
     */
    private const NAMA_KEMBAR = "Ada lebih dari satu jamaah dengan nama itu, jadi izinnya belum bisa dicatat.\n\n"
        . "Tulis nama lengkapnya sesuai data, atau minta petugas kelompok yang mencatatkan izinnya. Terima kasih 🙏";

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

        // Nama boleh kosong ("izin sakit" / "izin") kalau nomor pengirim sudah terdaftar.
        if (! preg_match('/^izin\b\s*(.*)/is', trim($data['message'] ?? ''), $m)) {
            $this->balas($data['from'], self::FORMAT_BANTUAN);

            return response()->json(['success' => true, 'message' => 'Kirim panduan format']);
        }

        $balasan = $this->prosesIzin(trim($m[1]), $data['from']);
        $this->balas($data['from'], $balasan);

        return response()->json(['success' => true, 'message' => $balasan]);
    }

    /**
     * Cocokkan nama + catat izin, kembalikan teks balasan siap kirim.
     *
     * Nama yang ditulis selalu menang; nomor pengirim cuma dipakai kalau nama gagal dicocokkan,
     * supaya nomor keluarga tidak pernah menimpa nama yang ditulis jelas.
     */
    private function prosesIzin(string $pesan, string $dari): string
    {
        [$jamaah, $keterangan, $kembar] = $this->cocokkanJamaah($pesan);

        if (! $jamaah) {
            $terdaftar = $this->jamaahDariNomor($dari);

            if ($terdaftar->isEmpty()) {
                if ($kembar) {
                    return self::NAMA_KEMBAR;
                }

                return $pesan === ''
                    ? self::FORMAT_BANTUAN
                    : 'Nama jamaah tidak ditemukan. Pastikan menulis nama lengkap sesuai data.';
            }

            [$jamaah, $keterangan] = $this->cocokkanMirip($terdaftar, $pesan);

            if (! $jamaah) {
                return "Nomor ini terdaftar untuk beberapa jamaah:\n"
                    . $terdaftar->map(fn ($j) => "- {$j->nama_lengkap}")->implode("\n")
                    . "\n\nSebutkan namanya, contoh:\nizin {$terdaftar->first()->nama_lengkap} sakit";
            }
        }

        [$kegiatans, $untukBesok] = $this->kegiatanUntukIzin($jamaah);

        if ($kegiatans->isEmpty()) {
            return "Tidak ada kegiatan hari ini atau besok untuk {$jamaah->nama_lengkap}.";
        }

        // Izin lewat WA tidak boleh menghapus bukti kehadiran: siapa pun bisa mengaku
        // lewat nomor mana pun, sedangkan absen wajah/manual berarti orangnya benar di tempat.
        $sudahHadir = $kegiatans->filter(
            fn ($k) => Absensi::where('kegiatan_id', $k->id)
                ->where('jamaah_id', $jamaah->id)
                ->where('status', 'hadir')
                ->exists()
        );

        if ($sudahHadir->count() === $kegiatans->count()) {
            return "{$jamaah->nama_lengkap} sudah tercatat hadir di {$sudahHadir->pluck('nama')->implode(', ')},"
                . ' jadi izin tidak dicatat. Hubungi petugas kalau ini keliru.';
        }

        $kegiatans = $kegiatans->diff($sudahHadir);

        foreach ($kegiatans as $kegiatan) {
            Absensi::updateOrCreate(
                ['kegiatan_id' => $kegiatan->id, 'jamaah_id' => $jamaah->id],
                ['status' => 'izin', 'keterangan' => $keterangan, 'metode' => 'wa', 'waktu_absen' => now()]
            );
        }

        // Jejak audit: izin boleh dikirim dari nomor siapa pun (jamaah tidak bawa HP, pinjam
        // punya orang), jadi yang penting bukan melarang, tapi kelihatan di Log Aktivitas.
        activity()
            ->performedOn($jamaah)
            ->withProperties([
                'nomor_pengirim' => $this->nomorWa($dari),
                'asal_nomor' => $this->nomorWa((string) $jamaah->no_hp) === $this->nomorWa($dari)
                    ? 'nomor sendiri'
                    : 'nomor orang lain',
                'kegiatan' => $kegiatans->pluck('nama')->implode(', '),
                'alasan' => $keterangan ?: '-',
            ])
            ->log('Izin via WhatsApp');

        $this->beriTahuJamaah($jamaah, $dari, $kegiatans, $keterangan);

        $template = Setting::get(Setting::WA_REPLY_TEMPLATE, Setting::DEFAULT_WA_REPLY_TEMPLATE);

        $balasan = strtr($template, [
            '{nama}' => $jamaah->nama_panggilan ?: $jamaah->nama_lengkap,
            '{keterangan}' => $keterangan ?: '-',
            '{kegiatan}' => $kegiatans->pluck('nama')->implode(', '),
        ]);

        // Tanggalnya disebut supaya jamaah sadar izinnya masuk untuk besok, bukan hari ini
        return $untukBesok
            ? "Dicatat untuk pengajian besok ({$kegiatans->first()->tanggal->format('d/m/Y')}).\n\n{$balasan}"
            : $balasan;
    }

    /**
     * Kegiatan yang izinnya bisa dicatat: hari ini, atau besok kalau hari ini tidak ada.
     * Jamaah lazim mengirim izin sejak malam sebelumnya, dan sebelumnya itu selalu ditolak.
     *
     * @return array{0: Collection<int, Kegiatan>, 1: bool}
     */
    private function kegiatanUntukIzin(Jamaah $jamaah): array
    {
        // app.timezone masih UTC sedangkan tanggal kegiatan diisi dalam waktu lokal — izin
        // yang dikirim antara tengah malam dan pagi (WIB) jangan sampai dihitung hari kemarin.
        $hariIni = Kegiatan::sekarangLokal();

        foreach ([$hariIni, $hariIni->copy()->addDay()] as $urutan => $tanggal) {
            $kegiatans = Kegiatan::whereDate('tanggal', $tanggal->toDateString())
                ->get()
                ->filter(fn ($k) => $k->pesertaQuery()->whereKey($jamaah->id)->exists());

            if ($kegiatans->isNotEmpty()) {
                return [$kegiatans, $urutan === 1];
            }
        }

        return [collect(), false];
    }

    /**
     * Kabari jamaah kalau izinnya dikirim dari nomor orang lain, supaya nama yang dicatut
     * langsung ketahuan oleh yang bersangkutan — tanpa perlu melarang bantu-izin.
     *
     * Jamaah tanpa nomor sendiri (anak kecil, lansia) dikabari lewat kepala keluarganya.
     *
     * @param  Collection<int, Kegiatan>  $kegiatans
     */
    private function beriTahuJamaah(Jamaah $jamaah, string $dari, Collection $kegiatans, string $keterangan): void
    {
        $tujuan = $this->nomorWa((string) $jamaah->no_hp);

        if ($tujuan === '' && $jamaah->kepala_keluarga_id) {
            $tujuan = $this->nomorWa((string) Jamaah::find($jamaah->kepala_keluarga_id)?->no_hp);
        }

        // Tujuan sama dengan pengirim: dia sendiri yang kirim, tidak perlu dikabari
        if ($tujuan === '' || $tujuan === $this->nomorWa($dari)) {
            return;
        }

        $this->balas($tujuan, "Pemberitahuan 📌\n"
            . "Izin tidak hadir atas nama {$jamaah->nama_lengkap} untuk "
            . "{$kegiatans->pluck('nama')->implode(', ')} sudah dicatat, dikirim dari nomor lain.\n"
            . 'Alasan: ' . ($keterangan ?: '-') . "\n\n"
            . 'Kalau ini di luar sepengetahuan Anda, hubungi petugas.');
    }

    /** Kirim balasan lewat WA Gateway. Gagal kirim diabaikan — izinnya sudah tercatat. */
    private function balas(string $target, string $message): void
    {
        WaGateway::kirim($target, $message);
    }

    /**
     * Cocokkan awal pesan dengan nama jamaah, sisanya jadi keterangan.
     *
     * Perbandingan pakai bentuk ringkas (tanpa titik/spasi/tanda baca) supaya "Bayu Aji Sp"
     * tetap kena ke "MOCHAMAD BAYU AJI S.P.". Dicoba dari kata terbanyak ke tersedikit agar
     * nama terpanjang menang, dan nama yang ditulis kurang lengkap masih bisa dikenali —
     * asalkan hanya satu jamaah yang cocok (kalau ambigu, jamaah diminta menulis lengkap).
     *
     * @return array{0: ?Jamaah, 1: string, 2: bool} jamaah, keterangan, dan apakah namanya kena ke beberapa orang
     */
    private function cocokkanJamaah(string $pesan): array
    {
        $kata = preg_split('/\s+/', trim($pesan), -1, PREG_SPLIT_NO_EMPTY);
        $aktif = Jamaah::where('aktif', true)
            ->get(['id', 'nama_lengkap', 'nama_panggilan', 'kelompok_id', 'no_hp', 'kepala_keluarga_id']);

        for ($n = count($kata); $n >= 1; $n--) {
            $ditulis = $this->ringkas(implode(' ', array_slice($kata, 0, $n)));
            if ($ditulis === '') {
                continue;
            }

            $cocok = $aktif->filter(fn ($j) => Str::startsWith($this->ringkas($j->nama_lengkap), $ditulis));

            if ($cocok->count() === 1) {
                return [$cocok->first(), implode(' ', array_slice($kata, $n)), false];
            }

            // Potongan yang lebih pendek adalah awalan dari yang ini, jadi pasti kena ke
            // orang-orang yang sama ditambah yang lain. Mencoba lebih pendek cuma menambah
            // ambigu — lebih baik langsung bilang namanya kembar daripada "tidak ditemukan".
            if ($cocok->count() > 1) {
                return [null, '', true];
            }
        }

        return [null, '', false];
    }

    /** Nama tanpa tanda baca, spasi, dan beda huruf besar/kecil: "MOCHAMAD BAYU AJI S.P." → "mochamadbayuajisp". */
    private function ringkas(string $nama): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($nama));
    }

    /**
     * Cocokkan nama di antara kandidat nomor pengirim, toleran salah/kurang huruf.
     *
     * Typo cuma dimaafkan di sini, bukan di pencarian nama global: lingkupnya sebatas jamaah
     * yang memang terhubung ke nomor itu, jadi salah orang praktis tidak mungkin. Kalau dua
     * anggota keluarga sama-sama mirip, tidak ada yang dipilih — lebih baik tanya.
     *
     * @param  Collection<int, Jamaah>  $kandidat
     * @return array{0: ?Jamaah, 1: string}
     */
    private function cocokkanMirip(Collection $kandidat, string $pesan): array
    {
        $kata = preg_split('/\s+/', trim($pesan), -1, PREG_SPLIT_NO_EMPTY);

        $nilai = $kandidat->map(fn ($j) => $this->kemiripanNama($j, $kata))
            ->sortBy(fn ($n) => $n['jarak'] ?? PHP_INT_MAX)
            ->values();

        $terbaik = $nilai->first();

        // Nama tidak disebut sama sekali (atau typo-nya kelewat jauh)
        if ($terbaik['jarak'] === null) {
            return $kandidat->count() === 1 ? [$kandidat->first(), $pesan] : [null, ''];
        }

        $kedua = $nilai->get(1);
        if ($kedua && $kedua['jarak'] !== null && $kedua['jarak'] - $terbaik['jarak'] < 2) {
            return [null, ''];
        }

        return [$terbaik['jamaah'], implode(' ', array_slice($kata, $terbaik['n']))];
    }

    /**
     * Jarak typo terkecil antara nama jamaah dan sekian kata pertama pesan.
     * Toleransi 20% panjang nama: nama panjang boleh salah beberapa huruf, nama pendek ketat.
     *
     * @param  string[]  $kata
     * @return array{jamaah: Jamaah, jarak: ?int, n: int}
     */
    private function kemiripanNama(Jamaah $jamaah, array $kata): array
    {
        $nama = $this->ringkas($jamaah->nama_lengkap);
        $toleransi = max(1, (int) floor(strlen($nama) * 0.2));
        $hasil = ['jamaah' => $jamaah, 'jarak' => null, 'n' => 0];

        for ($n = 1; $n <= count($kata); $n++) {
            $ditulis = $this->ringkas(implode(' ', array_slice($kata, 0, $n)));

            // Sudah lebih panjang dari nama + toleransi: menambah kata cuma memperjauh jarak
            if ($ditulis === '' || strlen($ditulis) > strlen($nama) + $toleransi) {
                break;
            }

            $jarak = levenshtein($ditulis, $nama);

            if ($jarak <= $toleransi && ($hasil['jarak'] === null || $jarak < $hasil['jarak'])) {
                $hasil = ['jamaah' => $jamaah, 'jarak' => $jarak, 'n' => $n];
            }
        }

        return $hasil;
    }

    /**
     * Jamaah yang boleh izin lewat nomor pengirim: pemilik nomor itu, ditambah anggota
     * keluarganya yang tidak punya no_hp sendiri (anak kecil / lansia tanpa WA).
     *
     * @return Collection<int, Jamaah>
     */
    private function jamaahDariNomor(string $dari): Collection
    {
        $nomor = $this->nomorWa($dari);

        if ($nomor === '') {
            return collect();
        }

        $aktif = Jamaah::where('aktif', true)
            ->get(['id', 'nama_lengkap', 'nama_panggilan', 'kelompok_id', 'no_hp', 'kepala_keluarga_id']);

        $pemilik = $aktif->filter(fn ($j) => $this->nomorWa((string) $j->no_hp) === $nomor);
        $idPemilik = $pemilik->pluck('id');

        $diwakili = $aktif->filter(fn ($j) => $j->kepala_keluarga_id
            && $idPemilik->contains($j->kepala_keluarga_id)
            && $this->nomorWa((string) $j->no_hp) === '');

        return $pemilik->concat($diwakili)->unique('id')->values();
    }

    /** Samakan format nomor: "0822-3652-1712", "+62 822 3652 1712", "6282236521712@s.whatsapp.net" → "6282236521712". */
    private function nomorWa(string $nomor): string
    {
        $angka = preg_replace('/\D/', '', $nomor);

        if ($angka === '' || strlen($angka) < 9) {
            return '';
        }
        if (str_starts_with($angka, '0')) {
            return '62' . ltrim($angka, '0');
        }
        if (str_starts_with($angka, '8')) {
            return '62' . $angka;
        }

        return $angka;
    }
}
