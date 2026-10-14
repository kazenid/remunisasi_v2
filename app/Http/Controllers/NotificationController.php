<?php

namespace App\Http\Controllers;

use App\Models\DatabaseNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REST notifikasi: daftar (polling fallback / refresh panel), tandai satu
 * terbaca, tandai semua terbaca (desain §7). Semua di grup auth.
 */
class NotificationController extends Controller
{
    /** Daftar hingga 20 notifikasi terbaru untuk user auth. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // `latest()` deterministik karena created_at mikrodetik (§3.1).
        $items = $user->notifications()->latest()->limit(20)->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->data['type'] ?? $n->type,
            'message' => $n->data['message'] ?? '',
            'url' => $n->data['url'] ?? '#',
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ]);

        return response()->json([
            'notifications' => $items,
            'has_unread' => $user->unreadNotifications()->exists(),
        ]);
    }

    /** Tandai satu notifikasi milik user auth sebagai terbaca. */
    public function markRead(Request $request, DatabaseNotification $notification): Response
    {
        abort_unless(
            $notification->notifiable_id === $request->user()->id
            && $notification->notifiable_type === User::class,
            403,
        );

        $notification->markAsRead();

        return response()->noContent();
    }

    /** Tandai semua notifikasi belum-dibaca milik user auth sebagai terbaca. */
    public function markAllRead(Request $request): Response
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->noContent();
    }
}
