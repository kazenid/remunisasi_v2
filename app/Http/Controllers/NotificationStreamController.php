<?php

namespace App\Http\Controllers;

use App\Models\DatabaseNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint SSE: subscribe ke channel Redis per-user dan push sinyal ke browser.
 * Loop deterministik dengan read-timeout 5 dtk + heartbeat wall-clock, dan
 * rebuild koneksi setiap read-timeout RedisException agar subscribe() selalu
 * dijalankan di socket command-ready (desain §6.2).
 *
 * Pub/Sub Redis tidak menyimpan pesan, sedangkan tiap rebuild koneksi membuka
 * celah singkat. Karena itu SEBELUM tiap subscribe() dilakukan catch-up dari
 * tabel `notifications` (sumber kebenaran): baris milik user dengan
 * created_at > cursor dikirim ulang (dedup berdasar id). Cursor awal berasal
 * dari header `Last-Event-ID` (dikirim otomatis EventSource saat reconnect).
 */
class NotificationStreamController extends Controller
{
    /** OPT_READ_TIMEOUT subscribe (dtk): batas satu putaran subscribe(). */
    private const TICK_SECONDS = 5;

    /** Maksimal baris catch-up per putaran. */
    private const CATCHUP_LIMIT = 50;

    /** Maksimal id yang diingat untuk dedup Pub/Sub vs catch-up. */
    private const SEEN_MAX = 100;

    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $sikepUserId = (int) $user->sikep_user_id;
        // Cursor ditentukan SINKRON sebelum stream dimulai (koneksi DB request ini).
        $cursor = $this->resolveCursor($request->header('Last-Event-ID'), $userId);

        $response = new StreamedResponse(function () use ($sikepUserId, $userId, $cursor) {
            $this->prepareOutput();                           // nonaktifkan batas waktu + akhiri buffer

            $this->sse('retry: 5000');                        // jeda reconnect browser (ms)
            $this->sse(': connected');                        // komentar awal → buka stream

            $channel = "notif.user.{$sikepUserId}";
            $hb = max(1, (int) config('app.sse_heartbeat', 20)); // interval heartbeat (dtk)
            $last = time();
            $seen = [];

            // Satu titik kirim event notifikasi; dedup agar pesan yang sama dari
            // Pub/Sub dan catch-up hanya terkirim sekali.
            $emit = function (string $json, ?string $id) use (&$seen) {
                if ($id !== null) {
                    if (isset($seen[$id])) {
                        return;
                    }
                    $seen[$id] = true;
                    if (count($seen) > self::SEEN_MAX) {
                        $seen = array_slice($seen, -self::SEEN_MAX, null, true);
                    }
                }
                $this->sse(($id !== null ? "id: {$id}\n" : '').'data: '.$json);
            };

            // Callback Pub/Sub: PhpRedisConnection::subscribe() memanggil
            // $callback($message, $channel) — DUA argumen (bukan tiga).
            $onMessage = function (string $message, string $chan) use ($emit) {
                $sig = json_decode($message, true);
                $emit($message, is_array($sig) && isset($sig['id']) ? (string) $sig['id'] : null);
            };

            // Catch-up dari DB: kirim baris yang lahir setelah cursor.
            $catchUp = function () use (&$cursor, $userId, $emit) {
                try {
                    $rows = DatabaseNotification::query()
                        ->where('notifiable_type', User::class)
                        ->where('notifiable_id', $userId)
                        ->where('created_at', '>', $cursor)
                        ->orderBy('created_at')
                        ->limit(self::CATCHUP_LIMIT)
                        ->get();

                    foreach ($rows as $n) {
                        $emit(json_encode(NotificationService::signalFor($n), JSON_UNESCAPED_UNICODE), (string) $n->id);
                        $cursor = $n->created_at->format('Y-m-d H:i:s.u'); // presisi mikrodetik
                    }
                } catch (\Throwable $e) {
                    Log::warning('SSE catch-up gagal', ['error' => $e->getMessage()]);
                }
            };

            try {
                [$connection, $client] = $this->connect();
            } catch (\Throwable $e) {
                // Browser akan reconnect otomatis (retry) dan onerror menyalakan polling.
                Log::warning('SSE: koneksi Redis gagal, stream diakhiri', ['error' => $e->getMessage()]);

                return;
            }

            try {
                while ($this->shouldContinue()) {
                    $catchUp();                               // SEBELUM subscribe (urutan penting)

                    try {
                        // Blok maksimal TICK_SECONDS: kembali/melempar agar loop luar berputar.
                        $connection->subscribe([$channel], $onMessage);
                    } catch (\RedisException $e) {
                        // Error di dalam callback muncul sebagai previous; jangan ditelan diam-diam.
                        if ($e->getPrevious() !== null) {
                            Log::warning('SSE callback gagal', ['error' => $e->getPrevious()->getMessage()]);
                        }
                        // Tick idle ATAU transien: socket subscriber bisa tertinggal rusak
                        // → DISCARD + REBUILD sebelum subscribe() berikutnya.
                        try {
                            [$connection, $client] = $this->connect();
                        } catch (\Throwable $e2) {
                            Log::warning('SSE: rebuild koneksi Redis gagal, stream diakhiri', ['error' => $e2->getMessage()]);
                            break;
                        }
                    } catch (\Throwable $e) {
                        Log::debug('SSE subscribe berhenti', ['error' => $e->getMessage()]);
                        break;
                    }

                    if (time() - $last >= $hb) {   // heartbeat wall-clock, independen dari timeout
                        $this->sse(': ping');
                        $last = time();
                    }
                }
            } finally {
                try {
                    $client->close();              // SATU titik close
                } catch (\Throwable $e) {
                    // abaikan
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * (Re)bangun koneksi bersih + set OPT_READ_TIMEOUT. Seam untuk pengujian.
     *
     * @return array{0: object, 1: \Redis}
     */
    protected function connect(): array
    {
        Redis::purge('notifications');                    // buang instance ter-cache (socket lama)
        $connection = Redis::connection('notifications');
        $client = $connection->client();
        $client->setOption(\Redis::OPT_READ_TIMEOUT, self::TICK_SECONDS); // tiap subscribe ≤ TICK_SECONDS

        return [$connection, $client];
    }

    /** Siapkan output stream. Seam untuk pengujian (buffer PHPUnit). */
    protected function prepareOutput(): void
    {
        @set_time_limit(0);                               // nonaktifkan batas eksekusi stream
        while (ob_get_level() > 0) {
            @ob_end_flush();                              // akhiri semua buffer SEKALI
        }
    }

    /** Loop berlanjut selama klien belum memutus koneksi. */
    protected function shouldContinue(): bool
    {
        return ! connection_aborted();
    }

    /** Satu titik echo+flush; tidak menumpuk string di memori. */
    protected function sse(string $line): void
    {
        echo $line."\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Cursor catch-up: created_at notifikasi `Last-Event-ID` bila valid dan milik
     * user ini; selain itu sekarang. Format string menjaga presisi mikrodetik
     * (query builder memotong DateTimeInterface ke detik).
     */
    private function resolveCursor(?string $lastEventId, int $userId): string
    {
        if ($lastEventId !== null && Str::isUuid($lastEventId)) {
            $n = DatabaseNotification::query()
                ->where('id', $lastEventId)
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $userId)
                ->first();
            if ($n !== null) {
                return $n->created_at->format('Y-m-d H:i:s.u');
            }
        }

        return now()->format('Y-m-d H:i:s.u');
    }
}
