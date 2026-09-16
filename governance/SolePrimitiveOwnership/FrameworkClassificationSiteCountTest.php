<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SolePrimitiveOwnership;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mirror stays a mirror.
 *
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\FrameworkClassificationSites::names()}
 * restates the two positions where `computeClassMetrics()` calls the
 * framework predicate, and nothing in the language keeps the two in step. A
 * third call site would classify names
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule}'s
 * universe does not hold, and the rule would go back to reporting a prefix
 * that moved a metric. So the call sites are counted: adding one reddens
 * here, which is where the mirror is named.
 */
final class FrameworkClassificationSiteCountTest extends TestCase
{
    #[Test]
    public function itPinsTheNumberOfClassificationSitesTheUniverseMirrors(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Analysis/Evidence/Coupling/CouplingCollector.php');
        self::assertIsString($source);

        self::assertSame(
            2,
            preg_match_all('/\$this->isFrameworkSymbol\(/', $source),
            'CouplingCollector classifies at a position FrameworkClassificationSites::names() does not mirror.',
        );
    }
}
