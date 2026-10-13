<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kegiatan;
use App\Support\Label;
use App\Support\RekapAbsensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RekapController extends Controller
{
    /** Status di berkas disingkat: satu huruf per sel supaya kolomnya tidak melebar saat dicetak. */
    private const SINGKAT = ['hadir' => 'H', 'izin' => 'I', 'alpha' => 'A'];

    public function index(Request $request): JsonResponse
    {
        $rekap = $this->rekap($request);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'kegiatans' => $rekap->kegiatansRingkas(),
                'rows' => $rekap->rows,
            ],
        ]);
    }

    /**
     * Satu sheet per jenis pengajian.
     *
     * Kalau semua kolom bertuliskan "Caberawit", kata itu bukan milik kolomnya — itu milik
     * seluruh lembarnya. Dinaikkan jadi nama sheet, judul kolomnya tinggal tanggal, dan
     * tiap lembar cuma memuat orang yang memang peserta jenis pengajian itu. Sel kosong
     * untuk yang bukan pesertanya hilang dengan sendirinya, dan itu yang paling terasa
     * waktu dicetak.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $data = $this->validasi($request);
        $semuaJenis = RekapAbsensi::jenisDalamRentang(
            $request->user(), $data['dari'], $data['sampai'], $data['jenis_pengajian'] ?? null
        );

        $opsi = new XlsxOptions;
        $opsi->setColumnWidth(28, 1);         // nama
        $opsi->setColumnWidthForRange(18, 2, 3); // kelompok, kategori
        // Lebar kolom berlaku untuk seluruh berkas, sementara tiap sheet beda jumlah
        // kolomnya. Disetel sampai kolom ke-60 sekalian — kolom yang tidak terpakai
        // tidak merugikan siapa pun.
        $opsi->setColumnWidthForRange(11, 4, 60);
        // Kolomnya banyak dan cuma selebar satu huruf: mendatar, dan dipaksa muat satu
        // halaman ke samping supaya tidak ada tanggal yang terlempar ke lembar kedua.
        $opsi->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToWidth: 1));

        $actor = $request->user();

        return response()->streamDownload(function () use ($actor, $data, $semuaJenis, $opsi) {
            $writer = new XlsxWriter($opsi);
            $writer->openToFile('php://output');

            // Rentang tanpa kegiatan sama sekali: tetap terbitkan satu lembar berisi judul,
            // supaya yang membuka tahu berkasnya memang kosong, bukan gagal diunduh.
            if ($semuaJenis === []) {
                $this->tulisSheet($writer, 'Rekap', null);
                $writer->close();

                return;
            }

            foreach ($semuaJenis as $urutan => $jenis) {
                if ($urutan > 0) {
                    $writer->addNewSheetAndMakeItCurrent();
                }

                $this->tulisSheet(
                    $writer,
                    Label::jenisPengajian($jenis),
                    new RekapAbsensi($actor, $data['dari'], $data['sampai'], $jenis)
                );
            }

            $writer->close();
        }, $this->namaBerkas($request), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function tulisSheet(XlsxWriter $writer, string $nama, ?RekapAbsensi $rekap): void
    {
        $kegiatans = $rekap?->kegiatans ?? collect();

        $writer->getCurrentSheet()->setName($nama);
        // Judul membeku: di gulungan kelima tidak ada lagi yang ingat kolom ini tanggal berapa.
        $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('B'));

        $writer->addRow(Row::fromValues([
            'Nama', 'Kelompok', 'Kategori',
            ...$kegiatans->map(fn (Kegiatan $k) => $this->judulKolom($k, $kegiatans)),
            'H', 'I', 'A', 'Perlu Perhatian',
        ], (new Style)->setFontBold()));

        foreach ($rekap?->rows ?? collect() as $baris) {
            $writer->addRow(Row::fromValues([
                $baris['jamaah']['nama_lengkap'],
                $baris['jamaah']['kelompok'] ?? '',
                Label::kategoriUsia($baris['jamaah']['kategori_usia']),
                // Sel kosong berarti bukan peserta kegiatan itu, bukan berarti tidak hadir.
                ...$kegiatans->map(
                    fn (Kegiatan $k) => self::SINGKAT[$baris['statuses'][$k->id] ?? ''] ?? ''
                ),
                $baris['jumlah']['hadir'],
                $baris['jumlah']['izin'],
                $baris['jumlah']['alpha'],
                $baris['perlu_perhatian'] ? 'Ya' : '',
            ]));
        }
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'jenis_pengajian' => ['nullable', 'in:'.implode(',', array_keys(Kegiatan::KATEGORI_MAP))],
        ]);
    }

    private function rekap(Request $request): RekapAbsensi
    {
        $data = $this->validasi($request);

        return new RekapAbsensi(
            $request->user(),
            $data['dari'],
            $data['sampai'],
            $data['jenis_pengajian'] ?? null,
        );
    }

    /**
     * Tanggalnya saja — jenisnya sudah jadi nama sheet, dan menuliskannya lagi di tiap
     * kolom cuma memakan lebar tanpa membedakan apa pun.
     *
     * Lebar kolom cetakan ditentukan isi terpanjang, dan yang terpanjang justru judulnya:
     * "2026-09-18 PENGAJIAN IBU2 PENGURUS TINGKAT DESA" membuat kolom tetap melebar walau
     * selnya cuma berisi "A". Nama kegiatan baru ikut kalau memang perlu membedakan, yaitu
     * saat ada dua kegiatan sejenis di tanggal yang sama.
     *
     * @param  \Illuminate\Support\Collection<int, Kegiatan>  $sekelas
     */
    private function judulKolom(Kegiatan $kegiatan, $sekelas): string
    {
        $tanggal = $kegiatan->tanggal->format('d/m');

        $kembar = $sekelas->filter(
            fn (Kegiatan $k) => $k->tanggal->isSameDay($kegiatan->tanggal)
        )->count() > 1;

        return $kembar ? $tanggal.' '.Str::limit($kegiatan->nama, 18) : $tanggal;
    }

    private function namaBerkas(Request $request): string
    {
        return "rekap-{$request->string('dari')}-sd-{$request->string('sampai')}.xlsx";
    }
}
