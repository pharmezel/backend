<?php

namespace App\Http\Controllers;

use App\Models\ReferralLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superadmin-only user administration.
 *
 * Lists users with optional role/search filters, exposes referral network graphs,
 * and updates roles between buyer and admin (superadmin accounts cannot be changed).
 */
class AdminController extends Controller
{
    private function requireSuperadmin(Request $request): ?JsonResponse
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    public function indexUsers(Request $request)
    {
        if ($deny = $this->requireSuperadmin($request)) {
            return $deny;
        }

        $request->validate([
            'role' => 'nullable|in:buyer,admin,superadmin',
            'search' => 'nullable|string|max:255',
        ]);

        $query = User::query()->withCount('referralsMade as total_referrals');

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $users = $query->orderBy('user_id')->get();

        $payload = $users->map(fn (User $u) => [
            'user_id' => $u->user_id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $u->email,
            'role' => $u->role,
            'points' => $u->points,
            'date_registered' => $u->date_registered?->format('Y-m-d H:i:s'),
            'referral_code' => $u->referral_code,
            'total_referrals' => (int) $u->total_referrals,
        ]);

        return response()->json([
            'users' => $payload,
        ]);
    }

    /**
     * Referral network tree for superadmin (roots = users with no referrer link).
     */
    public function usersNetwork(Request $request): JsonResponse
    {
        if ($deny = $this->requireSuperadmin($request)) {
            return $deny;
        }

        $maxDepth = 50;

        $links = ReferralLink::query()->get(['referrer_id', 'referred_id']);
        $users = User::query()
            ->select(['user_id', 'first_name', 'last_name', 'email', 'contact_number', 'role', 'referral_code'])
            ->orderBy('user_id')
            ->get();

        $childrenMap = [];
        $referrerOf = [];
        $referredIds = [];

        foreach ($links as $link) {
            $childrenMap[$link->referrer_id][] = $link->referred_id;
            $referrerOf[$link->referred_id] = $link->referrer_id;
            $referredIds[$link->referred_id] = true;
        }

        $userById = $users->keyBy('user_id');

        $buildNode = function (int $userId, int $depth, array $visited) use (
            &$buildNode,
            $userById,
            $childrenMap,
            $referrerOf,
            $maxDepth
): ?array {
            if ($depth > $maxDepth || isset($visited[$userId])) {
                return null;
            }

            $user = $userById->get($userId);
            if (! $user) {
                return null;
            }

            $visited[$userId] = true;
            $childIds = $childrenMap[$userId] ?? [];
            $referrals = [];

            foreach ($childIds as $childId) {
                $child = $buildNode((int) $childId, $depth + 1, $visited);
                if ($child) {
                    $referrals[] = $child;
                }
            }

            return [
                'id' => $user->user_id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'phone' => $user->contact_number,
                'role' => $user->role,
                'referral_code' => $user->referral_code,
                'referrer_id' => $referrerOf[$user->user_id] ?? null,
                'referral_count' => count($childIds),
                'referrals' => $referrals,
            ];
        };

        $network = [];
        foreach ($users as $user) {
            if (! isset($referredIds[$user->user_id])) {
                $node = $buildNode((int) $user->user_id, 0, []);
                if ($node) {
                    $network[] = $node;
                }
            }
        }

        return response()->json([
            'network' => $network,
            'stats' => [
                'total_users' => $users->count(),
                'total_roots' => count($network),
                'total_referral_links' => $links->count(),
            ],
        ]);
    }

    public function updateRole(Request $request, $id)
    {
        if ($deny = $this->requireSuperadmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'role' => 'required|in:buyer,admin',
        ]);

        $user = User::where('user_id', $id)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->role === 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user->update(['role' => $validated['role']]);

        return response()->json([
            'message' => 'Role updated',
            'user_id' => $user->user_id,
            'role' => $user->role,
        ]);
    }
}
