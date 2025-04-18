<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Login
    public function login(Request $request)
{
    $credentials = $request->only('phone_number', 'password');

    // Validar si el usuario existe
    $user = User::where('phone_number', $credentials['phone_number'])->first();

    // Verificar la contraseña
    if ($user && Hash::check($credentials['password'], $user->password)) {
        // Generar el token
        $token = JWTAuth::fromUser($user);

        // Retornar token y datos básicos del usuario
        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'phone_number' => $user->phone_number,
                'name' => $user->name,
                'permission' => explode(',', $user->permission),
            ]
        ]);
    }

    return response()->json([
        'error' => 'Credenciales inválidas'
    ], 401);
}

    // Refresh token
    public function refresh()
    {
        try {
            $token = JWTAuth::parseToken()->refresh();
            return response()->json(['token' => $token]);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token is invalid or expired'], 401);
        }
    }

    // Logout
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['message' => 'Successfully logged out']);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Failed to logout'], 500);
        }
    }
}
