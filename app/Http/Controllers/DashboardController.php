<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\CommissionTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Role-specific dashboard aggregates.
 *
 * `userDashboard`: admins and buyers — orders, commissions, referrals, withdrawals scoped to the user.
 * `adminDashboard`: superadmin only — platform metrics limited to direct-referral orders and
 * buyers with no referrer (Pharmicare default), plus commission and withdrawal summaries.
 * Supports optional date ranges: today, week, month, year, or all.
 */
class DashboardController extends Controller
{
    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveRange(Request $request): ?array
    {
        $request->validate([
            'range' => 'nullable|in:today,week,month,year,all',
        ]);

        $range = $request->query('range', 'all');
        if ($range === 'all' || $range === null || $range === '') {
            return null;
        }

        $end = now()->endOfDay();

        $start = match ($range) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfDay(),
        };

        return [$start, $end];
    }

    private function applyOrderDateRange($query, ?array $bounds, string $column = 'order_date'): void
    {
        if ($bounds === null) {
            return;
        }
        [$start, $end] = $bounds;
        $query->whereDate($column, '>=', $start->toDateString())
            ->whereDate($column, '<=', $end->toDateString());
    }

    private function applyUserRegisteredRange($query, ?array $bounds): void
    {
        if ($bounds === null) {
            return;
        }
        [$start, $end] = $bounds;
        $query->whereBetween('date_registered', [$start, $end]);
    }

    private function applyCommissionDateRange($query, ?array $bounds): void
    {
        if ($bounds === null) {
            return;
        }
        [$start, $end] = $bounds;
        $query->whereDate('date_earned', '>=', $start->toDateString())
            ->whereDate('date_earned', '<=', $end->toDateString());
    }

    private function applyWithdrawalCreatedRange($query, ?array $bounds): void
    {
        if ($bounds === null) {
            return;
        }
        [$start, $end] = $bounds;
        $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Orders from direct referrals or buyers with no referrer (Pharmicare default).
     */
    private function scopeOrdersForSuperadmin($query, int $superadminUserId, string $buyerIdColumn = 'buyer_id'): void
    {
        $referredIds = ReferralLink::where('referrer_id', $superadminUserId)
            ->pluck('referred_id')
            ->toArray();

        $query->where(function ($q) use ($referredIds, $buyerIdColumn) {
            if ($referredIds !== []) {
                $q->whereIn($buyerIdColumn, $referredIds);
            }
            $q->orWhereNotIn($buyerIdColumn, ReferralLink::query()->select('referred_id'));
        });
    }

    public function adminDashboard(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $superadminUserId = (int) $request->user()->user_id;
        $bounds = $this->resolveRange($request);

        $usersQuery = User::query();
        $this->applyUserRegisteredRange($usersQuery, $bounds);

        $totalUsers = (clone $usersQuery)->count();
        $buyers = (clone $usersQuery)->where('role', 'buyer')->count();
        $admins = (clone $usersQuery)->where('role', 'admin')->count();
        $superadmins = (clone $usersQuery)->where('role', 'superadmin')->count();

        $ordersQuery = Order::query();
        $this->scopeOrdersForSuperadmin($ordersQuery, $superadminUserId);
        $this->applyOrderDateRange($ordersQuery, $bounds);

        $ordersTotal = (clone $ordersQuery)->count();
        $statuses = ['processing', 'confirmed', 'packaging', 'shipped', 'delivered', 'fulfilled', 'cancelled', 'issues'];
        $byStatus = [];
        foreach ($statuses as $status) {
            $byStatus[$status] = (clone $ordersQuery)->where('order_status', $status)->count();
        }

        $revenueQuery = Order::query()
            ->whereIn('order_status', ['delivered', 'fulfilled']);
        $this->scopeOrdersForSuperadmin($revenueQuery, $superadminUserId);
        $this->applyOrderDateRange($revenueQuery, $bounds);
        $revenue = (float) $revenueQuery->sum('total_amount');

        $commissionSummary = CommissionTotals::superadminSummary($superadminUserId);
        $totalPending = $commissionSummary['total_pending'];
        $totalEarned = $commissionSummary['total_earned'];
        $totalIssued = $totalPending + $totalEarned;

        $wdBase = Withdrawal::query()->whereIn('status', ['approved', 'completed']);
        $this->applyWithdrawalCreatedRange($wdBase, $bounds);
        $totalWithdrawn = (float) (clone $wdBase)->sum('points_requested');

        $withdrawals = [
            'total' => (clone $wdBase)->count(),
            'pending' => (clone $wdBase)->where('status', 'pending')->count(),
            'approved' => (clone $wdBase)->where('status', 'approved')->count(),
            'completed' => (clone $wdBase)->where('status', 'completed')->count(),
            'cancelled' => (clone $wdBase)->where('status', 'cancelled')->count(),
        ];

        $topProductsQuery = OrderDetail::query()
            ->join('orders', 'order_details.order_id', '=', 'orders.order_id')
            ->whereIn('orders.order_status', ['delivered', 'fulfilled']);
        $this->scopeOrdersForSuperadmin($topProductsQuery, $superadminUserId, 'orders.buyer_id');
        if ($bounds !== null) {
            [$start, $end] = $bounds;
            $topProductsQuery->whereDate('orders.order_date', '>=', $start->toDateString())
                ->whereDate('orders.order_date', '<=', $end->toDateString());
        }
        $topRows = $topProductsQuery
            ->select('order_details.product_id', DB::raw('SUM(order_details.quantity) as qty_sold'))
            ->groupBy('order_details.product_id')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get();

        $productIds = $topRows->pluck('product_id');
        $names = Product::whereIn('product_id', $productIds)->pluck('product_name', 'product_id');
        $top_products = $topRows->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'product_name' => $names[$row->product_id] ?? null,
            'quantity_sold' => (int) $row->qty_sold,
        ])->values()->all();

        $low_stock = Product::query()
            ->where('stock_quantity', '<', 10)
            ->orderBy('stock_quantity')
            ->get(['product_id', 'product_name', 'stock_quantity'])
            ->map(fn (Product $p) => [
                'product_id' => $p->product_id,
                'product_name' => $p->product_name,
                'stock_quantity' => $p->stock_quantity,
            ]);

        $recent_users = User::query()
            ->orderByDesc('date_registered')
            ->limit(5)
            ->get(['user_id', 'first_name', 'last_name', 'role', 'date_registered'])
            ->map(fn (User $u) => [
                'id' => $u->user_id,
                'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')),
                'role' => $u->role,
                'date_registered' => $u->date_registered?->format('M j, Y'),
            ])
            ->values()
            ->all();

        return response()->json([
            'users' => [
                'total' => $totalUsers,
                'buyers' => $buyers,
                'admins' => $admins,
                'superadmins' => $superadmins,
            ],
            'orders' => [
                'total' => $ordersTotal,
                'by_status' => $byStatus,
            ],
            'revenue' => round($revenue, 2),
            'commissions' => [
                'total_issued' => round($totalIssued, 2),
                'total_pending' => $commissionSummary['total_pending'],
                'referral_earned' => $commissionSummary['referral_earned'],
                'withdrawal_receipts' => $commissionSummary['withdrawal_receipts'],
                'total_earned' => $commissionSummary['total_earned'],
                'withdrawn' => $commissionSummary['withdrawn'],
                'points_balance' => $commissionSummary['points_balance'],
                'total_withdrawn' => $commissionSummary['withdrawn'],
            ],
            'withdrawals' => $withdrawals,
            'top_products' => $top_products,
            'low_stock' => $low_stock,
            'recent_users' => $recent_users,
        ]);
    }

    public function userDashboard(Request $request)
    {
        $user = $request->user();

        if (! in_array($user->role, ['admin', 'buyer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $buyerId = $user->user_id;

        if ($user->role === 'admin') {
            $myOrdersScope = Order::where('buyer_id', $buyerId);
            $myOrdersCount = (clone $myOrdersScope)->count();
            $myOrdersTotalSpent = (float) (clone $myOrdersScope)->sum('total_amount');

            $referredBuyerIds = ReferralLink::where('referrer_id', $buyerId)->pluck('referred_id');
            $manageOrdersCount = Order::whereIn('buyer_id', $referredBuyerIds)->count();

            $commissionSummary = CommissionTotals::personalSummary($buyerId);

            $inventoryCount = AdminInventory::where('admin_id', $buyerId)->count();

            $recentOrders = Order::where('buyer_id', $buyerId)
                ->orderByDesc('order_id')
                ->limit(5)
                ->get(['order_id', 'order_status', 'total_amount', 'order_date'])
                ->map(fn (Order $o) => [
                    'id' => $o->order_id,
                    'status' => $o->order_status,
                    'total_amount' => $o->total_amount,
                    'order_date' => $o->order_date?->format('Y-m-d'),
                ]);

            return response()->json([
                'my_orders' => [
                    'count' => $myOrdersCount,
                    'total_spent' => round($myOrdersTotalSpent, 2),
                ],
                'manage_orders' => [
                    'count' => $manageOrdersCount,
                ],
                'commissions' => $commissionSummary,
                'my_commissions' => $commissionSummary,
                'inventory' => [
                    'count' => $inventoryCount,
                ],
                'points_balance' => CommissionTotals::pointsBalanceForUser($buyerId),
                'my_referrals' => [
                    'count' => ReferralLink::where('referrer_id', $buyerId)->count(),
                ],
                'recent_orders' => $recentOrders,
            ]);
        }

        $ordersScope = Order::where('buyer_id', $buyerId);
        $myOrdersCount = (clone $ordersScope)->count();
        $totalSpent = (float) (clone $ordersScope)->sum('total_amount');

        $recentOrders = Order::where('buyer_id', $buyerId)
            ->orderByDesc('order_id')
            ->limit(5)
            ->get(['order_id', 'order_status', 'total_amount', 'order_date'])
            ->map(fn (Order $o) => [
                'id' => $o->order_id,
                'status' => $o->order_status,
                'total_amount' => $o->total_amount,
                'order_date' => $o->order_date?->format('Y-m-d'),
            ]);

        return response()->json([
            'my_orders' => [
                'count' => $myOrdersCount,
                'total_spent' => round($totalSpent, 2),
            ],
            'recent_orders' => $recentOrders,
            'points_balance' => CommissionTotals::pointsBalanceForUser((int) $user->user_id),
        ]);
    }
}
