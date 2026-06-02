<?php

namespace App\Http\Controllers;

use App\Models\AdminUpgradeRequest;
use App\Models\ReferralLink;
use App\Models\RegistrationOtp;
use App\Models\User;
use Illuminate\Http\Request;
use App\Support\PharmCareMail;
use App\Support\ProductApiTransform;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * User registration, profiles, and role-upgrade workflow.
 *
 * Public (via routes): sendOtp / verifyOtp — email OTP registration with required referral code.
 * Authenticated: profile read/update, shipping address, password change, account deletion (superadmin).
 * Buyers may request upgrade to admin; admins/superadmins approve pending upgrade requests.
 * Users may only view or edit their own profile unless the caller is superadmin.
 */
class UserController extends Controller
{
    private function formatReferrer(?User $referrer): ?array
    {
        if (! $referrer) {
            return null;
        }

        return [
            'id' => $referrer->user_id,
            'name' => trim($referrer->first_name.' '.$referrer->last_name),
            'first_name' => $referrer->first_name,
            'last_name' => $referrer->last_name,
            'phone' => $referrer->contact_number,
            'email' => $referrer->email,
        ];
    }

    private function profileImageUrl(?string $path): ?string
    {
        return ProductApiTransform::imageUrl($path);
    }

    private function deleteProfileImage(?string $path): void
    {
        PublicStorage::delete($path);
    }

