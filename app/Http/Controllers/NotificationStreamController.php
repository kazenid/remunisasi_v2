<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint SSE: subscribe ke channel Redis per-user dan push sinyal ke browser.
 * Loop deterministik dengan read-timeout 1 dtk + heartbeat wall-clock, dan
 * rebuild koneksi setiap read-timeout RedisException agar subscribe() selalu
 * dijalankan di socket command-ready (desain §6.2).
 */
class NotificationStreamController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $sikepUserId = (int) $request->user()->sikep_user_id;

        $response = new StreamedResponse(function () use ($sikepUserId) {
            @set_time_limit(0);                               // nonaktifkan batas eksekusi stream
            while (ob_get_level() > 0) {
                @ob_end_flush();                              // akhiri semua buffer SEKALI
            }

            $this->sse(': connected');                        // komentar awal → buka stream

            $channel = "notif.user.{$sikepUserId}";
            $hb = (int) config('database.redis.notifications.read_timeout', 20); // interval heartbeat (dtk)
            $last = time();

            // Helper lokal: (re)bangun koneksi bersih + set OPT_READ_TIMEOUT=1 dtk.
            $connect = function () {
                Redis::purge('notifications');                // buang instance ter-cache (socket lama)
                $connection = Redis::connection('notifications');
                $client = $connection->client();
                $client->setOption(\Redis::OPT_READ_TIMEOUT, 1); // DETERMINISTIK: tiap subscribe ≤1 dtk

                return [$connection, $client];
            };

            [$connection, $client] = $connect();

            try {
                while (! connection_aborted()) {
                    try {
                        // Blok maksimal ~1 dtk: kembali/melempar cepat agar loop luar berputar.
                        $connection->subscribe([$channel], function ($redis, $chan, $message) {
                            $this->sse('data: '.$message);
                        });
                    } catch (\RedisException $e) {
                        // Tick idle 1 dtk ATAU transien: socket subscriber bisa tertinggal
                        // rusak → DISCARD + REBUILD sebelum subscribe() berikutnya.
                        [$connection, $client] = $connect();
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

    /** Satu titik echo+flush; tidak menumpuk string di memori. */
    private function sse(string $line): void
    {
        echo $line."\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }
}
