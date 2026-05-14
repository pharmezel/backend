<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function adminDashboard(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $bounds = $this->resolveRange($request);

        $usersQuery = User::query();
        $this->applyUserRegisteredRange($usersQuery, $bounds);

        $totalUsers = (clone $usersQuery)->count();
        $buyers = (clone $usersQuery)->where('role', 'buyer')->count();
        $admins = (clone $usersQuery)->where('role', 'admin')->count();

        $ordersQuery = Order::query();
        $this->applyOrderDateRange($ordersQuery, $bounds);

        $ordersTotal = (clone $ordersQuery)->count();
        $statuses = ['processing', 'confirmed', 'packaging', 'shipped', 'delivered', 'fulfilled', 'cancelled', 'issues'];
        $byStatus = [];
        foreach ($statuses as $status) {
            $byStatus[$status] = (clone $ordersQuery)->where('order_status', $status)->count();
        }

        $revenueQuery = Order::query()
            ->whereIn('order_status', ['delivered', 'fulfilled']);
        $this->applyOrderDateRange($revenueQuery, $bounds);
        $revenue = (float) $revenueQuery->sum('total_amount');

        $commBase = Commission::query()->whereHas('referral', function ($q) use ($request) {
            $q->where('referrer_id', $request->user()->user_id);
        });
        $this->applyCommissionDateRange($commBase, $bounds);
        $totalPending = (float) (clone $commBase)->where('status', 'pending')->sum('commission_earned');
        $totalReleased = (float) (clone $commBase)->where('status', 'released')->sum('commission_earned');
        $totalIssued = $totalPending + $totalReleased;

        $wdBase = Withdrawal::query();
        $this->applyWithdrawalCreatedRange($wdBase, $bounds);
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

        return response()->json([
            'users' => [
                'total' => $totalUsers,
                'buyers' => $buyers,
                'admins' => $admins,
            ],
            'orders' => [
                'total' => $ordersTotal,
                'by_status' => $byStatus,
            ],
            'revenue' => round($revenue, 2),
            'commissions' => [
                'total_issued' => round($totalIssued, 2),
                'total_pending' => round($totalPending, 2),
                'total_released' => round($totalReleased, 2),
            ],
            'withdrawals' => $withdrawals,
            'top_products' => $top_products,
            'low_stock' => $low_stock,
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

            $linkIds = ReferralLink::where('referrer_id', $buyerId)->pluck('id');
            $commBase = Commission::whereIn('referral_id', $linkIds);
            $totalPending = (float) (clone $commBase)->where('status', 'pending')->sum('commission_earned');
            $totalReleased = (float) (clone $commBase)->where('status', 'released')->sum('commission_earned');
            $totalEarned = $totalPending + $totalReleased;

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
                'commissions' => [
                    'total_earned' => round($totalEarned, 2),
                    'total_pending' => round($totalPending, 2),
                    'total_released' => round($totalReleased, 2),
                ],
                'my_commissions' => [
                    'total_earned' => round($totalEarned, 2),
                    'total_pending' => round($totalPending, 2),
                    'total_released' => round($totalReleased, 2),
                ],
                'inventory' => [
                    'count' => $inventoryCount,
                ],
                'withdrawal_balance' => $user->points,
                'my_points' => $user->points,
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
            'points_balance' => $user->points,
            'my_points' => $user->points,
        ]);
    }
}
