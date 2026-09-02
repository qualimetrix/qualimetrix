<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The narrow sweep's whole invariant — that a rule can read only its own
 * `@qmx-threshold`, never a neighbour's — is not enforced by any type. It
 * holds only because every `AnalysisContext::getThresholdOverride()` call
 * site passes `$this->getName()` for the rule name, which
 * {@see \Qualimetrix\Analysis\Finding\RuleExecution::isEnabled()} narrows by
 * exact producer name (see `docs/adr/` narrow-pass ADR). A third call site
 * passing a literal, a foreign variable, or another rule's name would let a
 * counterfactual for rule A read rule B's directive while A alone is
 * executing — silently, since the narrow and full sweeps would then disagree
 * on findings neither `ThresholdDirectiveAudit::assertNarrowingChangedNothing()`
 * nor its `Full`-scope control sees (both compare against the *same* leak).
 *
 * This guard makes that assumption checkable instead of merely documented: it
 * tokenizes every production file, finds each `->getThresholdOverride(` call,
 * and requires its first argument to be exactly the expression `$this->getName()`.
 * Token-based rather than a regex match, per the precedent in
 * {@see RuleIdentifierLiteralGuardTest}: a regex reading `getThresholdOverride`
 * cannot tell a real call from the same text inside a docblock or a string
 * literal, and this project has been bitten by exactly that twice.
 */
final class ThresholdOverrideOwnRuleNameGuardTest extends TestCase
{
    private const string METHOD = 'getThresholdOverride';

    #[Test]
    public function everyCallSitePassesItsOwnRuleNameAsTheFirstArgument(): void
    {
        $root = self::projectRoot();
        $violations = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $relative = substr($absolutePath, \strlen($root) + 1);

            foreach (self::callSites($absolutePath) as $callSite) {
                if (self::firstArgumentIsOwnRuleName($callSite['argumentTokens'])) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s:%d calls %s() with a first argument other than $this->getName().',
                    $relative,
                    $callSite['line'],
                    self::METHOD,
                );
            }
        }

        self::assertSame([], $violations, "\n" . implode("\n", $violations));
    }

    /**
     * A control proving the guard actually reads call sites: the two known
     * production callers ({@see \Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule::getEffectiveOptions()}
     * and {@see \Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule::analyze()})
     * must both be found. A guard finding zero call sites would pass
     * {@see everyCallSitePassesItsOwnRuleNameAsTheFirstArgument()} by having
     * nothing to check.
     */
    #[Test]
    public function itFindsAtLeastTheTwoKnownProductionCallSites(): void
    {
        $root = self::projectRoot();
        $found = 0;

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $found += \count(self::callSites($absolutePath));
        }

        self::assertGreaterThanOrEqual(2, $found);
    }

    /**
     * Whether a first argument's tokens spell exactly `$this->getName()`,
     * whitespace and comments aside.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argumentTokens raw tokens, `token_get_all` shape
     */
    private static function firstArgumentIsOwnRuleName(array $argumentTokens): bool
    {
        $meaningful = [];

        foreach ($argumentTokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $meaningful[] = \is_array($token) ? $token[1] : $token;
        }

        return $meaningful === ['$this', '->', 'getName', '(', ')'];
    }

    /**
     * Every `->getThresholdOverride(...)` call site in a file, with the first
     * argument's raw tokens and the line the call starts on.
     *
     * Matched by the preceding `->`, which a method *declaration* (`function
     * getThresholdOverride(...)`) never has — the one other place this name
     * appears in the tree ({@see \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext}).
     *
     * @return list<array{line: int, argumentTokens: list<array{0: int, 1: string, 2: int}|string>}>
     */
    private static function callSites(string $absolutePath): array
    {
        $source = file_get_contents($absolutePath);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $absolutePath));
        }

        $tokens = token_get_all($source);
        $sites = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== self::METHOD) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);

            if (!\is_array($previous) || $previous[0] !== \T_OBJECT_OPERATOR) {
                continue;
            }

            $openParenIndex = self::nextMeaningfulTokenIndex($tokens, $index);

            if ($openParenIndex === null || $tokens[$openParenIndex] !== '(') {
                continue;
            }

            $sites[] = [
                'line' => $token[2],
                'argumentTokens' => self::firstArgumentTokens($tokens, $openParenIndex),
            ];
        }

        return $sites;
    }

    /**
     * The tokens of the call's first argument: everything between the opening
     * `(` and the first top-level `,` or the matching closing `)`, respecting
     * nested parentheses so a first argument that is itself a call is not cut
     * short.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function firstArgumentTokens(array $tokens, int $openParenIndex): array
    {
        $depth = 0;
        $argument = [];

        for ($i = $openParenIndex; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                ++$depth;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($text === ')') {
                --$depth;

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1 && $text === ',') {
                break;
            }

            if ($depth >= 1 && $i !== $openParenIndex) {
                $argument[] = $token;
            }
        }

        return $argument;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextMeaningfulTokenIndex(array $tokens, int $index): ?int
    {
        for ($i = $index + 1; $i < \count($tokens); ++$i) {
            $token = $tokens[$i];

            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @return list<string> absolute paths
     */
    private static function productionPhpFiles(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
