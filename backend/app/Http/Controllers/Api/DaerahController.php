<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Daerah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DaerahController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => Daerah::withCount('desas')->orderBy('nama')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:255', 'unique:daerahs,nama']]);

        return response()->json([
            'success' => true,
            'message' => 'Daerah dibuat',
            'data' => Daerah::create($data),
        ], 201);
    }

    public function update(Request $request, Daerah $daerah): JsonResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:255', 'unique:daerahs,nama,' . $daerah->id]]);
        $daerah->update($data);

        return response()->json(['success' => true, 'message' => 'Daerah diperbarui', 'data' => $daerah]);
    }

    public function destroy(Daerah $daerah): JsonResponse
    {
        $daerah->delete();

        return response()->json(['success' => true, 'message' => 'Daerah dihapus', 'data' => null]);
    }
}
