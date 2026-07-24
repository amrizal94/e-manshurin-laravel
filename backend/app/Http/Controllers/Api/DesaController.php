<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesStruktur;
use App\Http\Controllers\Controller;
use App\Models\Desa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesaController extends Controller
{
    use ScopesStruktur;

    public function index(Request $request): JsonResponse
    {
        $query = Desa::visibleTo($request->user())->with('daerah:id,nama')->withCount('kelompoks')->orderBy('nama');

        if ($request->filled('daerah_id')) {
            $query->where('daerah_id', $request->integer('daerah_id'));
        }

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'daerah_id' => ['required', 'exists:daerahs,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('desas')->where('daerah_id', $request->daerah_id)],
        ]);
        abort_unless($this->targetWithinScope($request->user(), $data), 403, 'Target struktur di luar wilayah akun Anda');

        return response()->json(['success' => true, 'message' => 'Desa dibuat', 'data' => Desa::create($data)], 201);
    }

    public function update(Request $request, Desa $desa): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->targetWithinScope($user, ['desa_id' => $desa->id]), 403);

        $data = $request->validate([
            'daerah_id' => ['required', 'exists:daerahs,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('desas')->where('daerah_id', $request->daerah_id)->ignore($desa->id)],
        ]);

        $isSuperAdmin = ! $user->daerah_id && ! $user->desa_id && ! $user->kelompok_id;
        // Non-super-admin tak boleh pindahkan desa ke daerah lain, cuma boleh ubah nama.
        abort_unless($isSuperAdmin || $data['daerah_id'] == $desa->daerah_id, 403, 'Tidak bisa memindahkan desa ke daerah lain');

        $desa->update($data);

        return response()->json(['success' => true, 'message' => 'Desa diperbarui', 'data' => $desa]);
    }

    public function destroy(Request $request, Desa $desa): JsonResponse
    {
        abort_unless($this->targetWithinScope($request->user(), ['desa_id' => $desa->id]), 403);
        $desa->delete();

        return response()->json(['success' => true, 'message' => 'Desa dihapus', 'data' => null]);
    }
}
