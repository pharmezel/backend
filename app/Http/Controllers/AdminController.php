<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
