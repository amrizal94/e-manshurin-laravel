<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelompok;
use App\Support\ImporJamaah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImporJamaahController extends Controller
{
    /** Contoh isi yang jelas-jelas contoh, supaya tidak ada yang lupa menghapusnya lalu tersimpan. */
    private const CONTOH = [
        'nama_lengkap' => 'CONTOH — hapus baris ini',
        'nama_panggilan' => 'Contoh',
        'jenis_kelamin' => 'L',
        'tempat_lahir' => 'Kediri',
        'tanggal_lahir' => '1990-11-30',
        'alamat' => 'Jl. Contoh No. 1',
        'no_hp' => '081234567890',
        'pekerjaan' => 'Wiraswasta',
        'kategori_usia' => 'menikah',
        'status_kk' => 'kepala_keluarga',
        'status_mubaligh' => 'tidak',
        'aktif' => 'ya',
        'keterangan_tidak_aktif' => '',
    ];

    /** Kolom templat, berikut satu baris contoh yang ejaan desa/kelompoknya nyata. */
    private function isiTemplate(Request $request): array
    {
        // Desa dan kelompok contohnya diambil dari wilayah akun ini — nama yang bisa langsung
        // disalin lebih berguna daripada placeholder yang harus ditebak ejaannya.
        $kelompok = Kelompok::visibleTo($request->user())->with('desa:id,nama')->orderBy('nama')->first();
        $kolom = [...ImporJamaah::WAJIB, ...ImporJamaah::OPSIONAL];
        $contoh = [
            ...self::CONTOH,
            'desa' => $kelompok?->desa?->nama ?? 'Nama Desa',
            'kelompok' => $kelompok?->nama ?? 'Nama Kelompok',
        ];

        return [$kolom, array_map(fn ($k) => $contoh[$k] ?? '', $kolom)];
    }

    /**
     * Templat .xlsx, dan ini yang sebaiknya dipakai: tanggal yang diketik di Excel sampai ke
     * sini sebagai tanggal betulan, jadi 30/11 tidak perlu ditebak urutannya, dan nol depan
     * nomor HP yang dibuang Excel bisa dikembalikan dengan pasti.
     */
    public function templateXlsx(Request $request): BinaryFileResponse
    {
        [$kolom, $contoh] = $this->isiTemplate($request);

        $opsi = new XlsxOptions;
        $opsi->setColumnWidthForRange(22, 1, count($kolom));

        $berkas = tempnam(sys_get_temp_dir(), 'impor');
        $writer = new XlsxWriter($opsi);
        $writer->openToFile($berkas);
        $writer->addRow(Row::fromValues($kolom, (new Style)->setFontBold()));
        $writer->addRow(Row::fromValues($contoh));
        $writer->close();

        return response()->download($berkas, 'template-impor-jamaah.xlsx')->deleteFileAfterSend();
    }

    public function template(Request $request): Response
    {
        [$kolom, $contoh] = $this->isiTemplate($request);

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $kolom, escape: '');
        fputcsv($csv, $contoh, escape: '');
        rewind($csv);

        // "sep=," bikin Excel yang pemisah bawaannya titik koma tetap memecah kolom dengan benar;
        // BOM bikin huruf beraksen tampil utuh. Keduanya diabaikan lagi saat file dibaca balik.
        $isi = "\u{FEFF}sep=,\r\n".stream_get_contents($csv);
        fclose($csv);

        return response($isi, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-impor-jamaah.csv"',
        ]);
    }

    /**
     * .xls lama (Excel 2003) sengaja tidak didukung: formatnya biner dan sama sekali berbeda
     * dari .xlsx, jadi pesannya menyebut jalan keluarnya, bukan cuma menolak.
     */
    private const ATURAN_BERKAS = ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'];

    private const PESAN_BERKAS = ['file.mimes' => 'File harus .xlsx atau .csv. Format .xls lama belum didukung — buka di Excel lalu Save As → Excel Workbook (.xlsx).'];

    /** Hanya memeriksa dan melapor — tidak ada satu baris pun yang ditulis ke basis data. */
    public function periksa(Request $request): JsonResponse
    {
        $request->validate(['file' => self::ATURAN_BERKAS], self::PESAN_BERKAS);

        $berkas = $request->file('file');
        $hasil = (new ImporJamaah($request->user()))->periksa($berkas->getRealPath(), $berkas->getClientOriginalExtension());
        abort_if($hasil['gagal'] !== null, 422, $hasil['gagal']);

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $hasil]);
    }

    /**
     * Berkasnya diunggah ulang, bukan disimpan dari hasil pemeriksaan tadi. Kalau hasil
     * pemeriksaan yang ditahan di sesi, data yang tersimpan bisa berbeda dari yang terakhir
     * dilihat petugas — dan ini juga menjaga pemeriksaan tetap benar-benar tanpa jejak.
     */
    public function simpan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => self::ATURAN_BERKAS,
            'lewati_kembar' => ['boolean'],
        ], self::PESAN_BERKAS);

        $berkas = $request->file('file');
        $hasil = (new ImporJamaah($request->user()))->simpan(
            $berkas->getRealPath(),
            $berkas->getClientOriginalExtension(),
            (bool) ($data['lewati_kembar'] ?? true),
        );

        return response()->json([
            'success' => true,
            'message' => "{$hasil['disimpan']} jamaah tersimpan",
            'data' => $hasil,
        ], 201);
    }

    public function batal(Request $request, string $imporId): JsonResponse
    {
        $dihapus = (new ImporJamaah($request->user()))->batal($imporId);

        return response()->json([
            'success' => true,
            'message' => "{$dihapus} jamaah hasil impor itu dihapus",
            'data' => ['dihapus' => $dihapus],
        ]);
    }
}
