<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesStruktur;
use App\Http\Controllers\Controller;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\JamaahPhoto;
use App\Models\User;
use App\Support\DaftarKeluarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JamaahController extends Controller
{
    use ScopesStruktur;

    private const STATUS_KK = ['kepala_keluarga', 'suami', 'istri', 'anak', 'menantu', 'cucu', 'orang_tua', 'mertua'];

    private function rules(): array
    {
        return [
            'kelompok_id' => ['required', 'exists:kelompoks,id'],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            'nama_panggilan' => ['nullable', 'string', 'max:255'],
            'jenis_kelamin' => ['required', 'in:L,P'],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date'],
            'alamat' => ['nullable', 'string'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'kategori_usia' => ['required', 'in:paud_tk,caberawit,praremaja,remaja,usman,menikah,janda,duda'],
            'pekerjaan' => ['nullable', 'string', 'max:255'],
            'status_mubaligh' => ['boolean'],
            'pengurus_4s' => ['boolean'],
            'status_kk' => ['nullable', 'in:'.implode(',', self::STATUS_KK)],
            'kode_keluarga' => ['nullable', 'string', 'max:50'],
            'kepala_keluarga_id' => ['nullable', 'exists:jamaahs,id'],
            'aktif' => ['boolean'],
            'keterangan_tidak_aktif' => ['nullable', 'string'],
        ];
    }

    /**
     * "Janda" dan "duda" sudah menyebut jenis kelaminnya sendiri. Kalau boleh berbeda dari
     * kolom jenis_kelamin, ada dua sumber kebenaran — dan pengajian ibu-ibu/bapak-bapak,
     * yang menyaring keduanya sekaligus, akan memanggil orang yang salah.
     */
    private function assertKategoriCocokKelamin(array $data): void
    {
        $harus = ['janda' => 'P', 'duda' => 'L'][$data['kategori_usia']] ?? null;

        abort_if(
            $harus && $data['jenis_kelamin'] !== $harus,
            422,
            $harus === 'P'
                ? 'Kategori "Janda" hanya untuk jamaah perempuan'
                : 'Kategori "Duda" hanya untuk jamaah laki-laki'
        );
    }

    /** Kepala keluarga adalah rujukan keluarganya sendiri — gak boleh sekaligus jadi anggota keluarga lain. */
    private function assertKepalaKeluarga(User $user, array $data, ?Jamaah $jamaah = null): void
    {
        if (($data['status_kk'] ?? null) === 'kepala_keluarga') {
            abort_if(! empty($data['kepala_keluarga_id']), 422, 'Kepala keluarga tidak bisa sekaligus tercatat sebagai anggota keluarga lain');

            return;
        }

        // Menurunkan status kepala keluarga bikin anggotanya menggantung: rujukan mereka
        // menunjuk orang yang bukan kepala keluarga lagi, jadi tidak lagi muncul sebagai
        // pilihan di form dan keluarganya terlihat terputus.
        if ($jamaah?->status_kk === 'kepala_keluarga') {
            $anggota = $jamaah->anggotaKeluarga()->count();
            abort_if($anggota > 0, 422, "Masih ada {$anggota} anggota keluarga yang menunjuk orang ini. Pindahkan mereka ke kepala keluarga lain dulu.");
        }

        if (empty($data['kepala_keluarga_id'])) {
            return;
        }

        abort_if($data['kepala_keluarga_id'] === $jamaah?->id, 422, 'Kepala keluarga tidak boleh menunjuk diri sendiri');

        // Dibatasi wilayah: daftar pilihan di form memang sudah tersaring, tapi id-nya
        // dikirim mentah dari klien, jadi jangan percaya begitu saja.
        $target = Jamaah::visibleTo($user)->find($data['kepala_keluarga_id']);
        abort_if($target?->status_kk !== 'kepala_keluarga', 422, 'Kepala keluarga yang dipilih harus ada di wilayah Anda dan berstatus KK "Kepala Keluarga"');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Jamaah::visibleTo($request->user())
            ->with('kelompok:id,nama,desa_id', 'kelompok.desa:id,nama,daerah_id', 'kepalaKeluarga:id,nama_lengkap')
            ->withCount('photos')
            ->orderBy('nama_lengkap');

        if ($request->filled('kelompok_id')) {
            $query->where('kelompok_id', $request->integer('kelompok_id'));
        }
        if ($request->filled('kategori_usia')) {
            $query->where('kategori_usia', $request->string('kategori_usia'));
        }
        if ($request->filled('aktif')) {
            $query->where('aktif', $request->boolean('aktif'));
        }
        if ($request->filled('status_kk')) {
            $query->where('status_kk', $request->string('status_kk'));
        }
        // Satu jamaah dikirim, seisi keluarganya yang kembali — itu gunanya filter ini.
        if ($request->filled('keluarga_id')) {
            $anggota = Jamaah::visibleTo($request->user())->find($request->integer('keluarga_id'));
            abort_unless($anggota, 404, 'Jamaah itu tidak ada di wilayah Anda');
            $query->sekeluargaDengan($anggota);
        }
        if ($request->filled('search')) {
            $keyword = '%'.mb_strtolower($request->string('search')).'%';
            // Kode keluarga ikut dicari lewat kotak yang sama: mengetik "KK-SUGENG-01"
            // memunculkan serumah sekaligus, tanpa perlu kotak pencarian kedua.
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(nama_lengkap) like ?', [$keyword])
                    ->orWhereRaw('LOWER(nama_panggilan) like ?', [$keyword])
                    ->orWhereRaw('LOWER(kode_keluarga) like ?', [$keyword]);
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $query->paginate($request->integer('per_page', 25)),
        ]);
    }

    /**
     * Daftar yang sama, dikelompokkan per keluarga: mengetik satu nama memunculkan
     * seisi rumahnya, berikut apa yang belum lengkap di situ.
     */
    public function keluarga(Request $request): JsonResponse
    {
        $hasil = (new DaftarKeluarga($request->user()))->ambil(
            $request->filled('search') ? (string) $request->string('search') : null,
            $request->filled('kelompok_id') ? $request->integer('kelompok_id') : null,
            $request->boolean('tanpa_keluarga'),
            max(1, $request->integer('page', 1)),
            min(100, max(1, $request->integer('per_page', 25))),
        );

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $hasil]);
    }

    /**
     * Menyambungkan beberapa jamaah sekaligus ke satu kepala keluarga.
     *
     * Empat orang serumah berarti empat kali buka-tutup form kalau dikerjakan satu-satu,
     * dan sesudah impor massal ada ribuan orang yang perlu disambungkan. Ini menjadikannya
     * satu tindakan.
     */
    public function sambungKeluarga(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jamaah_ids' => ['required', 'array', 'min:1', 'max:100'],
            'jamaah_ids.*' => ['integer'],
            'kepala_keluarga_id' => ['required', 'integer'],
            // kepala_keluarga sengaja tidak boleh: menyamakannya borongan berarti membuat
            // beberapa kepala keluarga sekaligus dalam satu rumah.
            'status_kk' => ['nullable', 'in:suami,istri,anak,menantu,cucu,orang_tua,mertua'],
        ]);

        $user = $request->user();
        $kepala = Jamaah::visibleTo($user)->find($data['kepala_keluarga_id']);
        abort_unless($kepala, 404, 'Kepala keluarga itu tidak ada di wilayah Anda');
        abort_unless($kepala->status_kk === 'kepala_keluarga', 422, 'Yang dipilih harus berstatus KK "Kepala Keluarga"');

        // id-nya datang mentah dari klien: yang di luar wilayah user harus jatuh dari
        // daftar sebelum apa pun ditulis, bukan sesudahnya.
        $target = Jamaah::visibleTo($user)
            ->whereIn('id', $data['jamaah_ids'])
            ->whereKeyNot($kepala->id)
            ->pluck('id');

        abort_if($target->isEmpty(), 422, 'Tidak ada jamaah yang bisa disambungkan.');

        // Kode keluarganya ikut kepala keluarga supaya pengelompokan lewat kode dan lewat
        // rujukan tidak berbeda isi.
        Jamaah::whereIn('id', $target)->update([
            'kepala_keluarga_id' => $kepala->id,
            'kode_keluarga' => $kepala->kode_keluarga,
            ...(($data['status_kk'] ?? null) !== null ? ['status_kk' => $data['status_kk']] : []),
            'updated_at' => now(),
        ]);

        activity()->causedBy($user)
            ->withProperties(['kepala_keluarga_id' => $kepala->id, 'jamaah_ids' => $target->all()])
            ->log('Sambungkan '.$target->count().' jamaah ke keluarga '.$kepala->nama_lengkap);

        return response()->json([
            'success' => true,
            'message' => $target->count().' jamaah disambungkan ke keluarga '.$kepala->nama_lengkap,
            'data' => ['disambungkan' => $target->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        abort_unless($this->targetWithinScope($request->user(), $data), 403, 'Kelompok di luar wilayah akun Anda');
        $this->assertKategoriCocokKelamin($data);
        $this->assertKepalaKeluarga($request->user(), $data);

        $jamaah = Jamaah::create($data);

        return response()->json(['success' => true, 'message' => 'Jamaah dibuat', 'data' => $jamaah], 201);
    }

    public function show(Request $request, Jamaah $jamaah): JsonResponse
    {
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $jamaah->load('kelompok.desa.daerah', 'kepalaKeluarga:id,nama_lengkap', 'photos'),
        ]);
    }

    public function update(Request $request, Jamaah $jamaah): JsonResponse
    {
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);

        $data = $request->validate($this->rules());
        abort_unless($this->targetWithinScope($request->user(), $data), 403, 'Kelompok di luar wilayah akun Anda');
        $this->assertKategoriCocokKelamin($data);
        $this->assertKepalaKeluarga($request->user(), $data, $jamaah);

        $jamaah->update($data);

        return response()->json(['success' => true, 'message' => 'Jamaah diperbarui', 'data' => $jamaah]);
    }

    public function destroy(Request $request, Jamaah $jamaah): JsonResponse
    {
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);
        Storage::disk('public')->delete($jamaah->photos()->pluck('path')->all());
        $jamaah->delete();

        return response()->json(['success' => true, 'message' => 'Jamaah dihapus', 'data' => null]);
    }

    public function storePhoto(Request $request, Jamaah $jamaah): JsonResponse
    {
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $path = $request->file('photo')->store("jamaah/{$jamaah->id}", 'public');
        $photo = $jamaah->photos()->create(['path' => $path]);

        return response()->json(['success' => true, 'message' => 'Foto diunggah', 'data' => $photo], 201);
    }

    public function destroyPhoto(Request $request, Jamaah $jamaah, JamaahPhoto $photo): JsonResponse
    {
        abort_unless($photo->jamaah_id === $jamaah->id, 404);
        abort_unless(Jamaah::visibleTo($request->user())->whereKey($jamaah->id)->exists(), 403);

        Storage::disk('public')->delete($photo->path);
        JamaahFaceDescriptor::where('jamaah_photo_id', $photo->id)->delete();
        $photo->delete();

        return response()->json(['success' => true, 'message' => 'Foto dihapus', 'data' => null]);
    }
}
