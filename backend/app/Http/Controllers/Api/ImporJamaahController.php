<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelompok;
use App\Support\ImporJamaah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function template(Request $request): Response
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

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $kolom, escape: '');
        fputcsv($csv, array_map(fn ($k) => $contoh[$k] ?? '', $kolom), escape: '');
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

    /** Hanya memeriksa dan melapor — tidak ada satu baris pun yang ditulis ke basis data. */
    public function periksa(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']],
            ['file.mimes' => 'File harus CSV. Di Excel: File → Save As → CSV.']
        );

        $hasil = (new ImporJamaah($request->user()))->periksa($request->file('file')->get());
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'lewati_kembar' => ['boolean'],
        ], ['file.mimes' => 'File harus CSV. Di Excel: File → Save As → CSV.']);

        $hasil = (new ImporJamaah($request->user()))->simpan(
            $request->file('file')->get(),
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
