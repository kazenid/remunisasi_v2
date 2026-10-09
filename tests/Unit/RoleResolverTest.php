<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Sikep\RoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_aktif_resolve_sebagai_admin(): void
    {
        User::factory()->admin()->create(['sikep_user_id' => 999999]);

        $result = app(RoleResolver::class)->resolve(999999);

        $this->assertTrue($result['is_admin']);
        $this->assertFalse($result['is_katimker']);
        $this->assertFalse($result['is_kabid']);
        $this->assertSame(['admin'], $result['roles']);
    }

    public function test_katimker_aktif_resolve_sebagai_katimker(): void
    {
        User::factory()->katimker()->create(['sikep_user_id' => 500]);

        $result = app(RoleResolver::class)->resolve(500);

        $this->assertTrue($result['is_katimker']);
        $this->assertFalse($result['is_admin']);
        $this->assertFalse($result['is_kabid']);
        $this->assertSame(['katimker'], $result['roles']);
    }

    public function test_user_nonaktif_resolve_tanpa_peran(): void
    {
        User::factory()->katimker()->nonaktif()->create(['sikep_user_id' => 501]);

        $result = app(RoleResolver::class)->resolve(501);

        $this->assertSame([], $result['roles']);
        $this->assertFalse($result['is_katimker']);
        $this->assertFalse($result['is_kabid']);
        $this->assertFalse($result['is_admin']);
    }

    public function test_user_tanpa_role_resolve_tanpa_peran(): void
    {
        User::factory()->create(['sikep_user_id' => 502, 'role' => null, 'aktif' => true]);

        $result = app(RoleResolver::class)->resolve(502);

        $this->assertSame([], $result['roles']);
    }

    public function test_user_tidak_ada_resolve_tanpa_peran(): void
    {
        $result = app(RoleResolver::class)->resolve(123456);

        $this->assertSame([], $result['roles']);
        $this->assertFalse($result['is_admin']);
    }
}
