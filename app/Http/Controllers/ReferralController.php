<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\CommissionTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Referral codes and direct-referral relationships.
 *
 * Public `checkQuery`: validate a code before registration (no auth).
 * Authenticated: view own referrals and earnings summary, generate/check/apply/delete referral links.
 * Each buyer may have at most one referrer; commissions are direct-referral only.
 */
class ReferralController extends Controller
{
    /**
     * Public signup validation (no auth).
     */
    public function checkQuery(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $referrer = User::findByReferralCode($request->query('code'));

        return response()->json([
            'valid' => (bool) $referrer,
            'referrer_name' => $referrer
                ? trim(($referrer->first_name ?? '').' '.($referrer->last_name ?? ''))
                : null,
        ]);
    }

    public function mine(Request $request)
    {
        $user = $request->user();

        if (! $user->referral_code) {
            return response()->json([
                'referral_code' => null,
                'referred_users' => [],
                'summary' => [
                    'referral_code' => null,
                    'total_referrals' => 0,
                    'total_earned' => 0.0,
                    'total_pending' => 0.0,
                ],
            ]);
        }

        $normalized = strtoupper(trim($user->referral_code));

        $referredUsers = User::query()
            ->whereRaw('UPPER(TRIM(referrer_code_used)) = ?', [$normalized])
            ->orderBy('date_registered')
            ->get();

        $commissionBase = CommissionTotals::forReferrer(
            CommissionTotals::orderReferralBase(),
            (int) $user->user_id
        );

        $totalPending = CommissionTotals::sumPending($commissionBase);
        $totalEarned = CommissionTotals::sumEarned($commissionBase);

        $referredPayload = $referredUsers->map(function (User $u) use ($user) {
            $perUserBase = CommissionTotals::orderReferralBase()->whereHas('referral', function ($q) use ($user, $u) {
                $q->where('referrer_id', $user->user_id)
                    ->where('referred_id', $u->user_id);
            });
            $totalCommission = CommissionTotals::sumPending($perUserBase) + CommissionTotals::sumEarned($perUserBase);

            return [
                'user_id' => $u->user_id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'date_registered' => $u->date_registered?->format('Y-m-d H:i:s'),
                'total_commission_earned' => round((float) $totalCommission, 2),
            ];
        });

        return response()->json([
            'referral_code' => $user->referral_code,
            'referred_users' => $referredPayload,
            'summary' => [
                'referral_code' => $user->referral_code,
                'total_referrals' => $referredUsers->count(),
                'total_earned' => round((float) $totalEarned, 2),
                'total_pending' => round((float) $totalPending, 2),
            ],
        ]);
    }

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

    public function checkReferralCode(Request $request)
    {
        $validated = $request->validate([
            'referral_code' => 'required|string',
        ]);

        $referrer = User::findByReferralCode($validated['referral_code']);

        return response()->json([
            'valid' => (bool) $referrer,
            'message' => $referrer ? 'Referral code is valid' : 'Invalid referral code',
        ]);
    }

    public function applyReferral(Request $request)
    {
        $validated = $request->validate([
            'referral_code' => 'required|string',
        ]);

        $user = $request->user();

        if ($user->referrer_code_used) {
            return response()->json([
                'message' => 'Referral already applied',
            ], 409);
        }

        $referrer = User::findByReferralCode($validated['referral_code']);
        if (! $referrer) {
            return response()->json([
                'message' => 'Invalid referral code',
            ], 400);
        }

        if ((int) $referrer->user_id === (int) $user->user_id) {
            return response()->json([
                'message' => 'Cannot use your own referral code',
            ], 400);
        }

        $user->referrer_code_used = strtoupper(trim($validated['referral_code']));
        $user->save();

        ReferralLink::updateOrCreate(
            ['referred_id' => $user->user_id],
            [
                'referrer_id' => $referrer->user_id,
                'status' => 'active',
            ]
        );

        return response()->json([
            'message' => 'Referral applied',
        ]);
    }

    public function deleteReferral(Request $request)
    {
        $user = $request->user();

        ReferralLink::where('referred_id', $user->user_id)->delete();
        $user->referrer_code_used = null;
        $user->save();

        return response()->json([
            'message' => 'Referral removed',
        ]);
    }
}