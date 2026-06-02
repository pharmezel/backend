<?php

namespace App\Http\Controllers;

use App\Models\ReferralLink;
use App\Models\RegistrationOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Support\PharmCareMail;
use App\Support\ProductApiTransform;

/**
 * Handles unauthenticated and session lifecycle endpoints.
 *
 * Public: login (email or Philippine mobile), forgot/reset password.
 * Authenticated: logout (revokes current Sanctum token).
 * Login accepts email or `09XXXXXXXXX` phone; returns role, referral code, and points.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginId = trim($validated['email']);
        $phone = preg_replace('/\D/', '', $loginId);
        $isEmail = filter_var($loginId, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = preg_match('/^09\d{9}$/', $phone);

        if (! $isEmail && ! $isPhone) {
            return response()->json([
                'message' => 'Enter a valid email address or Philippine mobile number (09XXXXXXXXX).',
            ], 422);
        }

        $user = null;
        if ($isEmail) {
            $user = User::where('email', strtolower($loginId))->first();
        } else {
            $user = User::where('contact_number', $phone)->first();
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user_id' => $user->user_id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'contact_number' => $user->contact_number,
            'profile_image_url' => ProductApiTransform::imageUrl($user->profile_image),
            'role' => $user->role,
            'referral_code' => $user->referral_code,
            'points' => $user->points,
            'referrer_user_id' => ReferralLink::where('referred_id', $user->user_id)->value('referrer_id'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json(['message' => 'If that email exists, a code was sent.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        RegistrationOtp::updateOrCreate(
            ['email' => $email],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(15),
            ]
        );

        try {
            PharmCareMail::sendOtp($email, $code, 'password_reset', 15);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not send email. Please try again later.',
            ], 500);
        }

        return response()->json(['message' => 'If that email exists, a code was sent.']);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $email = strtolower(trim($validated['email']));

        $code = preg_replace('/\D/', '', (string) $validated['code']);
        if (strlen($code) !== 6) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $otp = RegistrationOtp::where('email', $email)
            ->where('code', $code)
            ->where('expires_at', '>', now())
            ->first();

        if (! $otp) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['password' => $validated['password']]);
        $otp->delete();

        return response()->json(['message' => 'Password reset successfully. Please log in.']);
    }
}