    private function formatPublicProfile(User $user): array
    {
        $user->loadMissing('referrer');

        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'profile_image_url' => $this->profileImageUrl($user->profile_image),
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'role' => $user->role,
            'referral_code' => $user->referral_code,
            'referrer_code_used' => $user->referrer_code_used,
            'referrer_user_id' => ReferralLink::where('referred_id', $user->user_id)->value('referrer_id'),
            'referrer' => $this->formatReferrer($user->referrer),
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

        $user = User::with('referrer')->where('user_id', $id)->first();

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
            'contact_number' => ['sometimes', 'nullable', 'regex:/^09\d{9}$/'],
            'date_of_birth' => 'sometimes|nullable|date',
            'shipping_address' => 'sometimes|nullable|string',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_profile_image' => 'sometimes|boolean',
        ], [
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
        ]);

        if ($request->boolean('remove_profile_image')) {
            $this->deleteProfileImage($user->profile_image);
            $user->profile_image = null;
        }

        if ($request->hasFile('profile_image')) {
            $this->deleteProfileImage($user->profile_image);
            $user->profile_image = PublicStorage::storeUploadedFile(
                $request->file('profile_image'),
                'profiles',
                'profile_image'
            );
        }

        $user->fill(collect($validated)->except(['profile_image', 'remove_profile_image'])->all());
        $user->save();
        $user->refresh();

        return response()->json([
            'message' => 'User updated successfully',
            'profile_image_url' => $this->profileImageUrl($user->profile_image),
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
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'password' => 'required|string',
            'referral_code' => 'required|string',
        ], [
            'contact_number.regex' => 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX).',
        ]);

        $validated['email'] = strtolower(trim($validated['email']));

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

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        RegistrationOtp::updateOrCreate(
            ['email' => $validated['email']],
            [
                'code' => $code,
                'form_data' => $validated,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        try {
            PharmCareMail::sendOtp($validated['email'], $code, 'registration', 5);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not send verification email. '.$e->getMessage(),
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
            'code' => 'required|string|min:6|max:6',
        ]);

        $email = strtolower(trim($request->input('email')));
        $code = preg_replace('/\D/', '', (string) $request->input('code'));
        if (strlen($code) !== 6) {
            return response()->json([
                'message' => 'Invalid OTP',
            ], 400);
        }

        $otp = RegistrationOtp::where('email', $email)
            ->where('code', $code)
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

        if ($otp->form_data === null) {
            return response()->json([
                'message' => 'Invalid OTP',
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

        $data['email'] = strtolower(trim($data['email'] ?? ''));

        if (User::where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'Email already registered',
            ], 409);
        }

        $contactNumber = $data['contact_number'] ?? '';
        if (! preg_match('/^09\d{9}$/', $contactNumber)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'contact_number' => ['Contact number must be a valid Philippine mobile number (09XXXXXXXXX).'],
                ],
            ], 422);
        }

        if (User::where('contact_number', $contactNumber)->exists()) {
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
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'contact_number' => $user->contact_number,
            'role' => $user->role,
            'referral_code' => $user->referral_code,
            'points' => $user->points,
            'referrer_user_id' => ReferralLink::where('referred_id', $user->user_id)->value('referrer_id'),
            'profile_image_url' => ProductApiTransform::imageUrl($user->profile_image),
        ]);
    }

    public function changePassword(Request $request, $id)
    {
        $user = $request->user();
        if ((string) $user->user_id !== (string) $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        $user->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function requestUpgrade(Request $request, $id)
    {
        $auth = $request->user();
        if ((int) $auth->user_id !== (int) $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($auth->role !== 'buyer') {
            return response()->json(['message' => 'Only buyers can request an upgrade.'], 422);
        }

        $referral = ReferralLink::where('referred_id', $auth->user_id)->first();
        if (! $referral) {
            return response()->json(['message' => 'No referral link found. Contact your referrer.'], 422);
        }

        $existing = AdminUpgradeRequest::where('requester_id', $auth->user_id)
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            return response()->json(['message' => 'You already have a pending upgrade request.'], 422);
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $upgradeRequest = AdminUpgradeRequest::create([
            'requester_id' => $auth->user_id,
            'approver_id' => $referral->referrer_id,
            'status' => 'pending',
            'requester_note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'Upgrade request submitted. Waiting for approval from your referrer.',
            'request' => [
                'id' => $upgradeRequest->id,
                'status' => $upgradeRequest->status,
            ],
        ]);
    }

    public function approveUpgrade(Request $request, $requestId)
    {
        $auth = $request->user();
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'note' => 'nullable|string|max:500',
        ]);

        $upgradeRequest = AdminUpgradeRequest::with('requester')
            ->where('id', $requestId)
            ->first();

        if (! $upgradeRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }
        if (
            $auth->role !== 'superadmin'
            && (int) $upgradeRequest->approver_id !== (int) $auth->user_id
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($upgradeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been '.$upgradeRequest->status.'.'], 422);
        }

        $upgradeRequest->update([
            'status' => $validated['action'],
            'approver_note' => $validated['note'] ?? null,
        ]);

        if ($validated['action'] === 'approved') {
            $buyer = $upgradeRequest->requester;
            if (! $buyer->referral_code) {
                $buyer->referral_code = User::generateUniqueReferralCode();
            }
            $buyer->role = 'admin';
            $buyer->save();
        }

        return response()->json([
            'message' => 'Request '.$validated['action'].'.',
            'status' => $validated['action'],
        ]);
    }

    public function myUpgradeRequest(Request $request)
    {
        $auth = $request->user();
        $req = AdminUpgradeRequest::where('requester_id', $auth->user_id)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $req) {
            return response()->json(['request' => null]);
        }

        return response()->json([
            'request' => [
                'id' => $req->id,
                'status' => $req->status,
                'requester_note' => $req->requester_note,
                'approver_note' => $req->approver_note,
                'created_at' => $req->created_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    private function upgradeRequestsBaseQuery(User $auth)
    {
        $query = AdminUpgradeRequest::query();
        if ($auth->role !== 'superadmin') {
            $query->where('approver_id', $auth->user_id);
        }

        return $query;
    }

    private function formatUpgradeRequest(AdminUpgradeRequest $r): array
    {
        $requester = $r->requester;
        $requester?->loadMissing('referrer');

        $referrer = $requester?->referrer;

        return [
            'id' => $r->id,
            'name' => $requester ? trim($requester->first_name.' '.$requester->last_name) : '',
            'email' => $requester?->email,
            'phone' => $requester?->contact_number,
            'status' => $r->status,
            'created_at' => $r->created_at->format('Y-m-d'),
            'created_at_full' => $r->created_at->format('Y-m-d H:i'),
            'requester_note' => $r->requester_note,
            'approver_note' => $r->approver_note,
            'requester' => $requester ? [
                'user_id' => $requester->user_id,
                'first_name' => $requester->first_name,
                'last_name' => $requester->last_name,
                'email' => $requester->email,
                'contact_number' => $requester->contact_number,
                'role' => $requester->role,
                'referral_code' => $requester->referral_code,
                'referrer_code_used' => $requester->referrer_code_used,
                'date_registered' => $requester->date_registered?->format('Y-m-d H:i:s'),
                'referrer' => $referrer ? [
                    'id' => $referrer->user_id,
                    'name' => trim($referrer->first_name.' '.$referrer->last_name),
                    'phone' => $referrer->contact_number,
                    'email' => $referrer->email,
                ] : null,
            ] : null,
        ];
    }

    public function pendingUpgradeRequests(Request $request)
    {
        $auth = $request->user();
        if (! in_array($auth->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        $base = $this->upgradeRequestsBaseQuery($auth);

        $counts = [
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];

        $listQuery = (clone $base)->with(['requester.referrer'])->orderBy('created_at', 'desc');

        if (! empty($validated['status'])) {
            $listQuery->where('status', $validated['status']);
        }

        $requests = $listQuery->get()->map(fn ($r) => $this->formatUpgradeRequest($r));

        return response()->json([
            'requests' => $requests,
            'counts' => $counts,
        ]);
    }

    public function showUpgradeRequest(Request $request, $requestId)
    {
        $auth = $request->user();
        if (! in_array($auth->role, ['admin', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $upgradeRequest = AdminUpgradeRequest::with(['requester.referrer'])
            ->where('id', $requestId)
            ->first();

        if (! $upgradeRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if (
            $auth->role !== 'superadmin'
            && (int) $upgradeRequest->approver_id !== (int) $auth->user_id
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'request' => $this->formatUpgradeRequest($upgradeRequest),
        ]);
    }
}
