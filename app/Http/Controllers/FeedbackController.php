<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;

/**
 * User feedback and superadmin moderation.
 *
 * Buyers and admins submit feedback tickets. Superadmin lists all tickets, replies, and
 * updates status. Users can view their own submissions via `mine`.
 */
class FeedbackController extends Controller
{
    private function formatFeedback(Feedback $f, bool $includeUser = false): array
    {
        $f->loadMissing('user');
        $row = [
            'id' => $f->id,
            'subject' => $f->subject,
            'message' => $f->message,
            'category' => $f->category,
            'admin_reply' => $f->admin_reply,
            'status' => $f->status,
            'created_at' => $f->created_at?->format('Y-m-d H:i'),
            'updated_at' => $f->updated_at?->format('Y-m-d H:i'),
        ];

        if ($includeUser && $f->user) {
            $row['user'] = [
                'user_id' => $f->user->user_id,
                'name' => trim($f->user->first_name.' '.$f->user->last_name),
                'email' => $f->user->email,
                'phone' => $f->user->contact_number,
                'role' => $f->user->role,
            ];
        }

        return $row;
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        if (! in_array($auth->role, ['buyer', 'admin'], true)) {
            return response()->json(['message' => 'Only buyers and admins can submit feedback.'], 403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
            'category' => 'nullable|string|max:80',
        ]);

        $feedback = Feedback::create([
            'user_id' => $auth->user_id,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'category' => $validated['category'] ?? null,
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Thank you for your feedback.',
            'feedback' => $this->formatFeedback($feedback),
        ], 201);
    }

    public function mine(Request $request)
    {
        $auth = $request->user();
        if (! in_array($auth->role, ['buyer', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $items = Feedback::where('user_id', $auth->user_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($f) => $this->formatFeedback($f));

        return response()->json(['feedback' => $items]);
    }

    public function adminIndex(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $validated = $request->validate([
                'status' => 'nullable|in:open,replied,resolved',
            ]);

            $query = Feedback::with('user')->orderByDesc('created_at');
            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $items = $query->get()->map(fn ($f) => $this->formatFeedback($f, true));

            $counts = [
                'open' => Feedback::where('status', 'open')->count(),
                'replied' => Feedback::where('status', 'replied')->count(),
                'resolved' => Feedback::where('status', 'resolved')->count(),
            ];

            return response()->json([
                'feedback' => $items,
                'counts' => $counts,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not load feedback.',
                'feedback' => [],
                'counts' => ['open' => 0, 'replied' => 0, 'resolved' => 0],
            ], 500);
        }
    }

    public function adminUpdate(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $feedback = Feedback::with('user')->find($id);
        if (! $feedback) {
            return response()->json(['message' => 'Feedback not found.'], 404);
        }

        $validated = $request->validate([
            'admin_reply' => 'nullable|string|max:5000',
            'status' => 'nullable|in:open,replied,resolved',
        ]);

        if (array_key_exists('admin_reply', $validated)) {
            $feedback->admin_reply = $validated['admin_reply'];
        }
        if (! empty($validated['status'])) {
            $feedback->status = $validated['status'];
        } elseif (! empty($validated['admin_reply']) && $feedback->status === 'open') {
            $feedback->status = 'replied';
        }

        $feedback->save();

        return response()->json([
            'message' => 'Feedback updated.',
            'feedback' => $this->formatFeedback($feedback->fresh('user'), true),
        ]);
    }
}
