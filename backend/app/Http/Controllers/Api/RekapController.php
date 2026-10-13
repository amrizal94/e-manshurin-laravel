<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kegiatan;
use App\Support\Label;
use App\Support\RekapAbsensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function ekspor(Request $request): StreamedResponse
    {
        $rekap = $this->rekap($request);

        $judul = [
            'Nama', 'Kelompok', 'Kategori',
            ...$rekap->kegiatans->map(fn (Kegiatan $k) => $this->judulKolom($k)),
            'H', 'I', 'A', 'Perlu Perhatian',
        ];

        $opsi = new XlsxOptions;
        $opsi->setColumnWidth(28, 1);                              // nama
        $opsi->setColumnWidthForRange(18, 2, 3);                   // kelompok, kategori
        $opsi->setColumnWidthForRange(11, 4, count($judul));       // kolom kegiatan + jumlah
        // Kolomnya banyak dan cuma selebar satu huruf: mendatar, dan dipaksa muat satu
        // halaman ke samping supaya tidak ada tanggal yang terlempar ke lembar kedua.
        $opsi->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToWidth: 1));

        return response()->streamDownload(function () use ($rekap, $judul, $opsi) {
            $writer = new XlsxWriter($opsi);
            $writer->openToFile('php://output');

            // Judul membeku: di gulungan kelima tidak ada lagi yang ingat kolom ini tanggal berapa.
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('B'));

            $writer->addRow(Row::fromValues($judul, (new Style)->setFontBold()));

            foreach ($rekap->rows as $baris) {
                $writer->addRow(Row::fromValues([
                    $baris['jamaah']['nama_lengkap'],
                    $baris['jamaah']['kelompok'] ?? '',
                    Label::kategoriUsia($baris['jamaah']['kategori_usia']),
                    // Sel kosong berarti bukan peserta kegiatan itu, bukan berarti tidak hadir.
                    ...$rekap->kegiatans->map(
                        fn (Kegiatan $k) => self::SINGKAT[$baris['statuses'][$k->id] ?? ''] ?? ''
                    ),
                    $baris['jumlah']['hadir'],
                    $baris['jumlah']['izin'],
                    $baris['jumlah']['alpha'],
                    $baris['perlu_perhatian'] ? 'Ya' : '',
                ]));
            }

            $writer->close();
        }, $this->namaBerkas($request), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function rekap(Request $request): RekapAbsensi
    {
        $data = $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'jenis_pengajian' => ['nullable', 'in:'.implode(',', array_keys(Kegiatan::KATEGORI_MAP))],
        ]);

        return new RekapAbsensi(
            $request->user(),
            $data['dari'],
            $data['sampai'],
            $data['jenis_pengajian'] ?? null,
        );
    }

    /**
     * Tanggal pendek plus jenis pengajiannya.
     *
     * Lebar kolom cetakan ditentukan isi terpanjang, dan yang terpanjang justru judulnya —
     * "2026-09-18 PENGAJIAN IBU2 PENGURUS TINGKAT DESA" membuat kolom tetap melebar walau
     * selnya cuma berisi "A". Jenisnya tetap dibawa supaya dua kegiatan di tanggal yang
     * sama masih bisa dibedakan.
     */
    private function judulKolom(Kegiatan $kegiatan): string
    {
        return $kegiatan->tanggal->format('d/m').' '.Label::jenisPengajian($kegiatan->jenis_pengajian);
    }

    private function namaBerkas(Request $request): string
    {
        return "rekap-{$request->string('dari')}-sd-{$request->string('sampai')}.xlsx";
    }
}
