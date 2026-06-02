<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\User;
use App\Support\CommissionTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission listing and superadmin status management.
 *
 * `index`: superadmin, admin, and buyer — superadmin sees platform totals including withdrawal
 * receipts; others see direct-referral order commissions only (no multi-level tree).
 * `updateStatus`: superadmin only — release or cancel commissions; cancellation reverses referrer
 * points. Withdrawal-receipt rows are immutable.
 */
class CommissionController extends Controller
{
    private function isSuperadmin(User $user): bool
    {
        return $user->role === 'superadmin';
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (! in_array($user->role, ['superadmin', 'admin', 'buyer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $baseQuery = Commission::query()
            ->with(['referral.referrer', 'order.buyer', 'order', 'withdrawal.requester']);

        if ($this->isSuperadmin($user)) {
            CommissionTotals::forSuperadmin($baseQuery, (int) $user->user_id);
            $summary = CommissionTotals::superadminSummary((int) $user->user_id);
        } else {
            $baseQuery = CommissionTotals::forReferrer(
                CommissionTotals::orderReferralBase(),
                (int) $user->user_id
            );
            $summary = CommissionTotals::personalSummary((int) $user->user_id);
        }

        $listQuery = clone $baseQuery;

        if ($request->filled('status')) {
            $request->validate([
                'status' => 'in:pending,earned,cancelled',
            ]);
            $listQuery = CommissionTotals::applyListStatusFilter($listQuery, $request->query('status'));
        } else {
            $listQuery->where('commissions.status', '!=', 'cancelled');
        }

        $commissions = $listQuery
            ->orderByDesc('date_earned')
            ->orderByDesc('commission_id')
            ->get();

        if ($this->isSuperadmin($user)) {
            $payload = $commissions->map(fn (Commission $c) => $this->formatCommissionSuperadmin($c));
        } else {
            $payload = $commissions->map(fn (Commission $c) => $this->formatCommissionReferrer($c));
        }

        return response()->json([
            'summary' => $summary,
            'commissions' => $payload,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        if (! $this->isSuperadmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:released,cancelled',
        ]);

        $commission = Commission::with(['referral.referrer', 'order.buyer', 'order'])
            ->where('commission_id', $id)
            ->first();

        if (! $commission) {
            return response()->json(['message' => 'Commission not found'], 404);
        }

        $newStatus = $validated['status'];

        if ($commission->status === 'cancelled') {
            return response()->json(['message' => 'Commission is already cancelled'], 422);
        }

        if ($newStatus === 'released' && $commission->status === 'released') {
            return response()->json(['message' => 'Commission is already released'], 422);
        }

        if ($commission->source === Commission::SOURCE_WITHDRAWAL_RECEIPT) {
            return response()->json(['message' => 'Withdrawal receipt commissions cannot be changed'], 422);
        }

        DB::transaction(function () use ($commission, $newStatus) {
            if ($newStatus === 'cancelled') {
                $referrer = $commission->referral?->referrer;
                if ($referrer) {
                    $pts = (int) round((float) $commission->commission_earned);
                    $referrer->refresh();
                    $referrer->update([
                        'points' => max(0, $referrer->points - $pts),
                    ]);
                }
            }

            $commission->update([
                'status' => $newStatus,
            ]);
        });

        $commission->refresh()->load(['referral.referrer', 'order.buyer', 'order']);

        $summary = CommissionTotals::platformSummary();

        return response()->json([
            'message' => 'Commission updated',
            'commission' => $this->formatCommissionSuperadmin($commission),
            'summary' => $summary,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCommissionReferrer(Commission $commission): array
    {
        $orderStatus = $commission->order?->order_status;

        return [
            'commission_id' => $commission->commission_id,
            'order_id' => $commission->order_id,
            'order_status' => $orderStatus,
            'commission_earned' => $commission->commission_earned,
            'date_earned' => $commission->date_earned?->format('Y-m-d'),
            'status' => $commission->status,
            'earning_status' => CommissionTotals::earningStatusFromOrder(
                $orderStatus,
                $commission->status,
                $commission->source
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCommissionSuperadmin(Commission $commission): array
    {
        $referrer = $commission->referral?->referrer;
        $order = $commission->order;
        $orderStatus = $order?->order_status;
        $isReceipt = $commission->source === Commission::SOURCE_WITHDRAWAL_RECEIPT;
        $requester = $commission->withdrawal?->requester;

        return [
            'commission_id' => $commission->commission_id,
            'source' => $commission->source ?? Commission::SOURCE_ORDER_REFERRAL,
            'withdrawal_id' => $commission->withdrawal_id,
            'requester_name' => $isReceipt && $requester
                ? trim(($requester->first_name ?? '').' '.($requester->last_name ?? ''))
                : null,
            'referral_id' => $commission->referral_id,
            'referrer_id' => $commission->referral?->referrer_id,
            'referrer_name' => $referrer
                ? trim(($referrer->first_name ?? '').' '.($referrer->last_name ?? ''))
                : null,
            'order_id' => $commission->order_id,
            'order' => $order ? [
                'total_amount' => $order->total_amount,
                'order_status' => $orderStatus,
                'buyer_id' => $order->buyer_id,
                'buyer_name' => $order->buyer
                    ? trim(($order->buyer->first_name ?? '').' '.($order->buyer->last_name ?? ''))
                    : null,
            ] : null,
            'commission_earned' => $commission->commission_earned,
            'date_earned' => $commission->date_earned?->format('Y-m-d'),
            'status' => $commission->status,
            'earning_status' => CommissionTotals::earningStatusFromOrder(
                $orderStatus,
                $commission->status,
                $commission->source
            ),
        ];
    }
}
