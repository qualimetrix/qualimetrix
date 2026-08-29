<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use ReflectionClass;

/**
 * `scripts/enumerate-inline-directives.php` reads `@qmx-threshold` sites
 * without booting the analysis pipeline, so it keeps its own copy of
 * {@see ThresholdOverrideExtractor}'s private `PATTERN` constant instead of
 * reusing it. This test is the guard the script's docblock promises: it fails
 * the moment the two patterns drift, rather than leaving the script to
 * silently under- or over-match against the product's own extraction.
 */
#[CoversNothing]
final class ThresholdDirectivePatternSyncTest extends TestCase
{
    #[Test]
    public function itKeepsTheEnumerationScriptPatternInSyncWithTheExtractor(): void
    {
        $constant = (new ReflectionClass(ThresholdOverrideExtractor::class))->getReflectionConstant('PATTERN');

        if ($constant === false) {
            self::fail(
                'ThresholdOverrideExtractor no longer declares a PATTERN constant — '
                    . 'update scripts/enumerate-inline-directives.php::DIRECTIVE_PATTERN '
                    . '(and this test) to match its replacement.',
            );
        }

        $extractorPattern = $constant->getValue();

        self::assertSame(
            $extractorPattern,
            self::scriptPattern(),
            'scripts/enumerate-inline-directives.php::DIRECTIVE_PATTERN has drifted from '
                . 'ThresholdOverrideExtractor::PATTERN — update the script copy to match.',
        );
    }

    private static function scriptPattern(): string
    {
        $path = \dirname(__DIR__, 5) . '/scripts/enumerate-inline-directives.php';
        $source = (string) file_get_contents($path);

        if (preg_match(
            '/const\s+DIRECTIVE_PATTERN\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*;/',
            $source,
            $matches,
        ) !== 1) {
            self::fail('Could not find DIRECTIVE_PATTERN constant in ' . $path);
        }

        /** @var string $value */
        $value = eval('return ' . $matches[1] . ';');

        return $value;
    }
}
