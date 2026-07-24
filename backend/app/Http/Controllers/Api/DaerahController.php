<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesStruktur;
use App\Http\Controllers\Controller;
use App\Models\Daerah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DaerahController extends Controller
{
    use ScopesStruktur;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => Daerah::visibleTo($request->user())->withCount('desas')->orderBy('nama')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->daerah_id || $user->desa_id || $user->kelompok_id, 403, 'Hanya super admin yang boleh membuat daerah baru');

        $data = $request->validate(['nama' => ['required', 'string', 'max:255', 'unique:daerahs,nama']]);

        return response()->json([
            'success' => true,
            'message' => 'Daerah dibuat',
            'data' => Daerah::create($data),
        ], 201);
    }

    public function update(Request $request, Daerah $daerah): JsonResponse
    {
        abort_unless($this->targetWithinScope($request->user(), ['daerah_id' => $daerah->id]), 403);

        $data = $request->validate(['nama' => ['required', 'string', 'max:255', 'unique:daerahs,nama,' . $daerah->id]]);
        $daerah->update($data);

        return response()->json(['success' => true, 'message' => 'Daerah diperbarui', 'data' => $daerah]);
    }

    public function destroy(Request $request, Daerah $daerah): JsonResponse
    {
        abort_unless($this->targetWithinScope($request->user(), ['daerah_id' => $daerah->id]), 403);
        $daerah->delete();

        return response()->json(['success' => true, 'message' => 'Daerah dihapus', 'data' => null]);
    }
}
