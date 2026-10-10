<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public registration is intentionally removed (routes/auth.php): accounts come from the owner
 * account or from an administrator's invitation (Phase 2 slice 1c). These tests replace the
 * starter kit's registration tests, which asserted the opposite and failed on every run.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_registration_page(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register_themselves(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'a-long-test-password-1',
            'password_confirmation' => 'a-long-test-password-1',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
