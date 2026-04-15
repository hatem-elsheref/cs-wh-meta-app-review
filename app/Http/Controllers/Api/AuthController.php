<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function tokenExpiresAt()
    {
        $minutes = config('sanctum.expiration');
        if ($minutes === null || $minutes === '' || (int) $minutes <= 0) {
            return null;
        }

        return now()->addMinutes((int) $minutes);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'approved') {
            throw ValidationException::withMessages([
                'email' => ['Your account is ' . $user->status . '. Please contact admin for approval.'],
            ]);
        }

        $expiresAt = $this->tokenExpiresAt();
        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        if ($current) {
            $current->delete();
        }

        $expiresAt = $this->tokenExpiresAt();
        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    public function me(Request $request)
    {
        if ($request->user()->status !== 'approved') {
            $request->user()->currentAccessToken()->delete();
            return response()->json([
                'message' => 'Your account is no longer active.',
            ], 403);
        }

        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'agent',
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Registration successful. Your account is under review and requires admin approval.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
        ], 201);
    }
}