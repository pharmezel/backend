<?php

namespace App\Http\Controllers;

use App\Models\ReferralLink;
use App\Models\RegistrationOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function formatPublicProfile(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'role' => $user->role,
            'referral_code' => $user->referral_code,
            'referrer_code_used' => $user->referrer_code_used,
            'referrer_user_id' => ReferralLink::where('referred_id', $user->user_id)->value('referrer_id'),
            'points' => $user->points,
            'shipping_address' => $user->shipping_address,
            'date_registered' => $user->date_registered?->format('Y-m-d H:i:s'),
        ];
    }

    public function show(Request $request, $id)
    {
        $auth = $request->user();
        if ($auth->role !== 'superadmin' && (int) $auth->user_id !== (int) $id) {
            $isReferrer = ReferralLink::where('referred_id', $auth->user_id)
                ->where('referrer_id', $id)
                ->exists();
            if (! $isReferrer) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $user = User::where('user_id', $id)->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        return response()->json($this->formatPublicProfile($user));
    }

    public function update(Request $request, $id)
    {
        if ((int) $request->user()->user_id !== (int) $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::where('user_id', $id)->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'contact_number' => 'sometimes|nullable|string|max:255',
            'date_of_birth' => 'sometimes|nullable|date',
            'shipping_address' => 'sometimes|nullable|string',
        ]);

        $user->update($validated);
        $user->refresh();

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $this->formatPublicProfile($user),
        ]);
    }

    public function updateShippingAddress(Request $request, $id)
    {
        if ((int) $request->user()->user_id !== (int) $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::where('user_id', $id)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'shipping_address' => 'required|string',
        ]);

        $user->update(['shipping_address' => $validated['shipping_address']]);
        $user->refresh();

        return response()->json([
            'message' => 'Shipping address updated',
            'user' => $this->formatPublicProfile($user),
        ]);
    }

    public function destroy($id)
    {
        $user = User::where('user_id', $id)->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Buyer signup step 1 — OTP via Brevo (configure MAIL_* in .env for smtp).
     */
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'date_of_birth' => 'required|date',
            'contact_number' => 'required|string',
            'password' => 'required|string',
            'referral_code' => 'required|string',
        ]);

        $referrer = User::findByReferralCode($validated['referral_code']);
        if (! $referrer) {
            return response()->json([
                'message' => 'Invalid referral code',
            ], 400);
        }

        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'Email already registered',
            ], 409);
        }

        if (User::where('contact_number', $validated['contact_number'])->exists()) {
            return response()->json([
                'message' => 'Contact number already registered',
            ], 409);
        }

        $code = (string) random_int(100000, 999999);

        RegistrationOtp::updateOrCreate(
            ['email' => $validated['email']],
            [
                'code' => $code,
                'form_data' => $validated,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        try {
            Mail::raw(
                "Your OTP is: {$code}",
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('OTP Verification');
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Something went wrong',
            ], 500);
        }

        return response()->json([
            'message' => 'OTP sent',
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        $otp = RegistrationOtp::where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (! $otp) {
            return response()->json([
                'message' => 'Invalid OTP',
            ], 400);
        }

        if (now()->gt($otp->expires_at)) {
            return response()->json([
                'message' => 'OTP expired',
            ], 400);
        }

        $data = $otp->form_data;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! is_array($data)) {
            return response()->json([
                'message' => 'Something went wrong',
            ], 500);
        }

        $referrer = User::findByReferralCode($data['referral_code'] ?? '');
        if (! $referrer) {
            return response()->json([
                'message' => 'Invalid referral code',
            ], 400);
        }

        if (User::where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'Email already registered',
            ], 409);
        }

        if (User::where('contact_number', $data['contact_number'] ?? '')->exists()) {
            return response()->json([
                'message' => 'Contact number already registered',
            ], 409);
        }

        $user = null;
        $plainToken = null;

        DB::transaction(function () use ($data, $referrer, $otp, &$user, &$plainToken) {
            $user = User::create([
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'contact_number' => $data['contact_number'] ?? '',
                'email' => $data['email'],
                'password' => $data['password'],
                'referrer_code_used' => strtoupper(trim($data['referral_code'] ?? '')),
                'role' => 'buyer',
                'points' => 0,
                'referral_code' => null,
                'date_registered' => now(),
            ]);

            ReferralLink::updateOrCreate(
                ['referred_id' => $user->user_id],
                [
                    'referrer_id' => $referrer->user_id,
                    'status' => 'active',
                ]
            );

            $otp->delete();

            $plainToken = $user->createToken('mobile')->plainTextToken;
        });

        return response()->json([
            'message' => 'Account created successfully',
            'token' => $plainToken,
            'user_id' => $user->user_id,
            'role' => $user->role,
            'referral_code' => $user->referral_code,
            'points' => $user->points,
            'referrer_user_id' => ReferralLink::where('referred_id', $user->user_id)->value('referrer_id'),
        ]);
    }

    public function becomeAdmin(Request $request, $id)
    {
        $authId = (int) $request->user()->user_id;
        if ($authId !== (int) $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::where('user_id', $id)->first();
        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        if ($user->role !== 'buyer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $user->referral_code) {
            $user->referral_code = User::generateUniqueReferralCode();
        }

        $user->role = 'admin';
        $user->save();

        return response()->json([
            'message' => 'Role updated successfully',
            'role' => $user->role,
            'referral_code' => $user->referral_code,
        ]);
    }
}
