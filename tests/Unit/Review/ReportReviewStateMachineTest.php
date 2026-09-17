<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Review\ReportReviewStateMachine;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportReviewStateMachineTest extends TestCase
{
    private const STATES = [
        'DRAFT_SCORED',
        'DRAFT_NARRATED',
        'UNDER_REVIEW',
        'REVISED',
        'SIGNED',
        'PUBLISHED',
        'REVOKED',
        'VOID',
    ];

    #[DataProvider('allowedTransitions')]
    public function test_it_returns_a_deterministic_result_for_every_allowed_transition(
        string $from,
        string $to,
        bool $invalidityDeclared,
    ): void {
        $this->assertSame([
            'from_state' => $from,
            'to_state' => $to,
            'transitioned' => true,
            'terminal' => in_array($to, ['REVOKED', 'VOID'], true),
            'provenance' => [
                'transition_kind' => $to === 'VOID' ? 'INVALIDATION' : 'STANDARD',
                'invalidity_declared' => $invalidityDeclared,
            ],
        ], (new ReportReviewStateMachine)->transition($from, $to, $invalidityDeclared));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function allowedTransitions(): iterable
    {
        foreach (self::standardTransitions() as [$from, $to]) {
            yield "{$from} to {$to}" => [$from, $to, false];
        }

        foreach (array_slice(self::STATES, 0, 6) as $from) {
            yield "{$from} to VOID after invalidity declaration" => [$from, 'VOID', true];
        }
    }

    #[DataProvider('deniedTransitions')]
    public function test_exhaustive_state_matrix_denies_every_transition_outside_the_graph(
        string $from,
        string $to,
        bool $invalidityDeclared,
    ): void {
        $this->expectException(DomainException::class);

        (new ReportReviewStateMachine)->transition($from, $to, $invalidityDeclared);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function deniedTransitions(): iterable
    {
        $allowed = [];
        foreach (self::allowedTransitions() as [$from, $to, $invalidityDeclared]) {
            $allowed["{$from}:{$to}"] = $invalidityDeclared;
        }

        foreach (self::STATES as $from) {
            foreach (self::STATES as $to) {
                if (! array_key_exists("{$from}:{$to}", $allowed)) {
                    yield "{$from} cannot transition to {$to}" => [$from, $to, $to === 'VOID'];
                }
            }
        }
    }

    #[DataProvider('states')]
    public function test_same_state_replay_is_denied_without_an_exact_event_identity(string $state): void
    {
        $this->expectException(DomainException::class);

        (new ReportReviewStateMachine)->transition($state, $state, $state === 'VOID');
    }

    /** @return iterable<string, array{string}> */
    public static function states(): iterable
    {
        foreach (self::STATES as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('invalidStates')]
    public function test_it_rejects_noncanonical_state_values(string $from, string $to): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportReviewStateMachine)->transition($from, $to);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStates(): iterable
    {
        yield 'lowercase source' => ['draft_scored', 'DRAFT_NARRATED'];
        yield 'lowercase target' => ['DRAFT_SCORED', 'draft_narrated'];
        yield 'source with whitespace' => [' DRAFT_SCORED', 'DRAFT_NARRATED'];
        yield 'target with whitespace' => ['DRAFT_SCORED', 'DRAFT_NARRATED '];
        yield 'empty source' => ['', 'DRAFT_NARRATED'];
        yield 'empty target' => ['DRAFT_SCORED', ''];
        yield 'unknown source' => ['DRAFT', 'DRAFT_NARRATED'];
        yield 'unknown target' => ['DRAFT_SCORED', 'ARCHIVED'];
    }

    public function test_invalidity_declaration_cannot_accompany_a_standard_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportReviewStateMachine)->transition('DRAFT_SCORED', 'DRAFT_NARRATED', true);
    }

    public function test_raw_state_machine_cannot_sign_an_under_review_report(): void
    {
        $this->expectException(DomainException::class);

        (new ReportReviewStateMachine)->transition('UNDER_REVIEW', 'SIGNED');
    }

    /** @return list<array{string, string}> */
    private static function standardTransitions(): array
    {
        return [
            ['DRAFT_SCORED', 'DRAFT_NARRATED'],
            ['DRAFT_NARRATED', 'UNDER_REVIEW'],
            ['UNDER_REVIEW', 'REVISED'],
            ['REVISED', 'UNDER_REVIEW'],
            ['SIGNED', 'PUBLISHED'],
            ['PUBLISHED', 'REVOKED'],
        ];
    }
}
