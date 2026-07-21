<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Desa;
use App\Models\Kelompok;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private const ROLES = ['super_admin', 'admin', 'absensi'];

    /** Tepat satu (atau nol untuk super_admin) target struktur, dan harus di dalam scope actor. */
    private function assertScope(User $actor, array $data): void
    {
        $targets = array_filter([$data['daerah_id'] ?? null, $data['desa_id'] ?? null, $data['kelompok_id'] ?? null]);
        abort_if(count($targets) > 1, 422, 'Isi maksimal satu tingkat struktur (daerah, desa, atau kelompok)');

        if (! $actor->daerah_id && ! $actor->desa_id && ! $actor->kelompok_id) {
            return; // super admin bebas
        }

        abort_if(count($targets) === 0, 422, 'Wajib pilih satu tingkat struktur');

        $allowed = match (true) {
            (bool) $actor->kelompok_id => ($data['kelompok_id'] ?? null) == $actor->kelompok_id,
            (bool) $actor->desa_id => ($data['desa_id'] ?? null) == $actor->desa_id
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->where('desa_id', $actor->desa_id)->exists()),
            default => ($data['daerah_id'] ?? null) == $actor->daerah_id
                || (($data['desa_id'] ?? null) && Desa::where('id', $data['desa_id'])->where('daerah_id', $actor->daerah_id)->exists())
                || (($data['kelompok_id'] ?? null) && Kelompok::where('id', $data['kelompok_id'])->whereHas('desa', fn ($q) => $q->where('daerah_id', $actor->daerah_id))->exists()),
        };

        abort_unless($allowed, 403, 'Target struktur di luar wilayah akun Anda');
    }

    /** Hanya super_admin yang boleh membuat/mengubah akun super_admin lain. */
    private function assertRoleGrant(User $actor, string $role): void
    {
        if ($role === 'super_admin') {
            abort_unless($actor->hasRole('super_admin'), 403, 'Hanya super admin yang boleh menetapkan peran super admin');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::visibleTo($request->user())
            ->with('roles:id,name', 'daerah:id,nama', 'desa:id,nama', 'kelompok:id,nama')
            ->orderBy('name')
            ->get()
            ->makeVisible(['daerah_id', 'desa_id', 'kelompok_id']);

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:' . implode(',', self::ROLES)],
            'daerah_id' => ['nullable', 'exists:daerahs,id'],
            'desa_id' => ['nullable', 'exists:desas,id'],
            'kelompok_id' => ['nullable', 'exists:kelompoks,id'],
        ]);

        $this->assertScope($request->user(), $data);
        $this->assertRoleGrant($request->user(), $data['role']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'daerah_id' => $data['daerah_id'] ?? null,
            'desa_id' => $data['desa_id'] ?? null,
            'kelompok_id' => $data['kelompok_id'] ?? null,
        ]);
        $user->syncRoles([$data['role']]);

        return response()->json([
            'success' => true,
            'message' => 'Pengguna dibuat',
            'data' => $user->load('roles:id,name'),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless(User::visibleTo($request->user())->whereKey($user->id)->exists(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:' . implode(',', self::ROLES)],
            'daerah_id' => ['nullable', 'exists:daerahs,id'],
            'desa_id' => ['nullable', 'exists:desas,id'],
            'kelompok_id' => ['nullable', 'exists:kelompoks,id'],
        ]);

        $this->assertScope($request->user(), $data);
        $this->assertRoleGrant($request->user(), $data['role']);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'daerah_id' => $data['daerah_id'] ?? null,
            'desa_id' => $data['desa_id'] ?? null,
            'kelompok_id' => $data['kelompok_id'] ?? null,
            ...(! empty($data['password']) ? ['password' => Hash::make($data['password'])] : []),
        ]);
        $user->syncRoles([$data['role']]);

        return response()->json([
            'success' => true,
            'message' => 'Pengguna diperbarui',
            'data' => $user->fresh()->load('roles:id,name'),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless(User::visibleTo($request->user())->whereKey($user->id)->exists(), 403);
        abort_if($user->id === $request->user()->id, 422, 'Tidak bisa menghapus akun sendiri');

        $user->delete();

        return response()->json(['success' => true, 'message' => 'Pengguna dihapus', 'data' => null]);
    }
}
