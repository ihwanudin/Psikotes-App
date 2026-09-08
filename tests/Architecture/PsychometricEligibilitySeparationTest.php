<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PsychometricEligibilitySeparationTest extends TestCase
{
    private const FORBIDDEN_ELIGIBILITY_OUTPUT_KEY_PARTS = [
        'eligibility',
        'zone',
        'label',
        'recommendation',
    ];

    private const REFERENCE_TOKEN_IDS = [
        T_CONSTANT_ENCAPSED_STRING,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_QUALIFIED,
        T_NAME_RELATIVE,
        T_STRING,
        T_VARIABLE,
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
    }

    public function test_guard_recognizes_forbidden_dependencies_and_output_keys(): void
    {
        $eligibilitySource = <<<'PHP'
<?php

namespace App\Domain\Eligibility;

use App\Services\Scoring\Dass21Scorer;

function calculate(array $dassPayload): array
{
    return $dassPayload;
}
PHP;
        $dassSource = <<<'PHP'
<?php

namespace App\Services\Scoring;

use App\Domain\Eligibility\EligibilityZoneCalculator;

function score(): array
{
    return ['eligibility_zone' => 'OK', 'recommendation' => 'approved'];
}
PHP;

        self::assertNotEmpty($this->referencesMatching($eligibilitySource, '/dass/i'));
        self::assertNotEmpty($this->referencesMatching($dassSource, '/eligibility/i'));
        self::assertSame(
            ['eligibility_zone', 'recommendation'],
            $this->forbiddenOutputKeys($dassSource),
        );
    }

    public function test_eligibility_domain_has_no_dass_dependency_or_payload_reference(): void
    {
        $files = $this->phpFilesUnder($this->root.'/app/Domain/Eligibility');

        self::assertNotEmpty($files, 'Eligibility domain must contain PHP source files.');

        foreach ($files as $file) {
            self::assertSame(
                [],
                $this->referencesMatching($this->readSource($file), '/dass/i'),
                $this->relativePath($file).' must not reference DASS payloads or scorers.',
            );
        }
    }

    public function test_dass_scoring_boundary_has_no_eligibility_dependency_or_output_keys(): void
    {
        $files = [
            $this->root.'/app/Services/Scoring/Dass21Scorer.php',
            $this->root.'/app/Services/Scoring/Dass21ScreeningPolicy.php',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file);
            $source = $this->readSource($file);

            self::assertSame(
                [],
                $this->referencesMatching($source, '/eligibility/i'),
                $this->relativePath($file).' must not reference the Eligibility domain.',
            );
            self::assertSame(
                [],
                $this->forbiddenOutputKeys($source),
                $this->relativePath($file).' must not emit eligibility, zone, label, or recommendation keys.',
            );
        }
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        self::assertDirectoryExists($directory);

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function readSource(string $file): string
    {
        $source = file_get_contents($file);

        self::assertIsString($source, 'Unable to read '.$this->relativePath($file).'.');

        return $source;
    }

    /** @return list<string> */
    private function referencesMatching(string $source, string $pattern): array
    {
        $matches = [];

        foreach (token_get_all($source, TOKEN_PARSE) as $token) {
            if (! is_array($token)
                || ! in_array($token[0], self::REFERENCE_TOKEN_IDS, true)
                || preg_match($pattern, $token[1]) !== 1) {
                continue;
            }

            $matches[] = token_name($token[0]).':'.$token[1];
        }

        return $matches;
    }

    /** @return list<string> */
    private function forbiddenOutputKeys(string $source): array
    {
        $tokens = token_get_all($source, TOKEN_PARSE);
        $matches = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $next = $this->nextSemanticToken($tokens, $index + 1);

            if (! is_array($next) || $next[0] !== T_DOUBLE_ARROW) {
                continue;
            }

            $key = $this->stringLiteralValue($token[1]);

            foreach (self::FORBIDDEN_ELIGIBILITY_OUTPUT_KEY_PARTS as $part) {
                if (stripos($key, $part) !== false) {
                    $matches[] = $key;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{int, string, int}|string|null
     */
    private function nextSemanticToken(array $tokens, int $offset): array|string|null
    {
        for ($index = $offset; array_key_exists($index, $tokens); $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function stringLiteralValue(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }

        return stripcslashes($value);
    }

    private function relativePath(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen($this->root) + 1));
    }
}
