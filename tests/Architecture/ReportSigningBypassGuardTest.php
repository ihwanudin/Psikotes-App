<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReportSigningBypassGuardTest extends TestCase
{
    /** @var list<string> */
    private const ALLOWED_SIGNING_PATHS = [
        'app/Domain/Review/ReportSigningTransitionPolicy.php',
    ];

    private string $root;

    private Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function test_control_fixture_detects_a_direct_signed_transition_bypass(): void
    {
        $source = <<<'PHP'
<?php

$machine->transition('UNDER_REVIEW', 'SIGNED');
PHP;

        self::assertSame(
            ['app/Actions/Bypass.php:3'],
            $this->violationsForFile('app/Actions/Bypass.php', $source),
        );
    }

    public function test_control_fixture_detects_static_and_named_argument_bypasses(): void
    {
        $source = <<<'PHP'
<?php

ReportReviewStateMachine::transition(
    fromState: 'UNDER_REVIEW',
    toState: 'SIGNED',
);
PHP;

        self::assertSame(
            ['app/Actions/NamedBypass.php:3'],
            $this->violationsForFile('app/Actions/NamedBypass.php', $source),
        );
    }

    public function test_control_fixture_detects_a_literal_successful_signed_transition_result(): void
    {
        $source = <<<'PHP'
<?php

$result = [
    'from_state' => 'UNDER_REVIEW',
    'to_state' => 'SIGNED',
    'transitioned' => true,
];
PHP;

        self::assertSame(
            ['app/Actions/ManualSigningBypass.php:3'],
            $this->violationsForFile('app/Actions/ManualSigningBypass.php', $source),
        );
    }

    public function test_control_fixture_detects_a_nested_long_array_signed_transition_result(): void
    {
        $source = <<<'PHP'
<?php

return array(
    'can_sign' => true,
    'transition' => array(
        'transitioned' => true,
        'to_state' => 'SIGNED',
        'from_state' => 'UNDER_REVIEW',
    ),
);
PHP;

        self::assertSame(
            ['app/Actions/NestedManualSigningBypass.php:5'],
            $this->violationsForFile('app/Actions/NestedManualSigningBypass.php', $source),
        );
    }

    public function test_control_fixture_ignores_non_successful_or_non_literal_signed_result_text(): void
    {
        $source = <<<'PHP'
<?php

const SIGNED_STATE = 'SIGNED';
$denied = ['to_state' => 'SIGNED', 'transitioned' => false];
$incomplete = ['to_state' => 'SIGNED'];
$dynamic = ['to_state' => SIGNED_STATE, 'transitioned' => true];
$other = ['to_state' => 'PUBLISHED', 'transitioned' => true];
$description = "['to_state' => 'SIGNED', 'transitioned' => true]";
PHP;

        self::assertSame([], $this->violationsForFile('app/Actions/NotSuccessfulSigning.php', $source));
    }

    public function test_control_fixture_allows_non_signed_transitions_and_non_call_text(): void
    {
        $source = <<<'PHP'
<?php

// $machine->transition('UNDER_REVIEW', 'SIGNED');
$published = $machine->transition('SIGNED', 'PUBLISHED');
$revised = $machine->transition(fromState: 'UNDER_REVIEW', toState: 'REVISED');
$description = "transition('UNDER_REVIEW', 'SIGNED')";
PHP;

        self::assertSame([], $this->violationsForFile('app/Actions/AllowedTransitions.php', $source));
    }

    public function test_control_fixture_allows_the_exact_signing_policy_path(): void
    {
        $source = <<<'PHP'
<?php

$machine->transition('UNDER_REVIEW', 'SIGNED');
PHP;

        self::assertSame(
            [],
            $this->violationsForFile('app/Domain/Review/ReportSigningTransitionPolicy.php', $source),
        );
    }

    public function test_application_has_no_signed_transition_call_outside_the_signing_policy(): void
    {
        $violations = [];

        foreach ($this->applicationPhpFiles() as $file) {
            $relativePath = $this->relativePath($file);
            $source = file_get_contents($file);
            self::assertIsString($source, 'Unable to read '.$relativePath.'.');

            array_push($violations, ...$this->violationsForFile($relativePath, $source));
        }

        self::assertSame(
            [],
            $violations,
            'SIGNED transitions must be composed only by ReportSigningTransitionPolicy.',
        );
    }

    /** @return list<string> */
    private function violationsForFile(string $relativePath, string $source): array
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        if (in_array($normalizedPath, self::ALLOWED_SIGNING_PATHS, true)) {
            return [];
        }

        $statements = $this->parser->parse($source);
        self::assertIsArray($statements, 'PHP source must produce an AST for '.$normalizedPath.'.');

        $calls = (new NodeFinder)->find(
            $statements,
            static fn (Node $node): bool => $node instanceof MethodCall
                || $node instanceof NullsafeMethodCall
                || $node instanceof StaticCall,
        );
        $violations = [];

        foreach ($calls as $call) {
            if ((! $call instanceof MethodCall
                    && ! $call instanceof NullsafeMethodCall
                    && ! $call instanceof StaticCall)
                || ! $call->name instanceof Identifier
                || strtolower($call->name->toString()) !== 'transition') {
                continue;
            }

            $targetArgument = $this->transitionTargetArgument($call->args);
            if ($targetArgument instanceof String_ && $targetArgument->value === 'SIGNED') {
                $violations[] = $normalizedPath.':'.$call->getStartLine();
            }
        }

        $arrays = (new NodeFinder)->findInstanceOf($statements, Array_::class);
        foreach ($arrays as $array) {
            $targetState = $this->literalArrayValue($array, 'to_state');
            $transitioned = $this->literalArrayValue($array, 'transitioned');

            if ($targetState instanceof String_
                && $targetState->value === 'SIGNED'
                && $transitioned instanceof ConstFetch
                && strtolower($transitioned->name->toString()) === 'true') {
                $violations[] = $normalizedPath.':'.$array->getStartLine();
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations);

        return $violations;
    }

    private function literalArrayValue(Array_ $array, string $key): ?Node\Expr
    {
        foreach ($array->items as $item) {
            if ($item->key instanceof String_
                && $item->key->value === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * @param  array<Arg|Node\VariadicPlaceholder>  $arguments
     */
    private function transitionTargetArgument(array $arguments): ?Node\Expr
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Arg
                && $argument->name instanceof Identifier
                && $argument->name->toString() === 'toState') {
                return $argument->value;
            }
        }

        $secondArgument = $arguments[1] ?? null;

        return $secondArgument instanceof Arg ? $secondArgument->value : null;
    }

    /** @return list<string> */
    private function applicationPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root.'/app', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen($this->root) + 1));
    }
}
