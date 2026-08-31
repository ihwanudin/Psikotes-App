<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_the_participant_flow_not_fortify_signup(): void
    {
        Branch::query()->create([
            'code' => 'TEST-CENTRAL',
            'name' => 'Synthetic central branch',
            'ref_code' => 'TEST-CENTRAL-REF',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertFalse(Features::enabled(Features::registration()));
        $this->get(route('register'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/create')
                ->where('assignedBranch.name', 'Synthetic central branch')
                ->has('registrationToken')
                ->has('consents.psychotest')
            );
        $this->assertGuest();
    }

    public function test_fortify_signup_cannot_create_a_general_user_account(): void
    {
        $this->assertFalse(Route::has('register.store'));
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(405);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_general_signup_payload_cannot_bypass_participant_requirements(): void
    {
        $this->postJson(route('registrations.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('participants', 0);
    }
}
