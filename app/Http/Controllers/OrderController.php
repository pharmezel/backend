<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ReferralLink;
use App\Models\User;
use App\Support\ProductCommission;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    private const STATUSES = [
        'processing', 'confirmed', 'packaging', 'shipped', 'delivered', 'fulfilled', 'cancelled',
    ];

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

        $order = null;

        DB::transaction(function () use ($buyer, $lineItems, $qtyByProduct, $totalAmount, $payment, $validated, $pointsUsed, $codAmount, &$order) {
            $productsLocked = [];

            foreach (collect($qtyByProduct)->sortKeys() as $pid => $needQty) {
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

                $productsLocked[$line['product_id']]->decrement('stock_quantity', $line['quantity']);
            }

            if ($pointsUsed > 0) {
                $buyer->decrement('points', $pointsUsed);
            }

            $referral = ReferralLink::with('referrer')
                ->where('referred_id', $buyer->user_id)
                ->first();

            if ($referral && $referral->referrer && $referral->referrer->role !== 'superadmin') {
                $commissionTotal = 0.0;
                foreach ($lineItems as $line) {
                    $product = $productsLocked[$line['product_id']];
                    $rate = ProductCommission::resolvedRate($product);
                    $commissionTotal += ((float) $line['subtotal']) * $rate / 100.0;
                }
                $commissionTotal = round($commissionTotal, 2);
                $pointsToReferrer = (int) round($commissionTotal);

                Commission::create([
                    'referral_id' => $referral->id,
                    'order_id' => $order->order_id,
                    'commission_earned' => number_format($commissionTotal, 2, '.', ''),
                    'date_earned' => now()->toDateString(),
                    'status' => 'pending',
                ]);

                if ($pointsToReferrer > 0) {
                    $referral->referrer->increment('points', $pointsToReferrer);
                }
            }
        });

        $order->load(['buyer', 'details.product']);

        return response()->json([
            'message' => 'Order created',
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Order::query()
            ->with(['buyer', 'details.product'])
            ->orderByDesc('order_id');

        if (! $this->isStaff($user)) {
            $query->where('buyer_id', $user->user_id);
        }

        if ($request->filled('status')) {
            $query->where('order_status', $request->query('status'));
        }

        $orders = $query->get()->map(fn (Order $order) => $this->formatOrder($order));

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $order = Order::with(['buyer', 'details.product'])
            ->where('order_id', $id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! $this->isStaff($user) && (int) $order->buyer_id !== (int) $user->user_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'order' => $this->formatOrder($order),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        if (! $this->isStaff($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'order_status' => 'required|in:'.implode(',', self::STATUSES),
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

        if ($newStatus === 'cancelled') {
            DB::transaction(function () use ($order) {
                $this->restoreCancelledOrder($order);
                $order->update(['order_status' => 'cancelled']);
            });
        } else {
            $order->update(['order_status' => $newStatus]);
        }

        $order->refresh()->load(['buyer', 'details.product']);

        return response()->json([
            'message' => 'Order updated',
            'order' => $this->formatOrder($order),
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

        DB::transaction(function () use ($order) {
            $this->restoreCancelledOrder($order);
            $order->update(['order_status' => 'cancelled']);
        });

        $order->refresh()->load(['buyer', 'details.product']);

        return response()->json([
            'message' => 'Order cancelled',
            'order' => $this->formatOrder($order),
        ]);
    }

    private function restoreCancelledOrder(Order $order): void
    {
        $order->loadMissing(['details', 'commissions.referral.referrer']);

        foreach ($order->details as $detail) {
            Product::where('product_id', $detail->product_id)
                ->increment('stock_quantity', $detail->quantity);
        }

        if ($order->points_used > 0) {
            User::where('user_id', $order->buyer_id)->increment('points', $order->points_used);
        }

        foreach ($order->commissions as $commission) {
            $referrer = $commission->referral?->referrer;
            if ($referrer) {
                $pts = (int) round((float) $commission->commission_earned);
                $referrer->refresh();
                $referrer->update([
                    'points' => max(0, $referrer->points - $pts),
                ]);
            }
            $commission->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(Order $order): array
    {
        $order->loadMissing(['buyer', 'details.product']);

        $buyer = $order->buyer;

        return [
            'order_id' => $order->order_id,
            'buyer_id' => $order->buyer_id,
            'buyer_name' => $buyer
                ? trim(($buyer->first_name ?? '').' '.($buyer->last_name ?? ''))
                : null,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'total_amount' => $order->total_amount,
            'payment_action' => $order->payment_action,
            'order_status' => $order->order_status,
            'shipping_address' => $order->shipping_address,
            'points_used' => $order->points_used,
            'cod_amount' => $order->cod_amount,
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
