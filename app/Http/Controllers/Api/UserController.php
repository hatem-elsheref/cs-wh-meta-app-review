<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private function ensureNotAdmin(User $user)
    {
        if ($user->role === 'admin') {
            return response()->json([
                'error' => 'Admin users cannot be viewed or modified via this endpoint',
            ], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        // Hide admin users from the list.
        $query = User::query()
            ->where('role', '!=', 'admin')
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $users = $query->paginate(20);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role' => 'sometimes|in:admin,agent',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'agent',
            'status' => 'approved',
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
        ], 201);
    }

    public function show(int $id)
    {
        $user = User::findOrFail($id);
        if ($resp = $this->ensureNotAdmin($user)) {
            return $resp;
        }

        return response()->json([
            'data' => $user,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        if ($resp = $this->ensureNotAdmin($user)) {
            return $resp;
        }

        $data = $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|min:8',
            'role' => 'sometimes|in:admin,agent',
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }

    public function destroy(int $id)
    {
        $user = User::findOrFail($id);
        if ($resp = $this->ensureNotAdmin($user)) {
            return $resp;
        }
        
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    public function approve(int $id)
    {
        $user = User::findOrFail($id);
        if ($resp = $this->ensureNotAdmin($user)) {
            return $resp;
        }
        $user->update(['status' => 'approved']);

        return response()->json([
            'message' => 'User approved successfully',
            'user' => $user,
        ]);
    }

    public function reject(int $id)
    {
        $user = User::findOrFail($id);
        if ($resp = $this->ensureNotAdmin($user)) {
            return $resp;
        }
        
        $user->update(['status' => 'rejected']);

        return response()->json([
            'message' => 'User rejected successfully',
            'user' => $user,
        ]);
    }
}