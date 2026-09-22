<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SelectorSyntax;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SelectorImplementationBoundaryTest extends TestCase
{
    /** @var array<string, int> */
    private const array PATTERN_CONSTRUCTION_SITES = [
        'src/Analysis/Configuration/SelectorYamlDecoder.php' => 2,
        'src/Analysis/Evidence/Coupling/CouplingAnalysis.php' => 1,
        'src/Analysis/Evidence/Coupling/DistanceOptions.php' => 1,
        'src/Analysis/Finding/RuleConfiguration/RuleSuppressionSelectorDecoder.php' => 3,
        'src/Analysis/Run/Discovery/DirectoryPruner.php' => 1,
        'src/Infrastructure/Console/CliSelectorDecoder.php' => 2,
    ];

    #[Test]
    public function itHasNoPrivateGlobMatcherInProduction(): void
    {
        $occurrences = self::productionOccurrences('/\bfnmatch\s*\(/');

        self::assertSame([], $occurrences, 'User-facing matching must not reintroduce fnmatch().');
    }

    #[Test]
    public function itKnowsEveryPatternConstructionSite(): void
    {
        $found = self::productionOccurrences('/new\s+(?:PathPattern|NamespacePattern)\s*\(/');

        self::assertSame(self::PATTERN_CONSTRUCTION_SITES, $found, 'Declare every new selector construction site.');
    }

    /** @return array<string, int> */
    private static function productionOccurrences(string $pattern): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($entry->getPathname());
            self::assertIsString($source, $entry->getPathname());
            $count = preg_match_all($pattern, self::codeWithoutComments($source));
            self::assertNotFalse($count);

            if ($count > 0) {
                $found[substr($entry->getPathname(), \strlen($root) + 1)] = $count;
            }
        }

        ksort($found);

        return $found;
    }

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
