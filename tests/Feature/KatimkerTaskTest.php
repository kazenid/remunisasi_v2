<?php

namespace Tests\Feature;

use App\Http\Requests\TaskDraftRequest;
use App\Models\TaskAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class KatimkerTaskTest extends TestCase
{
    use RefreshDatabase;

    private int $sikepId = 500;

    private function actingAsKatimker(?int $sikepUserId = null): User
    {
        $sikepUserId ??= $this->sikepId;
        $user = User::factory()->create(['sikep_user_id' => $sikepUserId]);

        $this->mock(RoleResolver::class, function ($m) {
            $m->shouldReceive('resolve')->andReturn([
                'roles' => ['katimker'],
                'is_katimker' => true, 'is_kabid' => false, 'is_admin' => false,
            ]);
        });

        $this->actingAs($user);

        return $user;
    }

    public function test_katimker_dapat_membuat_draf_dengan_durasi_menit(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06',
            'jenis' => 'utama',
            'judul' => 'Rapat tim',
            'durasi_jam' => 2,
            'durasi_menit' => 30,
            'foto_selfie' => [UploadedFile::fake()->image('selfie.jpg')],
        ])->assertRedirect(route('katimker.tugas.index'));

        $task = TaskReport::first();
        $this->assertNotNull($task);
        $this->assertSame(150, $task->durasi_diajukan_menit);
        $this->assertSame('draf', $task->status);
        $this->assertSame($this->sikepId, $task->katimker_sikep_user_id);
        $this->assertSame(1, $task->attachments()->where('tipe', 'foto_selfie')->count());
    }

    public function test_durasi_nol_ditolak(): void
    {
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'X',
                'durasi_jam' => 0, 'durasi_menit' => 0,
            ])
            ->assertSessionHasErrors('durasi_menit');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_dokumen_non_pdf_ditolak(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'X',
                'durasi_jam' => 1, 'durasi_menit' => 0,
                'dokumen' => [UploadedFile::fake()->create('data.txt', 10, 'text/plain')],
            ])
            ->assertSessionHasErrors('dokumen.0');
    }

    public function test_upload_bukti_campuran_membuat_beberapa_attachment(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Bukti campuran',
            'durasi_jam' => 1, 'durasi_menit' => 0,
            'bukti' => [
                UploadedFile::fake()->image('foto.jpg'),
                UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
            ],
        ])->assertRedirect(route('katimker.tugas.index'));

        $task = TaskReport::first();
        $this->assertNotNull($task);
        $this->assertSame(2, $task->attachments()->count());
        $this->assertSame(1, $task->attachments()->where('tipe', 'foto_selfie')->count());
        $this->assertSame(1, $task->attachments()->where('tipe', 'dokumen')->count());
    }

    public function test_bukti_tipe_tidak_didukung_ditolak(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'X',
                'durasi_jam' => 1, 'durasi_menit' => 0,
                'bukti' => [UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')],
            ])
            ->assertSessionHasErrors('bukti.0');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_bukti_melebihi_ukuran_ditolak(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'X',
                'durasi_jam' => 1, 'durasi_menit' => 0,
                'bukti' => [UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')], // > 10 MB
            ])
            ->assertSessionHasErrors('bukti.0');

        $this->assertSame(0, TaskReport::count());
    }

    public function test_selfie_oversize_menampilkan_pesan_ukuran(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Selfie besar',
                'durasi_jam' => 1, 'durasi_menit' => 0,
                'foto_selfie' => [UploadedFile::fake()->image('big.jpg')->size(6000)], // 6 MB > 5 MB
            ])
            ->assertSessionHasErrors('foto_selfie.0');

        // Pesan menyebut batas 5 MB dan BUKAN pesan generik "failed to upload".
        $pesan = session('errors')->get('foto_selfie.0')[0];
        $this->assertStringContainsString('5 MB', $pesan);
        $this->assertStringNotContainsStringIgnoringCase('failed to upload', $pesan);

        $this->assertSame(0, TaskReport::count());
    }

    public function test_bukti_oversize_menampilkan_pesan_ukuran(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->from(route('katimker.tugas.create'))
            ->post(route('katimker.tugas.store'), [
                'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Bukti besar',
                'durasi_jam' => 1, 'durasi_menit' => 0,
                'bukti' => [UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')], // > 10 MB
            ])
            ->assertSessionHasErrors('bukti.0');

        // Pesan menyebut batas 10 MB dan BUKAN pesan generik "failed to upload".
        $pesan = session('errors')->get('bukti.0')[0];
        $this->assertStringContainsString('10 MB', $pesan);
        $this->assertStringNotContainsStringIgnoringCase('failed to upload', $pesan);

        $this->assertSame(0, TaskReport::count());
    }

    public function test_pesan_uploaded_kustom_terdaftar_untuk_foto_selfie(): void
    {
        // Backstop Bug 1 kasus (b): bila PHP menolak file (UPLOAD_ERR_INI_SIZE), rule implisit
        // "uploaded" yang gagal — pesan kustomnya harus menyebut batas MB, bukan teks generik.
        $pesan = (new TaskDraftRequest)->messages();

        $this->assertArrayHasKey('foto_selfie.*.uploaded', $pesan);
        $this->assertStringContainsString('5 MB', $pesan['foto_selfie.*.uploaded']);
        $this->assertArrayHasKey('bukti.*.uploaded', $pesan);
        $this->assertStringContainsString('10 MB', $pesan['bukti.*.uploaded']);
    }

    public function test_edit_memuat_daftar_bukti_dengan_url(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Punya bukti',
            'durasi_jam' => 1, 'durasi_menit' => 0,
            'bukti' => [UploadedFile::fake()->image('foto.jpg')],
        ]);

        $task = TaskReport::first();

        $this->get(route('katimker.tugas.edit', $task->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Katimker/TaskForm')
                ->has('tugas.attachments', 1, fn (AssertableInertia $att) => $att
                    ->where('is_image', true)
                    ->whereNot('url', null)
                    ->where('ukuran_label', fn ($v) => is_string($v) && $v !== '')
                    ->etc()
                )
            );
    }

    public function test_hanya_pemilik_dapat_mengedit(): void
    {
        $task = TaskReport::create([
            'katimker_sikep_user_id' => 999, // bukan milik user login
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Milik orang lain',
            'durasi_diajukan_menit' => 60, 'status' => 'draf',
        ]);

        $this->actingAsKatimker(); // sikep 500

        $this->get(route('katimker.tugas.edit', $task->id))->assertForbidden();
    }

    public function test_draf_terkunci_dari_hapus_jika_bukan_draf(): void
    {
        $task = TaskReport::create([
            'katimker_sikep_user_id' => $this->sikepId,
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Sudah diajukan',
            'durasi_diajukan_menit' => 60, 'status' => 'diajukan',
        ]);

        $this->actingAsKatimker();

        $this->delete(route('katimker.tugas.destroy', $task->id))->assertForbidden();
        $this->assertDatabaseHas('task_reports', ['id' => $task->id]);
    }

    public function test_hapus_draf_menghapus_lampiran(): void
    {
        Storage::fake('local');
        $this->actingAsKatimker();

        $this->post(route('katimker.tugas.store'), [
            'tanggal' => '2026-10-06', 'jenis' => 'utama', 'judul' => 'Hapus saya',
            'durasi_jam' => 1, 'durasi_menit' => 0,
            'foto_selfie' => [UploadedFile::fake()->image('s.jpg')],
        ]);

        $task = TaskReport::first();
        $path = $task->attachments()->first()->path;
        Storage::disk('local')->assertExists($path);

        $this->delete(route('katimker.tugas.destroy', $task->id))->assertRedirect();

        $this->assertSame(0, TaskReport::count());
        $this->assertSame(0, TaskAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
