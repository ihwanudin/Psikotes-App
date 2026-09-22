<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Admins\IssueAdminPasswordSetupLink;
use App\Data\Admins\IssuedAdminPasswordSetupLink;
use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_the_show_page_renders_with_strict_csp_headers(): void
    {
        [, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);

        $response = $this->get(route('admin.password-setup.show', ['publicId' => $publicId]));

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_valid_token_sets_the_password_and_consumes_the_link(): void
    {
        [$admin, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);
        $token = $this->tokenFrom($link->url);

        $response = $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), [
            'token' => $token,
            'password' => 'Str0ngPassw0rd!Z',
            'password_confirmation' => 'Str0ngPassw0rd!Z',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('Str0ngPassw0rd!Z', $admin->refresh()->password));

        $setup = DB::table('admin_password_setup_tokens')->where('public_id', $publicId)->sole();
        $this->assertSame('CONSUMED', $setup->status);
        $this->assertNull($setup->active_marker);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.password_set', 'actor_type' => 'admin', 'subject_id' => (string) $admin->id,
        ]);
    }

    public function test_the_same_token_cannot_be_consumed_twice(): void
    {
        [, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);
        $token = $this->tokenFrom($link->url);
        $payload = ['token' => $token, 'password' => 'Str0ngPassw0rd!Z', 'password_confirmation' => 'Str0ngPassw0rd!Z'];

        $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), $payload)->assertOk();
        $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), $payload)->assertStatus(409);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);
        $token = $this->tokenFrom($link->url);

        DB::table('admin_password_setup_tokens')->where('public_id', $publicId)
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), [
            'token' => $token, 'password' => 'Str0ngPassw0rd!Z', 'password_confirmation' => 'Str0ngPassw0rd!Z',
        ])->assertStatus(410);
    }

    public function test_issuing_a_new_link_revokes_the_prior_active_one(): void
    {
        [$admin, $firstLink] = $this->issueLink();
        $firstPublicId = $this->publicIdFrom($firstLink->url);
        $firstToken = $this->tokenFrom($firstLink->url);

        app(IssueAdminPasswordSetupLink::class)->handle($admin->id, 'ops-2', 'admin.password_reset_link_issued');

        $this->postJson(route('admin.password-setup.consume', ['publicId' => $firstPublicId]), [
            'token' => $firstToken, 'password' => 'Str0ngPassw0rd!Z', 'password_confirmation' => 'Str0ngPassw0rd!Z',
        ])->assertStatus(409);

        $first = DB::table('admin_password_setup_tokens')->where('public_id', $firstPublicId)->sole();
        $this->assertSame('REVOKED', $first->status);
    }

    public function test_a_weak_password_is_rejected(): void
    {
        [, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);
        $token = $this->tokenFrom($link->url);

        $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), [
            'token' => $token, 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertStatus(422);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        [, $link] = $this->issueLink();
        $publicId = $this->publicIdFrom($link->url);

        $this->postJson(route('admin.password-setup.consume', ['publicId' => $publicId]), [
            'token' => str_repeat('x', 64), 'password' => 'Str0ngPassw0rd!Z', 'password_confirmation' => 'Str0ngPassw0rd!Z',
        ])->assertStatus(409);
    }

    /** @return array{0: Admin, 1: IssuedAdminPasswordSetupLink} */
    private function issueLink(): array
    {
        $admin = Admin::query()->create([
            'branch_id' => null,
            'name' => 'Admin', 'email' => 'admin-'.uniqid('', true).'@example.test',
            'password' => Hash::make(Str::random(40)),
            'role' => AdminRole::SuperAdmin,
        ]);

        $link = app(IssueAdminPasswordSetupLink::class)->handle($admin->id, 'ops-1', 'admin.password_setup_link_issued');

        return [$admin, $link];
    }

    private function publicIdFrom(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $segments = explode('/', trim((string) $path, '/'));

        return (string) end($segments);
    }

    private function tokenFrom(string $url): string
    {
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        parse_str($fragment, $parsed);
        $token = $parsed['token'] ?? '';

        return is_string($token) ? $token : '';
    }
}
