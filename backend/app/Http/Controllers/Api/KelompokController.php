<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesStruktur;
use App\Http\Controllers\Controller;
use App\Models\Kelompok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KelompokController extends Controller
{
    use ScopesStruktur;

    public function index(Request $request): JsonResponse
    {
        $query = Kelompok::visibleTo($request->user())->with('desa:id,nama,daerah_id')->orderBy('nama');

        if ($request->filled('desa_id')) {
            $query->where('desa_id', $request->integer('desa_id'));
        }

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desa_id' => ['required', 'exists:desas,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('kelompoks')->where('desa_id', $request->desa_id)],
        ]);
        abort_unless($this->targetWithinScope($request->user(), $data), 403, 'Target struktur di luar wilayah akun Anda');

        return response()->json(['success' => true, 'message' => 'Kelompok dibuat', 'data' => Kelompok::create($data)], 201);
    }

    public function update(Request $request, Kelompok $kelompok): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->targetWithinScope($user, ['kelompok_id' => $kelompok->id]), 403);

        $data = $request->validate([
            'desa_id' => ['required', 'exists:desas,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('kelompoks')->where('desa_id', $request->desa_id)->ignore($kelompok->id)],
        ]);

        $isSuperAdmin = ! $user->daerah_id && ! $user->desa_id && ! $user->kelompok_id;
        // Non-super-admin tak boleh pindahkan kelompok ke desa lain, cuma boleh ubah nama.
        abort_unless($isSuperAdmin || $data['desa_id'] == $kelompok->desa_id, 403, 'Tidak bisa memindahkan kelompok ke desa lain');

        $kelompok->update($data);

        return response()->json(['success' => true, 'message' => 'Kelompok diperbarui', 'data' => $kelompok]);
    }

    public function destroy(Request $request, Kelompok $kelompok): JsonResponse
    {
        abort_unless($this->targetWithinScope($request->user(), ['kelompok_id' => $kelompok->id]), 403);
        $kelompok->delete();

        return response()->json(['success' => true, 'message' => 'Kelompok dihapus', 'data' => null]);
    }
}
