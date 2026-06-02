<?php

namespace App\Http\Controllers;

use App\Models\AdminInventory;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\DirectReferralCommission;
use App\Support\ProductCommission;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order placement, listing, and fulfillment.
 *
 * Buyers create orders (COD, points, or mixed payment) from their referrer's catalog stock.
 * Superadmin and admin staff update order status; direct-referral commission is created when
 * an order reaches a complete status (delivered/fulfilled). Admin inventory stock is decremented
 * for admin-referrer orders. Buyers may cancel their own orders while still processing.
 */
class OrderController extends Controller
{
    private const STATUSES = [
        'processing', 'confirmed', 'packaging', 'shipped', 'delivered', 'fulfilled', 'cancelled', 'issues',
    ];

    private const COMPLETE_STATUSES = ['delivered', 'fulfilled'];

    private function isStaff(User $user): bool
    {
        return in_array($user->role, ['superadmin', 'admin'], true);
    }

    private function moneyClose(float $a, float $b): bool
    {
        return abs($a - $b) < 0.02;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,product_id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'payment_action' => 'required|in:cod,points,cod_points',
            'shipping_address' => 'required|string',
            'points_used' => 'nullable|integer|min:0',
            'cod_amount' => 'nullable|numeric|min:0',
        ]);

        $buyer = $request->user();
        $payment = $validated['payment_action'];
        $pointsUsed = (int) ($validated['points_used'] ?? 0);
        $codAmount = array_key_exists('cod_amount', $validated) && $validated['cod_amount'] !== null
            ? (float) $validated['cod_amount']
            : null;

        $lineItems = [];
        $totalAmount = 0.0;
        $qtyByProduct = [];

        foreach ($validated['items'] as $row) {
            $pid = (int) $row['product_id'];
            $qty = (int) $row['quantity'];
            $unit = (float) $row['unit_price'];
            $subtotal = round($unit * $qty, 2);
            $totalAmount = round($totalAmount + $subtotal, 2);
            $lineItems[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }

        if ($payment === 'cod') {
            if ($pointsUsed !== 0) {
                return response()->json([
                    'message' => 'points_used must be 0 for cod payment',
                ], 422);
            }
        } elseif ($payment === 'points') {
            if ((float) $pointsUsed + 0.005 < $totalAmount) {
                return response()->json([
                    'message' => 'points_used must cover total_amount for points payment',
                ], 422);
            }
        } elseif ($payment === 'cod_points') {
            if ($codAmount === null) {
                return response()->json([
                    'message' => 'cod_amount is required for cod_points payment',
                ], 422);
            }
            if (! $this->moneyClose((float) $pointsUsed + $codAmount, $totalAmount)) {
                return response()->json([
                    'message' => 'points_used plus cod_amount must equal total_amount',
                ], 422);
            }
        }

        if (($payment === 'points' || $payment === 'cod_points') && $pointsUsed > 0) {
            if ($buyer->points < $pointsUsed) {
                return response()->json([
                    'message' => 'Insufficient points balance',
                ], 422);
            }
        }

        // Level-1 only: single referral_links row where referred_id = buyer (no tree walk).
        $referral = DirectReferralCommission::linkForBuyer((int) $buyer->user_id);
        $referrer = $referral?->referrer;

        $order = null;

        DB::transaction(function () use ($buyer, $lineItems, $qtyByProduct, $totalAmount, $payment, $validated, $pointsUsed, $codAmount, &$order, $referrer, $referral) {
            $productsLocked = [];
            $adminInvLocked = [];

            foreach (collect($qtyByProduct)->sortKeys() as $pid => $needQty) {
                if ($referrer && $referrer->role === 'admin') {
                    $inv = AdminInventory::query()
                        ->where('admin_id', $referrer->user_id)
                        ->where('product_id', $pid)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if (! $inv || $inv->stock_quantity < $needQty) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'Insufficient stock for product '.$pid,
                        ], 422));
                    }

                    $adminInvLocked[$pid] = $inv;
                } else {
                    $product = Product::with('brand')->lockForUpdate()
                        ->where('product_id', $pid)
                        ->first();

                    if (! $product || $product->stock_quantity < $needQty) {
                        throw new HttpResponseException(response()->json([
                            'message' => 'Insufficient stock for product '.$pid,
                        ], 422));
                    }

                    $productsLocked[$pid] = $product;
                }
            }

            foreach (array_keys($qtyByProduct) as $pid) {
                if (! isset($productsLocked[$pid])) {
                    $product = Product::with('brand')->where('product_id', $pid)->first();
                    if ($product) {
                        $productsLocked[$pid] = $product;
                    }
                }
            }

            $order = Order::create([
                'buyer_id' => $buyer->user_id,
                'order_date' => now()->toDateString(),
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'payment_action' => $payment,
                'order_status' => 'processing',
                'shipping_address' => $validated['shipping_address'],
                'points_used' => $pointsUsed,
                'cod_amount' => $codAmount !== null ? number_format($codAmount, 2, '.', '') : null,
            ]);

            foreach ($lineItems as $line) {
                OrderDetail::create([
                    'order_id' => $order->order_id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'subtotal' => number_format($line['subtotal'], 2, '.', ''),
                ]);

                if ($referrer && $referrer->role === 'admin') {
                    $adminInvLocked[$line['product_id']]->decrement('stock_quantity', $line['quantity']);
                } else {
                    $productsLocked[$line['product_id']]->decrement('stock_quantity', $line['quantity']);
                }
            }

            if ($pointsUsed > 0) {
                $buyer->decrement('points', $pointsUsed);
            }

            // One commission record for the direct referrer only (includes superadmin when they referred the buyer).
            DirectReferralCommission::createForOrder(
                $order,
                (int) $buyer->user_id,
                $lineItems,
                $productsLocked
            );
        });

        $order->load(['buyer', 'details.product']);

        return response()->json([
            'message' => 'Order created',
            'order' => $this->formatOrder($order, false),
        ], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Order::query()
            ->with(['buyer', 'details.product', 'commissions'])
            ->orderByDesc('order_id');

        $scope = $request->query('scope');

        if ($user->role === 'superadmin') {
            $referredIds = ReferralLink::where('referrer_id', $user->user_id)
                ->pluck('referred_id')
                ->toArray();

            $query->where(function ($q) use ($referredIds) {
                if ($referredIds !== []) {
                    $q->whereIn('buyer_id', $referredIds);
                }
                $q->orWhereNotIn('buyer_id', ReferralLink::query()->select('referred_id'));
            });
        } elseif ($user->role === 'admin' && $scope === 'mine') {
            $query->where('buyer_id', $user->user_id);
        } elseif ($user->role === 'admin') {
            $referredIds = ReferralLink::where('referrer_id', $user->user_id)
                ->pluck('referred_id')
                ->toArray();
            $query->whereIn('buyer_id', $referredIds);
        } else {
            $query->where('buyer_id', $user->user_id);
        }

        if ($request->filled('status')) {
            $query->where('order_status', $request->query('status'));
        }

        $orders = $query->get()->map(fn (Order $order) => $this->formatOrder($order, false));

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::with(['buyer', 'details.product', 'commissions'])
            ->where('order_id', $id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($user->role === 'superadmin') {
            // superadmin may view any order
        } elseif ($user->role === 'admin') {
            if ((int) $order->buyer_id === (int) $user->user_id) {
                // Admin viewing their own purchase (My Orders)
            } elseif (! ReferralLink::where('referrer_id', $user->user_id)
                ->where('referred_id', $order->buyer_id)
                ->exists()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } else {
            if ((int) $order->buyer_id !== (int) $user->user_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        return response()->json([
            'order' => $this->formatOrder($order, true),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        if (! $this->isStaff($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'order_status' => 'required|in:'.implode(',', self::STATUSES),
            'issue_description' => 'nullable|string|max:1000',
        ]);

        $order = Order::with(['details', 'commissions.referral.referrer'])
            ->where('order_id', $id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->order_status === 'cancelled') {
            return response()->json(['message' => 'Order is already cancelled'], 422);
        }

        $newStatus = $validated['order_status'];
        $previousStatus = $order->order_status;

        DB::transaction(function () use ($order, $newStatus, $previousStatus, $validated) {
            if ($newStatus === 'cancelled') {
                $this->restoreCancelledOrder($order);
                $order->update(['order_status' => 'cancelled']);
            } else {
                $payload = ['order_status' => $newStatus];
                if ($newStatus === 'issues' && array_key_exists('issue_description', $validated) && $validated['issue_description'] !== null) {
                    $payload['issue_description'] = $validated['issue_description'];
                }
                $order->update($payload);

                if ($this->isCompleteStatus($newStatus) && ! $this->isCompleteStatus($previousStatus)) {
                    $order->refresh()->load(['commissions.referral.referrer']);
                    $this->releaseCommissionsForOrder($order);
                }
            }
        });

        if ($newStatus === 'fulfilled' && $previousStatus !== 'fulfilled') {
            $order->refresh()->load('details');
            $this->creditAdminInventoryOnFulfillment($order);
        }

        $order->refresh()->load(['buyer', 'details.product', 'commissions']);

        return response()->json([
            'message' => 'Order updated',
            'order' => $this->formatOrder($order, true),
        ]);
    }

    public function buyerCancel(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::with(['details', 'commissions.referral.referrer'])
            ->where('order_id', $id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ((int) $order->buyer_id !== (int) $user->user_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($order->order_status === 'cancelled') {
            return response()->json(['message' => 'Order is already cancelled'], 422);
        }

        if ($order->order_status !== 'processing') {
            return response()->json([
                'message' => 'Only processing orders can be cancelled',
            ], 422);
        }

        try {
            DB::transaction(function () use ($order) {
                $this->restoreCancelledOrder($order);
                $order->update(['order_status' => 'cancelled']);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not cancel order. Please try again.',
            ], 500);
        }

        $order->refresh()->load(['buyer', 'details.product']);

        return response()->json([
            'message' => 'Order cancelled',
            'order' => $this->formatOrder($order, true),
        ]);
    }

    private function restoreCancelledOrder(Order $order): void
    {
        $order->loadMissing(['details', 'commissions.referral.referrer']);

        $this->restoreOrderStockLines($order);

        if ($order->points_used > 0) {
            User::where('user_id', $order->buyer_id)->increment('points', $order->points_used);
        }

        $this->cancelCommissionsForOrder($order);
    }

    private function isCompleteStatus(string $status): bool
    {
        return in_array($status, self::COMPLETE_STATUSES, true);
    }

    private function releaseCommissionsForOrder(Order $order): void
    {
        foreach ($order->commissions as $commission) {
            if (in_array($commission->status, ['cancelled', 'released'], true)) {
                continue;
            }

            $referrer = $commission->referral?->referrer;
            $pts = (int) round((float) $commission->commission_earned);

            $commission->update(['status' => 'released']);

            if ($referrer && $pts > 0) {
                $referrer->increment('points', $pts);
            }
        }
    }

    private function cancelCommissionsForOrder(Order $order): void
    {
        foreach ($order->commissions as $commission) {
            if ($commission->status === 'cancelled') {
                continue;
            }

            if ($commission->status === 'released') {
                $referrer = $commission->referral?->referrer;
                if ($referrer) {
                    $pts = (int) round((float) $commission->commission_earned);
                    $referrer->refresh();
                    $referrer->update([
                        'points' => max(0, $referrer->points - $pts),
                    ]);
                }
            }

            $commission->update(['status' => 'cancelled']);
        }
    }

    private function orderCommissionAmount(Order $order): float
    {
        if ($order->relationLoaded('commissions')) {
            return round(
                (float) $order->commissions
                    ->where('status', '!=', 'cancelled')
                    ->sum('commission_earned'),
                2
            );
        }

        return round(
            (float) Commission::query()
                ->where('order_id', $order->order_id)
                ->where('status', '!=', 'cancelled')
                ->sum('commission_earned'),
            2
        );
    }

    private function restoreOrderStockLines(Order $order): void
    {
        $referral = ReferralLink::where('referred_id', $order->buyer_id)->first();
        $referrer = $referral ? User::find($referral->referrer_id) : null;

        foreach ($order->details as $detail) {
            if ($referrer && $referrer->role === 'admin') {
                AdminInventory::where('admin_id', $referrer->user_id)
                    ->where('product_id', $detail->product_id)
                    ->increment('stock_quantity', $detail->quantity);
            } else {
                Product::where('product_id', $detail->product_id)
                    ->increment('stock_quantity', $detail->quantity);
            }
        }
    }

    private function creditAdminInventoryOnFulfillment(Order $order): void
    {
        $buyer = User::find($order->buyer_id);
        if (! $buyer || $buyer->role !== 'admin') {
            return;
        }

        foreach ($order->details as $detail) {
            AdminInventory::updateOrCreate(
                ['admin_id' => $buyer->user_id, 'product_id' => $detail->product_id],
                []
            );
            AdminInventory::where('admin_id', $buyer->user_id)
                ->where('product_id', $detail->product_id)
                ->increment('stock_quantity', $detail->quantity);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(Order $order, bool $includeReferrer): array
    {
        $order->loadMissing(['buyer', 'details.product']);

        $buyer = $order->buyer;

        $referrerPayload = null;
        if ($includeReferrer) {
            $link = DirectReferralCommission::linkForBuyer((int) $order->buyer_id);
            $refUser = $link?->referrer;
            if ($refUser) {
                $referrerPayload = [
                    'user_id' => $refUser->user_id,
                    'first_name' => $refUser->first_name,
                    'last_name' => $refUser->last_name,
                ];
            }
        }

        $possibleCommission = 0.0;
        if ($order->relationLoaded('details')) {
            foreach ($order->details as $detail) {
                $product = $detail->product;
                if ($product) {
                    $rate = ProductCommission::resolveRate($product);
                    $possibleCommission += (float) $detail->subtotal * ($rate / 100);
                }
            }
        }

        return [
            'order_id' => $order->order_id,
            'buyer_id' => $order->buyer_id,
            'buyer_name' => $buyer
                ? trim(($buyer->first_name ?? '').' '.($buyer->last_name ?? ''))
                : null,
            'buyer' => $buyer ? [
                'user_id' => $buyer->user_id,
                'first_name' => $buyer->first_name,
                'last_name' => $buyer->last_name,
            ] : null,
            'referrer' => $referrerPayload,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'total_amount' => $order->total_amount,
            'payment_action' => $order->payment_action,
            'order_status' => $order->order_status,
            'issue_description' => $order->issue_description,
            'shipping_address' => $order->shipping_address,
            'points_used' => $order->points_used,
            'cod_amount' => $order->cod_amount,
            'possible_commission' => round($possibleCommission, 2),
            'commission_amount' => $this->orderCommissionAmount($order),
            'items' => $order->details->map(fn (OrderDetail $d) => [
                'order_details_id' => $d->order_details_id,
                'product_id' => $d->product_id,
                'product_name' => $d->product?->product_name,
                'quantity' => $d->quantity,
                'subtotal' => $d->subtotal,
            ])->values()->all(),
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }
}
