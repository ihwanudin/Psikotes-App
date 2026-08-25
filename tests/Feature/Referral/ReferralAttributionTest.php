<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Branch;
use App\Models\ReferralVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReferralAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_referral_cookie_wins_when_another_link_is_opened(): void
    {
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $branchA = $this->branch('A', 'REF-A');
        $this->branch('B', 'REF-B');

        $first = $this->get('/r/REF-A')
            ->assertRedirect('/register')
            ->assertCookie('psikotes_referral');
        $encryptedCookie = $first->getCookie('psikotes_referral', decrypt: false);
        $this->assertNotNull($encryptedCookie);

        $this->withUnencryptedCookie('psikotes_referral', $encryptedCookie->getValue())
            ->get('/r/REF-B')
            ->assertRedirect('/register')
            ->assertCookieMissing('psikotes_referral');

        $this->assertDatabaseCount('referral_visits', 2);
        $this->assertSame(
            [$branchA->id, $branchA->id],
            ReferralVisit::query()->orderBy('id')->pluck('branch_id')->all(),
        );
    }

    public function test_unknown_referral_falls_back_to_default_branch_without_disclosure(): void
    {
        $default = $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);

        $response = $this->get('/r/DOES-NOT-EXIST')
            ->assertRedirect('/register')
            ->assertCookie('psikotes_referral');

        $response->assertDontSee($default->name);
        $this->assertDatabaseHas('referral_visits', [
            'ref_code' => 'DOES-NOT-EXIST',
            'branch_id' => $default->id,
        ]);
    }

    public function test_expired_cookie_is_replaced_by_current_referral(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $branchA = $this->branch('A', 'REF-A');
        $this->branch('B', 'REF-B');
        $expired = json_encode([
            'version' => 1,
            'ref_code' => 'REF-B',
            'expires_at' => Carbon::now()->subMinute()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $this->withCookie('psikotes_referral', $expired)
            ->get('/r/REF-A')
            ->assertRedirect('/register')
            ->assertCookie('psikotes_referral');

        $this->assertDatabaseHas('referral_visits', [
            'ref_code' => 'REF-A',
            'branch_id' => $branchA->id,
        ]);
    }

    public function test_ip_and_user_agent_are_minimized_and_expire_after_thirty_days(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $this->branch('CENTRAL', 'CENTRAL-REF', isDefault: true);
        $longUserAgent = str_repeat('mobile-browser;', 100);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', $longUserAgent)
            ->get('/r/UNKNOWN')
            ->assertRedirect('/register');

        $visit = ReferralVisit::query()->sole();
        $this->assertSame('203.0.113.10', $visit->ip_address);
        $this->assertSame(512, strlen((string) $visit->user_agent));
        $this->assertTrue($visit->expires_at->equalTo(Carbon::now()->addDays(30)));
    }

    private function branch(
        string $code,
        string $refCode,
        bool $isDefault = false,
    ): Branch {
        return Branch::query()->create([
            'code' => $code,
            'name' => "Branch {$code}",
            'ref_code' => $refCode,
            'is_default' => $isDefault,
        ]);
    }
}
