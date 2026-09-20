<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SelectorSyntax;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every production call site of `NamespaceMatcher::matchesSingle()` leaves
 * pattern normalization to the primitive rather than compensating for it
 * before the call. A surface missing here — or one that normalizes its own
 * pattern before handing it over — is how the trailing-backslash behaviour
 * came to differ between surfaces.
 */
final class NamespaceMatcherNormalizationSurfaceTest extends TestCase
{
    /**
     * Every production call site of the primitive, with the number of calls it
     * makes.
     *
     * @var array<string, int>
     */
    private const array SELECTOR_SURFACES = [
        'src/Analysis/Evidence/ComputedMetrics/Health/Contract/DrillDown/HealthScoreDrillDown.php' => 2,
        'src/Analysis/Evidence/ComputedMetrics/Health/Offender/WorstOffenderBuilder.php' => 1,
        'src/Analysis/Evidence/Coupling/DistanceRule.php' => 1,
        'src/Analysis/Policy/Architecture/Layer/Expansion/TupleExtractor.php' => 1,
        'src/Analysis/Policy/Architecture/Layer/LayerCriteriaMatcher.php' => 1,
        'src/Infrastructure/Console/DrillDownBinding.php' => 1,
        'src/Reporting/DrillDown/FindingFilter.php' => 2,
    ];

    #[Test]
    public function itLeavesPatternNormalizationToThePrimitiveOnEverySurface(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        $compensating = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = self::codeWithoutComments((string) file_get_contents($file->getPathname()));
            $calls = preg_match_all('/NamespaceMatcher::matchesSingle\(\s*(?<first>[^,]+),/', $source, $matches);

            if ($calls === 0 || $calls === false) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $found[$relative] = $calls;

            foreach ($matches['first'] as $argument) {
                if (preg_match('/\b(rtrim|trim|str_replace|preg_replace)\s*\(/', $argument) === 1) {
                    $compensating[] = $relative . ': ' . trim($argument);
                }
            }
        }

        ksort($found);
        $expected = self::SELECTOR_SURFACES;
        ksort($expected);

        self::assertSame($expected, $found, 'Every production call site of the primitive must be registered here.');
        self::assertSame([], $compensating, 'Normalization belongs to matchesSingle(), not to its callers.');
    }

    /**
     * Docblocks name the primitive as often as code calls it, so the sweep
     * must see code only.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                $code .= \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
