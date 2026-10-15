<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationStreamController;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Sikep\RoleResolver;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Stream SSE notifikasi tanpa Redis nyata: controller diganti subclass yang
 * meng-override seam `connect()/prepareOutput()/sse()` dengan koneksi palsu
 * yang meniru PhpRedisConnection::subscribe() (callback dipanggil DUA argumen:
 * $message, $channel). Tes 1 gagal bila callback dikembalikan ke 3 argumen.
 */
class NotificationStreamTest extends TestCase
{
    use RefreshDatabase;

    private int $kabidId = 700;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function actingAsKabid(): User
    {
        $user = User::factory()->create(['sikep_user_id' => $this->kabidId]);
        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => ['kabid'],
                'is_katimker' => false,
                'is_kabid' => true,
                'is_admin' => false,
            ]);
        });
        $this->actingAs($user);

        return $user;
    }

    /** Buat baris notifikasi untuk user dengan created_at eksplisit (urutan deterministik). */
    private function buatNotif(User $user, string $pesan, string $waktu): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'task.submitted',
            'data' => ['type' => 'task.submitted', 'message' => $pesan, 'url' => '/x', 'task_id' => 1],
            'created_at' => Carbon::parse($waktu),
        ]);
    }

    /**
     * Ikat controller palsu. $subscribe dipanggil tiap subscribe() dengan
     * ($callback, $nomorPanggilan); callback asli dipanggil lewat helper agar
     * persis dua argumen seperti wrapper Laravel.
     *
     * @param  Closure(Closure, int): void  $subscribe
     * @param  Closure(int): void|null  $onConnect
     */
    private function bindFake(Closure $subscribe, ?Closure $onConnect = null): object
    {
        $state = new \stdClass;
        $state->connects = 0;
        $state->subscribes = 0;

        $this->app->bind(NotificationStreamController::class, function () use ($subscribe, $onConnect, $state) {
            return new class($subscribe, $onConnect, $state) extends NotificationStreamController
            {
                public function __construct(private Closure $subscribe, private ?Closure $onConnect, private \stdClass $state) {}

                protected function connect(): array
                {
                    $this->state->connects++;
                    if ($this->onConnect) {
                        ($this->onConnect)($this->state->connects);
                    }
                    $state = $this->state;
                    $subscribe = $this->subscribe;

                    $connection = new class($subscribe, $state)
                    {
                        public function __construct(private Closure $subscribe, private \stdClass $state) {}

                        public function subscribe(array $channels, Closure $callback): void
                        {
                            $this->state->subscribes++;
                            ($this->subscribe)(fn (string $message) => $callback($message, $channels[0]), $this->state->subscribes);
                        }
                    };
                    $client = new class
                    {
                        public function close(): void {}
                    };

                    return [$connection, $client];
                }

                protected function prepareOutput(): void {}

                protected function sse(string $line): void
                {
                    echo $line."\n\n";
                }
            };
        });

        return $state;
    }

    private function sinyal(string $id, string $pesan = 'Halo'): string
    {
        return json_encode([
            'id' => $id, 'type' => 'task.submitted', 'message' => $pesan, 'url' => '/x',
            'task_id' => 1, 'created_at' => now()->toIso8601String(),
        ]);
    }

    public function test_callback_subscribe_menerima_dua_argumen_dan_meneruskan_pesan(): void
    {
        $this->actingAsKabid();
        $id = (string) Str::uuid();
        $this->bindFake(function (Closure $callback) use ($id) {
            $callback($this->sinyal($id));
            throw new \RuntimeException('stop');
        });

        $out = $this->get(route('notifications.stream'))->streamedContent();

        $this->assertStringContainsString("id: {$id}\ndata: ", $out);
        $this->assertStringContainsString('"message":"Halo"', $out);
        $this->assertStringContainsString('retry: 5000', $out);
        $this->assertStringContainsString(': connected', $out);
    }

    public function test_pesan_duplikat_hanya_dikirim_sekali(): void
    {
        $this->actingAsKabid();
        $id = (string) Str::uuid();
        $this->bindFake(function (Closure $callback) use ($id) {
            $callback($this->sinyal($id));
            $callback($this->sinyal($id));
            throw new \RuntimeException('stop');
        });

        $out = $this->get(route('notifications.stream'))->streamedContent();

        $this->assertSame(1, substr_count($out, 'data: '));
    }

    public function test_catch_up_mengirim_notifikasi_setelah_last_event_id(): void
    {
        $user = $this->actingAsKabid();
        $a = $this->buatNotif($user, 'Notif A', '2026-01-01 10:00:00.100000');
        $b = $this->buatNotif($user, 'Notif B', '2026-01-01 10:00:01.200000');
        $c = $this->buatNotif($user, 'Notif C', '2026-01-01 10:00:02.300000');
        $this->bindFake(function () {
            throw new \RuntimeException('stop');
        });

        $out = $this->withHeaders(['Last-Event-ID' => $a->id])
            ->get(route('notifications.stream'))->streamedContent();

        $this->assertStringNotContainsString('Notif A', $out);
        $this->assertStringContainsString("id: {$b->id}", $out);
        $this->assertStringContainsString("id: {$c->id}", $out);
        $this->assertLessThan(strpos($out, 'Notif C'), strpos($out, 'Notif B'));
    }

    public function test_catch_up_tidak_mengirim_ulang_yang_sudah_dikirim_pubsub(): void
    {
        $user = $this->actingAsKabid();
        $a = $this->buatNotif($user, 'Notif A', '2026-01-01 10:00:00.100000');
        $b = $this->buatNotif($user, 'Notif B', '2026-01-01 10:00:01.200000');
        $this->bindFake(function (Closure $callback, int $n) use ($b) {
            if ($n === 1) {
                $callback($this->sinyal($b->id, 'Notif B')); // Pub/Sub lebih dulu
            }
            throw new \RuntimeException('stop');
        });

        $out = $this->withHeaders(['Last-Event-ID' => $a->id])
            ->get(route('notifications.stream'))->streamedContent();

        // Catch-up mengirim B sebelum subscribe; Pub/Sub dengan id sama diserap dedup.
        $this->assertSame(1, substr_count($out, "id: {$b->id}"));
    }

    public function test_last_event_id_milik_user_lain_diabaikan(): void
    {
        $this->actingAsKabid();
        $lain = User::factory()->create(['sikep_user_id' => 999]);
        $milikLain = $this->buatNotif($lain, 'Rahasia user lain', '2026-01-01 10:00:00.100000');
        $this->buatNotif($lain, 'Rahasia kedua', '2026-01-01 10:00:01.100000');
        $this->bindFake(function () {
            throw new \RuntimeException('stop');
        });

        $out = $this->withHeaders(['Last-Event-ID' => $milikLain->id])
            ->get(route('notifications.stream'))->streamedContent();

        $this->assertStringNotContainsString('Rahasia', $out);
        $this->assertStringNotContainsString('data: ', $out);
    }

    public function test_tick_redis_exception_membangun_ulang_koneksi_lalu_menerima_pesan(): void
    {
        if (! class_exists(\RedisException::class)) {
            $this->markTestSkipped('Ekstensi redis tidak tersedia.');
        }

        $this->actingAsKabid();
        $id = (string) Str::uuid();
        $state = $this->bindFake(function (Closure $callback, int $n) use ($id) {
            if ($n === 1) {
                throw new \RedisException('read error on connection'); // tick idle
            }
            $callback($this->sinyal($id));
            throw new \RuntimeException('stop');
        });

        $out = $this->get(route('notifications.stream'))->streamedContent();

        $this->assertSame(2, $state->connects);
        $this->assertStringContainsString("id: {$id}", $out);
    }

    public function test_koneksi_redis_gagal_mengakhiri_stream_tanpa_exception(): void
    {
        $this->actingAsKabid();
        $state = $this->bindFake(fn () => null, function () {
            throw new \RuntimeException('connection refused');
        });

        $response = $this->get(route('notifications.stream'));
        $out = $response->streamedContent();

        $response->assertOk();
        $this->assertSame(1, $state->connects);
        $this->assertStringContainsString('retry: 5000', $out);
        $this->assertStringContainsString(': connected', $out);
        $this->assertStringNotContainsString('data: ', $out);
    }

    public function test_signal_for_memakai_bentuk_payload_yang_sama_dengan_publish(): void
    {
        $user = $this->actingAsKabid();
        $n = $this->buatNotif($user, 'Tes sinyal', '2026-01-01 10:00:00.100000');

        $sinyal = NotificationService::signalFor($n);

        $this->assertSame(['id', 'type', 'message', 'url', 'task_id', 'created_at'], array_keys($sinyal));
        $this->assertSame($n->id, $sinyal['id']);
        $this->assertSame('task.submitted', $sinyal['type']);
        $this->assertSame('Tes sinyal', $sinyal['message']);
        $this->assertSame('/x', $sinyal['url']);
        $this->assertSame(1, $sinyal['task_id']);
        $this->assertSame($n->created_at->toIso8601String(), $sinyal['created_at']);
    }

    /**
     * Integrasi Redis nyata (opsional): hanya bila REDIS_INTEGRATION=1, ekstensi
     * redis + pcntl tersedia, dan server Redis terjangkau. Dijalankan di container.
     */
    public function test_integrasi_redis_nyata_menerima_publish(): void
    {
        if (! (extension_loaded('redis') && function_exists('pcntl_fork') && getenv('REDIS_INTEGRATION') === '1')) {
            $this->markTestSkipped('Integrasi Redis nyata dilewati (butuh ext redis+pcntl dan REDIS_INTEGRATION=1).');
        }

        try {
            \Illuminate\Support\Facades\Redis::connection('notifications')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Server Redis tidak terjangkau: '.$e->getMessage());
        }

        $user = $this->actingAsKabid();
        $deadline = time() + 8;

        $this->app->bind(NotificationStreamController::class, fn () => new class($deadline) extends NotificationStreamController
        {
            public function __construct(private int $deadline) {}

            protected function prepareOutput(): void {}

            protected function sse(string $line): void
            {
                echo $line."\n\n";
                $this->terlihat = $this->terlihat || str_contains($line, 'data: ');
            }

            private bool $terlihat = false;

            protected function shouldContinue(): bool
            {
                return ! $this->terlihat && time() < $this->deadline;
            }
        });

        $pid = pcntl_fork();
        if ($pid === 0) {
            \Illuminate\Support\Facades\Redis::purge('notifications');
            sleep(1);
            \Illuminate\Support\Facades\Redis::connection('notifications')->publish(
                "notif.user.{$this->kabidId}",
                $this->sinyal((string) Str::uuid(), 'Integrasi')
            );
            posix_kill(getmypid(), SIGKILL);
        }

        $out = $this->get(route('notifications.stream'))->streamedContent();
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('Integrasi', $out);
    }
}
