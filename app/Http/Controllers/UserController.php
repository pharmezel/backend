<?php

namespace App\Http\Controllers;
use App\Models\User;
use App\Models\RegistrationOtp;
use App\Models\ReferralLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    // get user info
    public function show($id)
    {
        try {

            $user = User::where('user_id', $id)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'user' => $user
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Fetch failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    // update user info
    public function update(Request $request, $id)
    {
        try {

            $user = User::where('user_id', $id)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            $validated = $request->validate([
                'first_name' => 'nullable',
                'last_name' => 'nullable',
                'email' => 'nullable|email',
                'contact_number' => 'nullable',
                'date_of_birth' => 'nullable|date',
            ]);

            $user->update($validated);
            $user->refresh();

            return response()->json([
                'message' => 'User updated successfully',
                'user' => $user
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    //delete user info
    public function destroy($id)
    {
        try {

            $user = User::where('user_id', $id)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            $user->delete();

            return response()->json([
                'message' => 'User deleted successfully'
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
   // send otp via email (create account here https://app.brevo.com/ and change the edit the .env)
   public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'first_name' => 'required',
            'last_name' => 'required',
            'date_of_birth' => 'required',
            'contact_number' => 'required',
            'password' => 'required',
            'referral_code' => 'required'
        ]);

        // check if valid referral code
        $referrer = User::where(
            'referral_code',
            $validated['referral_code']
        )->first();

        if (!$referrer) {

            return response()->json([
                'message' => 'Invalid referral code'
            ], 400);

        }

        // check if email already exist to prevent duplicate account
        $existing = User::where(
            'email',
            $validated['email']
        )->first();

        if ($existing) {

            return response()->json([
                'message' => 'Email already registered'
            ], 409);

        }

        // generate otp
        $code = rand(100000, 999999);

        RegistrationOtp::updateOrCreate(
            ['email' => $validated['email']],
            [
                'code' => $code,
                'form_data' => $validated,
                'expires_at' => now()->addMinutes(5)
            ]
        );

        // send email
        Mail::raw(
            "Your OTP is: $code",
            function ($message) use ($validated) {

                $message->to($validated['email'])
                    ->subject("OTP Verification");

            }
        );

        return response()->json([
            'message' => 'OTP sent successfully'
        ]);
    }
   
    // verify otp
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required'
        ]);

        // store otp and check if valid
        $otp = RegistrationOtp::where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Invalid OTP'
            ], 400);
        }

        if (now()->gt($otp->expires_at)) {
            return response()->json([
                'message' => 'OTP expired'
            ], 400);
        }

        $data = $otp->form_data;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data)) {
            return response()->json([
                'message' => 'Corrupted form data'
            ], 500);
        }

        
        $referrer = null;

        if (!empty($data['referral_code'])) {

            $referrer = User::where(
                'referral_code',
                $data['referral_code']
            )->first();

        }

        // create user account
        $user = User::create([
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'contact_number' => $data['contact_number'] ?? '',
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'referrer_code_used' => $data['referral_code'] ?? null
        ]);



        if ($referrer) {

            // link user to the referral code owner
            ReferralLink::create([
                'referrer_id' => $referrer->user_id,
                'referred_id' => $user->user_id,
                'status' => 'active'
            ]);

        }

        $otp->delete();

        return response()->json([
            'message' => 'Account created successfully',
            'user_id' => $user->user_id,
            'user' => $user
        ]);
    }
}