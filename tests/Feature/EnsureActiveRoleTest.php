<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penegakan sesi berjalan oleh EnsureActiveRole: user yang dinonaktifkan atau
 * kehilangan peran di tengah sesi di-logout pada request berikutnya. RoleResolver
 * NYATA (membaca kolom lokal) — tanpa mock.
 */
class EnsureActiveRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_dinonaktifkan_di_tengah_sesi_dilogout(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 600]);
        $this->actingAs($user);

        // Akses awal berhasil (route ber-peran katimker).
        $this->get(route('katimker.tugas.index'))->assertOk();

        // Dinonaktifkan di tengah sesi.
        $user->update(['aktif' => false]);

        $response = $this->get(route('katimker.tugas.index'));
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_role_dicabut_di_tengah_sesi_dilogout(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 601]);
        $this->actingAs($user);

        $this->get(route('katimker.tugas.index'))->assertOk();

        // role=null di tengah sesi.
        $user->update(['role' => null]);

        $this->get(route('katimker.tugas.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_dashboard_tanpa_role_tercakup_penegakan(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 602]);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk();

        $user->update(['aktif' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_request_inertia_get_menampilkan_pesan_via_errors_login(): void
    {
        $user = User::factory()->katimker()->create(['sikep_user_id' => 603]);
        $this->actingAs($user);
        $user->update(['aktif' => false]);

        $response = $this->get(route('dashboard'), ['X-Inertia' => 'true']);

        // Inertia mengubah redirect pada request GET-nya menjadi 409 + header lokasi
        // (klien memuat ulang penuh). Yang penting: user di-logout dan pesan di-flash
        // ke bag login agar tampil di halaman login.
        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location');
        $response->assertSessionHasErrors(['login' => EnsureActiveRole::MESSAGE]);
        $this->assertGuest();
    }
}
