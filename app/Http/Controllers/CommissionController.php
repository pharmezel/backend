<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    private function isSuperadmin(User $user): bool
    {
        return $user->role === 'superadmin';
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $baseQuery = Commission::query()->with(['referral.referrer', 'order.buyer']);

        if (! $this->isSuperadmin($user)) {
            $baseQuery->whereHas('referral', function ($q) use ($user) {
                $q->where('referrer_id', $user->user_id);
            });
        }

        $summaryQuery = clone $baseQuery;

        $totalPending = (clone $summaryQuery)->where('status', 'pending')->sum('commission_earned');
        $totalReleased = (clone $summaryQuery)->where('status', 'released')->sum('commission_earned');
        $totalEarned = (clone $summaryQuery)->whereIn('status', ['pending', 'released'])->sum('commission_earned');

        $listQuery = clone $baseQuery;
        if ($request->filled('status')) {
            $request->validate([
                'status' => 'in:pending,released,cancelled',
            ]);
            $listQuery->where('status', $request->query('status'));
        }

        $commissions = $listQuery->orderByDesc('date_earned')->orderByDesc('commission_id')->get();

        if ($this->isSuperadmin($user)) {
            $payload = $commissions->map(fn (Commission $c) => $this->formatCommissionSuperadmin($c));
        } else {
            $payload = $commissions->map(fn (Commission $c) => $this->formatCommissionReferrer($c));
        }

        return response()->json([
            'summary' => [
                'total_earned' => round((float) $totalEarned, 2),
                'total_pending' => round((float) $totalPending, 2),
                'total_released' => round((float) $totalReleased, 2),
            ],
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

        $commission = Commission::with(['referral.referrer'])->where('commission_id', $id)->first();
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

        DB::transaction(function () use ($commission, $newStatus, $request) {
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

        $commission->refresh()->load(['referral.referrer', 'order.buyer']);

        return response()->json([
            'message' => 'Commission updated',
            'commission' => $this->formatCommissionSuperadmin($commission),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCommissionReferrer(Commission $commission): array
    {
        return [
            'commission_id' => $commission->commission_id,
            'order_id' => $commission->order_id,
            'commission_earned' => $commission->commission_earned,
            'date_earned' => $commission->date_earned?->format('Y-m-d'),
            'status' => $commission->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCommissionSuperadmin(Commission $commission): array
    {
        $referrer = $commission->referral?->referrer;
        $order = $commission->order;

        return [
            'commission_id' => $commission->commission_id,
            'referral_id' => $commission->referral_id,
            'referrer_id' => $commission->referral?->referrer_id,
            'referrer_name' => $referrer
                ? trim(($referrer->first_name ?? '').' '.($referrer->last_name ?? ''))
                : null,
            'order_id' => $commission->order_id,
            'order' => $order ? [
                'total_amount' => $order->total_amount,
                'order_status' => $order->order_status,
                'buyer_id' => $order->buyer_id,
                'buyer_name' => $order->buyer
                    ? trim(($order->buyer->first_name ?? '').' '.($order->buyer->last_name ?? ''))
                    : null,
            ] : null,
            'commission_earned' => $commission->commission_earned,
            'date_earned' => $commission->date_earned?->format('Y-m-d'),
            'status' => $commission->status,
        ];
    }
}
