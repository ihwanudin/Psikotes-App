<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AdminPasswordPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Every test here fakes the HIBP HTTP call (Illuminate\Validation\
 * NotPwnedVerifier) rather than reaching the real network -- required by
 * Lead's explicit condition for this feature.
 */
final class AdminPasswordPolicyTest extends TestCase
{
    public function test_it_rejects_a_password_that_fails_length_or_complexity(): void
    {
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $validator = Validator::make(
            ['password' => 'password'],
            ['password' => [AdminPasswordPolicy::rule()]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_it_accepts_a_strong_password_not_reported_compromised(): void
    {
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $validator = Validator::make(
            ['password' => 'Str0ngPassw0rd!Z'],
            ['password' => [AdminPasswordPolicy::rule()]],
        );

        $this->assertFalse($validator->fails());
    }

    /**
     * Proves uncompromised() is genuinely wired, not just always passing
     * because the fake always returns an empty body: this specific
     * password's real SHA1 hash range prefix is faked to return a
     * matching suffix with a non-zero breach count, which
     * NotPwnedVerifier::verify() must treat as compromised regardless of
     * the password otherwise meeting every length/complexity rule.
     */
    public function test_it_rejects_a_strong_password_reported_compromised(): void
    {
        // sha1('Str0ngPassw0rd!Y') = 8B901BBC8E0D1867CF17DD073E6B83F852630BC0
        Http::fake([
            'https://api.pwnedpasswords.com/range/8B901' => Http::response(
                "BBC8E0D1867CF17DD073E6B83F852630BC0:5\nOTHERSUFFIXVALUE0000000000000000000:2",
                200,
            ),
        ]);

        $validator = Validator::make(
            ['password' => 'Str0ngPassw0rd!Y'],
            ['password' => [AdminPasswordPolicy::rule()]],
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * Documents and proves the deliberate fail-open choice (Lead's
     * explicit ask, 2026-09-22): on a network failure reaching the HIBP
     * API, Illuminate\Validation\NotPwnedVerifier::search() catches the
     * exception, reports it, and falls through to an empty result --
     * uncompromised() then passes. Chosen over failing closed because
     * that would make admin bootstrap/creation impossible in a production
     * network with no outbound HTTPS access to api.pwnedpasswords.com,
     * which is a worse operational risk than occasionally letting a
     * compromised-but-otherwise-strong password through. The other four
     * rules (length, mixed case, numbers, symbols) are unaffected by
     * network state and still apply.
     */
    public function test_it_fails_open_when_the_compromise_check_cannot_reach_the_network(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('Simulated network failure.');
        });

        $validator = Validator::make(
            ['password' => 'Str0ngPassw0rd!Z'],
            ['password' => [AdminPasswordPolicy::rule()]],
        );

        $this->assertFalse($validator->fails());
    }
}
