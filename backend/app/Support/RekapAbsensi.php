<?php

namespace App\Support;

use App\Models\Absensi;
use App\Models\Kegiatan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Matriks rekap: baris = jamaah, kolom = kegiatan dalam rentang tanggal.
 * Status per sel: hadir/izin/alpha (alpha = berhak hadir tapi tak ada catatan).
 *
 * Urutan baris dan hitungan H/I/A dikerjakan di sini, bukan di halamannya, karena
 * angka yang sama harus keluar di layar, di berkas Excel, dan di cetakan. Dua salinan
 * aturan yang sama pasti lambat laun berbeda, dan yang ketahuan belakangan justru
 * cetakan yang sudah terlanjur dibagikan.
 */
class RekapAbsensi
{
    /**
     * Urutan baris: dewasa dulu, turun sampai yang paling muda. Sengaja beda dengan
     * urutan daftar pilihan yang muda-ke-tua — ini urutan membaca laporan, dan yang
     * dicari lebih dulu di laporan biasanya jamaah dewasanya.
     */
    public const URUTAN_KATEGORI = [
        'menikah', 'janda', 'duda', 'usman', 'remaja', 'praremaja', 'caberawit', 'paud_tk',
    ];

    /**
     * Jenis pengajian yang benar-benar ada kegiatannya di rentang itu, urut tanggal
     * pertamanya. Dipakai berkas Excel untuk memecah satu sheet per jenis.
     *
     * @return list<string>
     */
    public static function jenisDalamRentang(User $actor, string $dari, string $sampai, ?string $hanya = null): array
    {
        return Kegiatan::visibleTo($actor)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->when($hanya, fn ($q, $jenis) => $q->where('jenis_pengajian', $jenis))
            ->orderBy('tanggal')
            ->pluck('jenis_pengajian')
            ->unique()
            ->values()
            ->all();
    }

    /** @var Collection<int, Kegiatan> */
    public Collection $kegiatans;

    /** @var Collection<int, array> */
    public Collection $rows;

    public function __construct(User $actor, string $dari, string $sampai, ?string $jenisPengajian = null)
    {
        $this->kegiatans = Kegiatan::visibleTo($actor)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->when($jenisPengajian, fn ($q, $jenis) => $q->where('jenis_pengajian', $jenis))
            ->orderBy('tanggal')
            ->get();

        $this->rows = $this->kegiatans->isEmpty() ? collect() : $this->susun($actor);
    }

    /** @return Collection<int, array> */
    private function susun(User $actor): Collection
    {
        // status tercatat: [jamaah_id][kegiatan_id] => status
        $tercatat = Absensi::whereIn('kegiatan_id', $this->kegiatans->pluck('id'))
            ->get()
            ->groupBy('jamaah_id')
            ->map(fn ($group) => $group->keyBy('kegiatan_id')->map->status);

        // peserta per kegiatan + kumpulan semua jamaah yang terlibat
        $pesertaPerKegiatan = [];
        $jamaahById = collect();
        foreach ($this->kegiatans as $kegiatan) {
            // pesertaQuery() menyaring menurut sasaran kegiatannya, bukan menurut siapa yang
            // melihat: pengajian desa pesertanya sedesa, dan tanpa ini akun kelompok ikut
            // membaca nama kelompok tetangga di rekapnya sendiri. Kolom pengajian desanya
            // tetap ada — yang disaring barisnya, bukan kegiatannya.
            //
            // Daftar absensi per kegiatan sengaja tidak disamakan: di sana petugas memang
            // perlu mencentang siapa pun yang datang. Laporan dan alat kerja beda keperluan.
            $peserta = $kegiatan->pesertaQuery()
                ->visibleTo($actor)
                ->with('kelompok:id,nama')
                ->get();

            $pesertaPerKegiatan[$kegiatan->id] = $peserta->pluck('id')->flip();
            $jamaahById = $jamaahById->union($peserta->keyBy('id'));
        }

        return $jamaahById
            ->map(fn ($jamaah) => $this->baris($jamaah, $tercatat, $pesertaPerKegiatan))
            ->sortBy([
                fn (array $a, array $b) => $this->urutan($a) <=> $this->urutan($b),
                fn (array $a, array $b) => $a['jamaah']['nama_lengkap'] <=> $b['jamaah']['nama_lengkap'],
            ])
            ->values();
    }

    /** Kategori di luar daftar jatuh ke paling bawah, bukan menyelip di tengah tanpa alasan. */
    private function urutan(array $baris): int
    {
        $i = array_search($baris['jamaah']['kategori_usia'], self::URUTAN_KATEGORI, true);

        return $i === false ? count(self::URUTAN_KATEGORI) : $i;
    }

    private function baris($jamaah, Collection $tercatat, array $pesertaPerKegiatan): array
    {
        $statuses = [];
        $jumlah = ['hadir' => 0, 'izin' => 0, 'alpha' => 0];
        $beruntun = 0;
        $terpanjang = 0;

        foreach ($this->kegiatans as $kegiatan) {
            if (! isset($pesertaPerKegiatan[$kegiatan->id][$jamaah->id])) {
                // null, bukan alpha: bukan pesertanya, jadi tidak berhutang kehadiran.
                $statuses[$kegiatan->id] = null;
                continue;
            }

            $status = $tercatat->get($jamaah->id)?->get($kegiatan->id) ?? 'alpha';
            $statuses[$kegiatan->id] = $status;
            $jumlah[$status]++;

            $beruntun = $status === 'alpha' ? $beruntun + 1 : 0;
            $terpanjang = max($terpanjang, $beruntun);
        }

        return [
            'jamaah' => [
                'id' => $jamaah->id,
                'nama_lengkap' => $jamaah->nama_lengkap,
                'kelompok' => $jamaah->kelompok?->nama,
                'kategori_usia' => $jamaah->kategori_usia,
            ],
            'statuses' => $statuses,
            'jumlah' => $jumlah,
            'perlu_perhatian' => $terpanjang >= 3,
        ];
    }

    /** @return Collection<int, array> */
    public function kegiatansRingkas(): Collection
    {
        return $this->kegiatans->map(fn (Kegiatan $k) => [
            'id' => $k->id,
            'nama' => $k->nama,
            'tanggal' => $k->tanggal->toDateString(),
            'jenis_pengajian' => $k->jenis_pengajian,
        ]);
    }
}
