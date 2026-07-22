<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Jamaah;
use App\Models\JamaahFaceDescriptor;
use App\Models\JamaahPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JamaahController extends Controller
{
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
            'kategori_usia' => ['required', 'in:paud_tk,caberawit,praremaja,remaja,usman'],
            'pekerjaan' => ['nullable', 'string', 'max:255'],
            'status_mubaligh' => ['boolean'],
            'sudah_menikah' => ['boolean'],
            'status_kk' => ['nullable', 'in:' . implode(',', self::STATUS_KK)],
            'kepala_keluarga_id' => ['nullable', 'exists:jamaahs,id'],
            'aktif' => ['boolean'],
            'keterangan_tidak_aktif' => ['nullable', 'string'],
        ];
    }

    /** Kepala keluarga adalah rujukan keluarganya sendiri — gak boleh sekaligus jadi anggota keluarga lain. */
    private function assertKepalaKeluarga(array $data, ?int $selfId = null): void
    {
        if (($data['status_kk'] ?? null) === 'kepala_keluarga') {
            abort_if(! empty($data['kepala_keluarga_id']), 422, 'Kepala keluarga tidak bisa sekaligus tercatat sebagai anggota keluarga lain');

            return;
        }

        if (empty($data['kepala_keluarga_id'])) {
            return;
        }

        abort_if($data['kepala_keluarga_id'] === $selfId, 422, 'Kepala keluarga tidak boleh menunjuk diri sendiri');

        $target = Jamaah::find($data['kepala_keluarga_id']);
        abort_if($target?->status_kk !== 'kepala_keluarga', 422, 'Kepala keluarga yang dipilih harus berstatus KK "Kepala Keluarga"');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Jamaah::visibleTo($request->user())
            ->with('kelompok:id,nama,desa_id', 'kelompok.desa:id,nama,daerah_id')
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
        if ($request->filled('search')) {
            $query->where('nama_lengkap', 'like', '%' . $request->string('search') . '%');
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $query->paginate($request->integer('per_page', 25)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->assertKepalaKeluarga($data);

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
        $this->assertKepalaKeluarga($data, $jamaah->id);

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
