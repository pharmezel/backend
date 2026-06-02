<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use App\Support\CommissionTotals;
use App\Support\WithdrawalSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Points withdrawal request lifecycle.
 *
 * Admins and buyers create pending requests against available commission balance.
 * Superadmin approves (acknowledges only), completes (deducts points, records cash receipt),
 * cancels, or restores cancelled requests. Non-superadmins see only their own withdrawals.
 */
class WithdrawalController extends Controller
{
    private function isSuperadmin(User $user): bool
    {
        return $user->role === 'superadmin';
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Withdrawal::query()->with(['requester', 'processedBy'])->orderByDesc('id');

        if (! $this->isSuperadmin($user)) {
            $query->where('requester_id', $user->user_id);
        }

        $rows = $query->get()->map(function (Withdrawal $w) use ($user) {
            return $this->formatWithdrawal($w, $this->isSuperadmin($user));
        });

        $payload = ['withdrawals' => $rows];

        if (! $this->isSuperadmin($user)) {
            $payload['points_balance'] = CommissionTotals::pointsBalanceForUser((int) $user->user_id);
        }

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! in_array($user->role, ['admin', 'buyer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'points_requested' => 'required|integer|min:1',
        ]);

        $points = (int) $validated['points_requested'];
        $available = CommissionTotals::availableWithdrawalForUser((int) $user->user_id);

        if ($available <= 0 || $points > $available) {
            return response()->json([
                'message' => 'Insufficient points balance.',
            ], 422);
        }

        $withdrawal = Withdrawal::create([
            'requester_id' => $user->user_id,
            'points_requested' => $points,
            'status' => 'pending',
        ]);

        $withdrawal->load(['requester', 'processedBy']);

        return response()->json([
            'message' => 'Withdrawal request created',
            'withdrawal' => $this->formatWithdrawal($withdrawal, false),
            'points_balance' => CommissionTotals::pointsBalanceForUser((int) $user->user_id),
        ], 201);
    }

