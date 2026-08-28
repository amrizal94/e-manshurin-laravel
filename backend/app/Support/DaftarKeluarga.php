<?php

namespace App\Support;

use App\Models\Jamaah;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar jamaah yang dikelompokkan per keluarga.
 *
 * Gunanya bukan menampilkan hasil pencarian, tapi memeriksanya: mengetik satu nama
 * memunculkan seisi rumahnya sekaligus, berikut apa yang belum lengkap di situ.
 * Mencari dari sisi anak sama saja dengan mencari dari sisi bapaknya.
 */
class DaftarKeluarga
{
    /** Kolom ringan untuk menghitung kunci keluarga; baris utuhnya baru diambil per halaman. */
    private const KOLOM_KUNCI = ['id', 'nama_lengkap', 'kode_keluarga', 'kepala_keluarga_id', 'status_kk'];

    public function __construct(private User $actor) {}

    /**
     * @return array{data: list<array>, page: int, last_page: int, total: int, belum_masuk_keluarga: int}
     */
    public function ambil(?string $cari, ?int $kelompokId, bool $hanyaTanpaKeluarga, int $page, int $perPage): array
    {
        // Kolomnya sengaja sedikit: tanpa saringan apa pun ini menyapu seluruh jamaah dalam
        // wilayah user, dan yang dibutuhkan cuma secukupnya untuk menghitung kunci keluarga.
        // ponytail: kalau satu wilayah tembus puluhan ribu jamaah, pindahkan pengelompokan ke SQL.
        $cocok = Jamaah::visibleTo($this->actor)
            ->select(self::KOLOM_KUNCI)
            ->when($kelompokId, fn (Builder $q, int $id) => $q->where('kelompok_id', $id))
            ->when($hanyaTanpaKeluarga, fn (Builder $q) => $q->belumMasukKeluarga())
            ->when($cari, fn (Builder $q, string $kata) => $this->cari($q, $kata))
            ->get();

        // Kepala keluarga orang yang ketemu belum tentu ikut ketemu — mencari nama anaknya
        // harus tetap sampai ke bapaknya.
        $kepala = Jamaah::visibleTo($this->actor)
            ->select(self::KOLOM_KUNCI)
            ->whereIn('id', $cocok->pluck('kepala_keluarga_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        /** @var array<string, array{nama: string, id: int, kode: ?string}> */
        $inti = [];

        foreach ($cocok as $jamaah) {
            $patokan = $kepala->get($jamaah->kepala_keluarga_id) ?? $jamaah;
            $inti[$jamaah->kunciKeluarga($kepala->get($jamaah->kepala_keluarga_id))] ??= [
                'nama' => $patokan->nama_lengkap,
                'id' => $patokan->id,
                'kode' => $patokan->kode_keluarga,
            ];
        }

        // Diurutkan menurut nama patokannya supaya halaman kedua tetap halaman kedua
        // yang sama waktu dibuka lagi.
        $kunci = collect($inti)->sortBy(fn (array $i) => mb_strtolower($i['nama']));
        $total = $kunci->count();
        $halaman = $kunci->slice(max(0, ($page - 1) * $perPage), $perPage);

        return [
            'data' => $this->susun($halaman),
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
            'belum_masuk_keluarga' => Jamaah::visibleTo($this->actor)->belumMasukKeluarga()->count(),
        ];
    }

    /** Pencarian yang sama dengan daftar per orang, ditambah kode keluarganya. */
    private function cari(Builder $query, string $kata): Builder
    {
        $pola = '%'.mb_strtolower($kata).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(nama_lengkap) like ?', [$pola])
            ->orWhereRaw('LOWER(nama_panggilan) like ?', [$pola])
            ->orWhereRaw('LOWER(kode_keluarga) like ?', [$pola]));
    }

    /**
     * Mengambil seluruh anggota keluarga pada halaman ini — termasuk yang tidak cocok
     * dengan pencariannya. Itulah gunanya: yang dicari satu nama, yang keluar serumah.
     *
     * @param  Collection<string, array{nama: string, id: int, kode: ?string}>  $halaman
     * @return list<array>
     */
    private function susun(Collection $halaman): array
    {
        if ($halaman->isEmpty()) {
            return [];
        }

        $kode = $halaman->pluck('kode')->filter()->unique()->values();
        $intiId = $halaman->pluck('id')->unique()->values();

        $anggota = Jamaah::visibleTo($this->actor)
            ->with('kelompok:id,nama,desa_id', 'kelompok.desa:id,nama', 'kepalaKeluarga:id,nama_lengkap')
            ->withCount('photos')
            ->where(fn (Builder $q) => $q
                ->whereIn('kode_keluarga', $kode)
                ->orWhereIn('id', $intiId)
                ->orWhereIn('kepala_keluarga_id', $intiId))
            ->orderBy('nama_lengkap')
            ->get();

        $semua = $anggota->keyBy('id');
        $keluarga = [];

        foreach ($anggota as $jamaah) {
            $keluarga[$jamaah->kunciKeluarga($semua->get($jamaah->kepala_keluarga_id))][] = $jamaah;
        }

        return $halaman->keys()
            // Kunci yang tidak kebagian anggota berarti barisnya tidak lolos visibleTo
            // di query kedua — dilewati saja, jangan sampai jadi kartu kosong.
            ->filter(fn (string $kunci) => isset($keluarga[$kunci]))
            ->map(fn (string $kunci) => $this->kartu($kunci, $halaman[$kunci], $keluarga[$kunci]))
            ->values()
            ->all();
    }

    /**
     * @param  array{nama: string, id: int, kode: ?string}  $inti
     * @param  list<Jamaah>  $anggota
     */
    private function kartu(string $kunci, array $inti, array $anggota): array
    {
        $kepala = collect($anggota)->first(fn (Jamaah $j) => $j->status_kk === 'kepala_keluarga');

        return [
            'kunci' => $kunci,
            'kode_keluarga' => $inti['kode'],
            'kepala_keluarga_id' => $kepala?->id,
            'kelompok_id' => ($kepala ?? $anggota[0])->kelompok_id,
            'anggota' => $anggota,
            'masalah' => $this->masalah($anggota, $kepala),
        ];
    }

    /**
     * Apa yang belum beres di keluarga ini. Inilah yang membedakan tampilan ini dari
     * daftar biasa: lubangnya menyebut dirinya sendiri, tidak perlu disisir satu-satu.
     *
     * @param  list<Jamaah>  $anggota
     * @return list<string>
     */
    private function masalah(array $anggota, ?Jamaah $kepala): array
    {
        $masalah = [];

        if (count($anggota) === 1 && $anggota[0]->kode_keluarga === null && $anggota[0]->kepala_keluarga_id === null) {
            $masalah[] = $anggota[0]->status_kk !== null && $anggota[0]->status_kk !== 'kepala_keluarga'
                ? 'Status KK sudah diisi tapi belum tersambung ke keluarga mana pun'
                : 'Belum masuk keluarga mana pun';

            return $masalah;
        }

        if (! $kepala) {
            $masalah[] = 'Belum ada yang berstatus Kepala Keluarga';
        }

        $tanpaStatus = collect($anggota)->filter(fn (Jamaah $j) => $j->status_kk === null)->count();
        if ($tanpaStatus > 0) {
            $masalah[] = $tanpaStatus.' anggota belum diisi status KK-nya';
        }

        return $masalah;
    }
}
