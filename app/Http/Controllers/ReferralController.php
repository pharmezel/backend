<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ReferralLink;
use App\Models\User;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    //create referral
    public function createReferralCode($userId)
    {
        $user = User::where('user_id', $userId)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // if already has code → return it
        if ($user->referral_code) {
            return response()->json([
                'message' => 'Already has referral code',
                'referral_code' => $user->referral_code
            ]);
        }

        // generate unique code
        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        // save to SAME USER
        $user->update([
            'referral_code' => $code
        ]);

        return response()->json([
            'message' => 'Referral code created',
            'referral_code' => $code
        ]);
    }
}