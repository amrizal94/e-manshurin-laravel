<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            activity()->withProperties(['email' => $data['email']])->log('Percobaan login gagal');

            return response()->json(['success' => false, 'message' => 'Email atau password salah', 'data' => null], 401);
        }

        activity()->causedBy($user)->log('Login');

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => $user->load('roles:id,name'),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $request->user()->load('roles:id,name', 'daerah', 'desa', 'kelompok'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        activity()->causedBy($request->user())->log('Logout');
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logout berhasil', 'data' => null]);
    }
}
