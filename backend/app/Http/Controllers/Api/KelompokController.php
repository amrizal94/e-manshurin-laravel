<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelompok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KelompokController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Kelompok::with('desa:id,nama,daerah_id')->orderBy('nama');

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

        return response()->json(['success' => true, 'message' => 'Kelompok dibuat', 'data' => Kelompok::create($data)], 201);
    }

    public function update(Request $request, Kelompok $kelompok): JsonResponse
    {
        $data = $request->validate([
            'desa_id' => ['required', 'exists:desas,id'],
            'nama' => ['required', 'string', 'max:255', Rule::unique('kelompoks')->where('desa_id', $request->desa_id)->ignore($kelompok->id)],
        ]);
        $kelompok->update($data);

        return response()->json(['success' => true, 'message' => 'Kelompok diperbarui', 'data' => $kelompok]);
    }

    public function destroy(Kelompok $kelompok): JsonResponse
    {
        $kelompok->delete();

        return response()->json(['success' => true, 'message' => 'Kelompok dihapus', 'data' => null]);
    }
}