    public function approve(Request $request, $id)
    {
        if (! $this->isSuperadmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $withdrawal = Withdrawal::where('id', $id)->first();
        if (! $withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Only pending withdrawals can be approved'], 422);
        }

        $requesterId = (int) $withdrawal->requester_id;
        $pointsRequested = (int) $withdrawal->points_requested;

        // Acknowledge only — points_balance is reduced when status becomes 'completed', not here.
        $pointsBalance = CommissionTotals::pointsBalanceForUser($requesterId);
        Log::info('withdrawal.approve.balance_check', [
            'withdrawal_id' => $withdrawal->id,
            'requester_id' => $requesterId,
            'points_balance' => $pointsBalance,
            'points_requested' => $pointsRequested,
        ]);

        if ($pointsBalance < $pointsRequested) {
            Log::warning('withdrawal.approve.insufficient_balance', [
                'withdrawal_id' => $withdrawal->id,
                'requester_id' => $requesterId,
            ]);

            return response()->json([
                'message' => 'Insufficient points balance.',
            ], 422);
        }

        DB::transaction(function () use ($withdrawal, $request) {
            $withdrawal->update([
                'status' => 'approved',
                'processed_by' => $request->user()->user_id,
            ]);
            Log::info('withdrawal.approve.status_updated', ['withdrawal_id' => $withdrawal->id]);
        });

        $withdrawal->refresh()->load(['requester', 'processedBy']);

        return response()->json([
            'message' => 'Withdrawal approved',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
        ]);
    }

    public function complete(Request $request, $id)
    {
        if (! $this->isSuperadmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $withdrawal = Withdrawal::with('requester')->where('id', $id)->first();
        if (! $withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'approved') {
            return response()->json(['message' => 'Only approved withdrawals can be completed'], 422);
        }

        $requesterId = (int) $withdrawal->requester_id;
        $pointsRequested = (int) $withdrawal->points_requested;

        // Step 1 — Pre-transaction solvency (outside DB::transaction).
        // Approved rows do not reduce points_balance; deduction happens when marked completed.
        $pointsBalance = CommissionTotals::pointsBalanceForUser($requesterId);

        Log::info('withdrawal.complete.balance_check', [
            'withdrawal_id' => $withdrawal->id,
            'requester_id' => $requesterId,
            'points_balance' => $pointsBalance,
            'points_requested' => $pointsRequested,
        ]);

        if ($pointsBalance < $pointsRequested) {
            Log::warning('withdrawal.complete.insufficient_balance', [
                'withdrawal_id' => $withdrawal->id,
                'requester_id' => $requesterId,
            ]);

            return response()->json([
                'message' => 'Insufficient points balance.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($withdrawal, $request) {
                Log::info('withdrawal.complete.transaction_start', [
                    'withdrawal_id' => $withdrawal->id,
                ]);

                // Step 2 — Superadmin withdrawal_receipt commission (idempotent per withdrawal_id).
                $receipt = WithdrawalSettlement::recordSuperadminReceipt($withdrawal);

                Log::info('withdrawal.complete.receipt_recorded', [
                    'withdrawal_id' => $withdrawal->id,
                    'commission_id' => $receipt->commission_id,
                    'recipient_user_id' => $receipt->recipient_user_id,
                ]);

                // Step 3 — Mark completed; sumWithdrawn(completed) deducts from points_balance now.
                $withdrawal->update([
                    'status' => 'completed',
                    'processed_by' => $request->user()->user_id,
                ]);

                Log::info('withdrawal.complete.status_updated', [
                    'withdrawal_id' => $withdrawal->id,
                ]);
            });
        } catch (RuntimeException $e) {
            Log::error('withdrawal.complete.settlement_failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $withdrawal->refresh()->load(['requester', 'processedBy']);

        return response()->json([
            'message' => 'Withdrawal completed',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $isSuperadmin = $this->isSuperadmin($user);

        $withdrawal = Withdrawal::with('requester')->where('id', $id)->first();
        if (! $withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if (! $isSuperadmin) {
            if ((int) $withdrawal->requester_id !== (int) $user->user_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if ($withdrawal->status !== 'pending') {
                return response()->json([
                    'message' => 'You can only cancel pending requests. Contact Pharmicare for acknowledged requests.',
                ], 422);
            }
        }

        if ($isSuperadmin && ! in_array($withdrawal->status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'Cannot cancel a '.$withdrawal->status.' withdrawal'], 422);
        }

        if ($withdrawal->status === 'cancelled') {
            return response()->json(['message' => 'Already cancelled'], 422);
        }
        if ($withdrawal->status === 'completed') {
            return response()->json(['message' => 'Completed withdrawals cannot be cancelled'], 422);
        }

        DB::transaction(function () use ($withdrawal, $user) {
            $withdrawal->update([
                'status' => 'cancelled',
                'processed_by' => $user->user_id,
            ]);
        });

        $withdrawal->refresh()->load(['requester', 'processedBy']);

        return response()->json([
            'message' => 'Withdrawal cancelled',
            'withdrawal' => $this->formatWithdrawal($withdrawal, $isSuperadmin),
        ]);
    }

    public function restore(Request $request, $id)
    {
        if (! $this->isSuperadmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $withdrawal = Withdrawal::where('id', $id)->first();
        if (! $withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'cancelled') {
            return response()->json(['message' => 'Only cancelled withdrawals can be restored'], 422);
        }

        $withdrawal->update([
            'status' => 'pending',
            'processed_by' => null,
        ]);

        $withdrawal->refresh()->load(['requester', 'processedBy']);

        return response()->json([
            'message' => 'Withdrawal restored to pending',
            'withdrawal' => $this->formatWithdrawal($withdrawal, true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatWithdrawal(Withdrawal $w, bool $includeRequesterDetail): array
    {
        $requester = $w->requester;
        $base = [
            'id' => $w->id,
            'requester_id' => $w->requester_id,
            'points_requested' => $w->points_requested,
            'status' => $w->status,
            'processed_by' => $w->processed_by,
            'created_at' => $w->created_at,
            'updated_at' => $w->updated_at,
        ];

        if ($includeRequesterDetail && $requester) {
            $base['requester_name'] = trim(($requester->first_name ?? '').' '.($requester->last_name ?? ''));
        }

        return $base;
    }
}
