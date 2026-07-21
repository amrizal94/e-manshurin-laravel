<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Desa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Desa::with('daerah:id,nama')->withCount('kelompoks')->orderBy('nama');

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

        return response()->json(['success' => true, 'message' => 'Desa dibuat', 'data' => Desa::create($data)], 201);
    }

    public function update(Request $request, Desa $desa): JsonResponse
    {
        $data = $request->validate([
            'daerah_id' => ['required', 'exists:daerahs,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('desas')->where('daerah_id', $request->daerah_id)->ignore($desa->id)],
        ]);
        $desa->update($data);

        return response()->json(['success' => true, 'message' => 'Desa diperbarui', 'data' => $desa]);
    }

    public function destroy(Desa $desa): JsonResponse
    {
        $desa->delete();

        return response()->json(['success' => true, 'message' => 'Desa dihapus', 'data' => null]);
    }
}
