<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_set_role_admin_menyelaraskan_is_admin_true(): void
    {
        $user = new User;
        $user->setRole(User::ROLE_ADMIN);

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_admin);
    }

    public function test_set_role_non_admin_menyelaraskan_is_admin_false(): void
    {
        $user = new User;
        $user->is_admin = true;
        $user->setRole(User::ROLE_KATIMKER);

        $this->assertSame('katimker', $user->role);
        $this->assertFalse($user->is_admin);
    }

    public function test_set_role_null_mencabut_admin(): void
    {
        $user = new User;
        $user->setRole(User::ROLE_ADMIN);
        $user->setRole(null);

        $this->assertNull($user->role);
        $this->assertFalse($user->is_admin);
    }
}
