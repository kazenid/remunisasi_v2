<?php

namespace App\Services;

use App\Models\DatabaseNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Satu-satunya penulis notifikasi (desain §5/§11): persist baris sebagai sumber
 * kebenaran DAN publish sinyal ringan best-effort ke channel Redis per-user.
 * Kegagalan Redis TIDAK PERNAH boleh menggagalkan request HTTP maupun test.
 */
final class NotificationService
{
    /**
     * Buat baris notifikasi untuk User (resolusi via sikep_user_id) lalu
     * publish sinyal realtime best-effort.
     *
     * @param  array<string,mixed>  $extra
     */
    public function notify(int $sikepUserId, string $type, string $message, string $url, array $extra = []): void
    {
        $user = User::query()->where('sikep_user_id', $sikepUserId)->first();
        if ($user === null) {
            Log::info('Notifikasi dilewati: user lokal tidak ditemukan', [
                'sikep_user_id' => $sikepUserId,
                'type' => $type,
            ]);

            return;
        }

        $payload = array_merge(['type' => $type, 'message' => $message, 'url' => $url], $extra);

        // (a) SUMBER KEBENARAN: baris persisten.
        $notification = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $payload,
        ]);

        // (b) BEST-EFFORT: sinyal realtime. `url` dibaca ulang dari variabel yang
        //     sama (single-source, §3.2) — jangan panggil route() lagi di sini.
        $this->publish($sikepUserId, self::signalFor($notification));
    }

    /**
     * Bentuk sinyal ringan {id,type,message,url,task_id,created_at}. Dipakai
     * bersama oleh publish Pub/Sub dan catch-up stream SSE agar payload identik.
     *
     * @return array<string,mixed>
     */
    public static function signalFor(DatabaseNotification $n): array
    {
        $data = $n->data ?? [];

        return [
            'id' => $n->id,
            'type' => $data['type'] ?? $n->type,
            'message' => $data['message'] ?? '',
            'url' => $data['url'] ?? '',
            'task_id' => $data['task_id'] ?? null,
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }

    /**
     * Publish sinyal ke channel Redis user. Kegagalan ditangkap + dicatat,
     * tidak pernah dilempar (NFR-1).
     *
     * @param  array<string,mixed>  $signal
     */
    private function publish(int $sikepUserId, array $signal): void
    {
        try {
            Redis::connection('notifications')
                ->publish("notif.user.{$sikepUserId}", json_encode($signal, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::warning('Publish notifikasi Redis gagal (diabaikan)', [
                'sikep_user_id' => $sikepUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
