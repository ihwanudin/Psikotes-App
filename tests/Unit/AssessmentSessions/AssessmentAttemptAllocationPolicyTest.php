<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentAttempt;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocation;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentRetestGrant;
use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentAttemptAllocationPolicyTest extends TestCase
{
    private const string FIRST_SESSION = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const string SECOND_SESSION = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    private const string FIRST_INTENT = 'allocation-intent:first';

    private const string SECOND_INTENT = 'allocation-intent:second';

    private const string FIRST_AUTHORIZATION = 'entitlement-authorization:first';

    private const string SECOND_AUTHORIZATION = 'entitlement-authorization:second';

    public function test_first_authorized_intent_allocates_attempt_one_with_supplied_identity(): void
    {
        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [],
            self::FIRST_INTENT,
            self::FIRST_AUTHORIZATION,
            self::FIRST_SESSION,
            null,
            null,
        );

        $this->assertTrue($decision->accepted);
        $this->assertTrue($decision->shouldPersist);
        $this->assertSame(self::FIRST_SESSION, $decision->allocation?->attempt->sessionPublicId);
        $this->assertSame(1, $decision->allocation?->attempt->attemptNumber);
        $this->assertSame(self::FIRST_INTENT, $decision->allocation?->intentId);
        $this->assertNull($decision->errorCode);
    }

    public function test_same_intent_replays_the_same_attempt_without_persistence(): void
    {
        $attempt = $this->attempt(AssessmentSessionStatus::Created);
        $existing = new AssessmentAttemptAllocation(self::FIRST_INTENT, $attempt);

        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [$attempt],
            self::FIRST_INTENT,
            self::FIRST_AUTHORIZATION,
            self::SECOND_SESSION,
            $existing,
            null,
        );

        $this->assertTrue($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame($existing, $decision->allocation);
        $this->assertSame(self::FIRST_SESSION, $decision->allocation?->attempt->sessionPublicId);
    }

    #[DataProvider('activeStatuses')]
    public function test_different_intent_cannot_allocate_while_an_active_attempt_exists(
        AssessmentSessionStatus $status,
    ): void {
        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [$this->attempt($status)],
            self::SECOND_INTENT,
            self::SECOND_AUTHORIZATION,
            self::SECOND_SESSION,
            null,
            null,
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionErrorCode::AttemptAlreadyExists, $decision->errorCode);
    }

    /** @return iterable<string, array{AssessmentSessionStatus}> */
    public static function activeStatuses(): iterable
    {
        yield 'created' => [AssessmentSessionStatus::Created];
        yield 'in progress' => [AssessmentSessionStatus::InProgress];
    }

    #[DataProvider('consumedStatuses')]
    public function test_consumed_attempt_never_automatically_opens_a_new_attempt(
        AssessmentSessionStatus $status,
    ): void {
        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [$this->attempt($status)],
            self::SECOND_INTENT,
            self::SECOND_AUTHORIZATION,
            self::SECOND_SESSION,
            null,
            null,
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionErrorCode::RetestNotAuthorized, $decision->errorCode);
    }

    /** @return iterable<string, array{AssessmentSessionStatus}> */
    public static function consumedStatuses(): iterable
    {
        yield 'submitted' => [AssessmentSessionStatus::Submitted];
        yield 'scored' => [AssessmentSessionStatus::Scored];
        yield 'expired' => [AssessmentSessionStatus::Expired];
        yield 'void' => [AssessmentSessionStatus::Voided];
    }

    public function test_valid_retest_grant_allocates_the_next_attempt_with_new_authorization(): void
    {
        $grant = new AssessmentRetestGrant(
            'retest-grant:second',
            true,
            'Identity incident reviewed by authorized administrator.',
            'admin:reviewer-public-id',
            self::SECOND_AUTHORIZATION,
            2,
        );

        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [$this->attempt(AssessmentSessionStatus::Voided)],
            self::SECOND_INTENT,
            self::SECOND_AUTHORIZATION,
            self::SECOND_SESSION,
            null,
            $grant,
        );

        $this->assertTrue($decision->accepted);
        $this->assertTrue($decision->shouldPersist);
        $this->assertSame(2, $decision->allocation?->attempt->attemptNumber);
        $this->assertSame(self::SECOND_AUTHORIZATION, $decision->allocation?->attempt->authorizationId);
    }

    #[DataProvider('invalidRetestGrants')]
    public function test_retest_requires_authorization_reason_actor_new_entitlement_and_exact_next_attempt(
        AssessmentRetestGrant $grant,
        string $requestedAuthorization,
    ): void {
        $decision = (new AssessmentAttemptAllocationPolicy)->decide(
            [$this->attempt(AssessmentSessionStatus::Scored)],
            self::SECOND_INTENT,
            $requestedAuthorization,
            self::SECOND_SESSION,
            null,
            $grant,
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->shouldPersist);
        $this->assertSame(AssessmentSessionErrorCode::RetestNotAuthorized, $decision->errorCode);
    }

    /** @return iterable<string, array{AssessmentRetestGrant, string}> */
    public static function invalidRetestGrants(): iterable
    {
        yield 'not authorized' => [new AssessmentRetestGrant(
            'grant', false, 'Reviewed incident.', 'admin:one', self::SECOND_AUTHORIZATION, 2,
        ), self::SECOND_AUTHORIZATION];
        yield 'blank reason' => [new AssessmentRetestGrant(
            'grant', true, '   ', 'admin:one', self::SECOND_AUTHORIZATION, 2,
        ), self::SECOND_AUTHORIZATION];
        yield 'blank authorizing actor' => [new AssessmentRetestGrant(
            'grant', true, 'Reviewed incident.', '', self::SECOND_AUTHORIZATION, 2,
        ), self::SECOND_AUTHORIZATION];
        yield 'reused entitlement authorization' => [new AssessmentRetestGrant(
            'grant', true, 'Reviewed incident.', 'admin:one', self::FIRST_AUTHORIZATION, 2,
        ), self::FIRST_AUTHORIZATION];
        yield 'grant bound to another authorization' => [new AssessmentRetestGrant(
            'grant', true, 'Reviewed incident.', 'admin:one', 'entitlement-authorization:other', 2,
        ), self::SECOND_AUTHORIZATION];
        yield 'grant bound to wrong attempt' => [new AssessmentRetestGrant(
            'grant', true, 'Reviewed incident.', 'admin:one', self::SECOND_AUTHORIZATION, 3,
        ), self::SECOND_AUTHORIZATION];
    }

    private function attempt(AssessmentSessionStatus $status): AssessmentAttempt
    {
        return new AssessmentAttempt(
            self::FIRST_SESSION,
            1,
            self::FIRST_AUTHORIZATION,
            $status,
        );
    }
}
