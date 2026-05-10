<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\ReferralLink;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $email = strtolower(trim($request->email));
        $password = $request->password;

        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 401);
        }

        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Invalid password'
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'role' => $user->role,
            'user_id' => $user->user_id,
        ]);
    }
}