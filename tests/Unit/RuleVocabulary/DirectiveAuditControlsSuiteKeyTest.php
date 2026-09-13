<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAuditControls\Suite;

/**
 * A JUnit method name is not unique across test classes. This test guards
 * `Suite::fromJUnit()`'s `classname::name` key so a result for one class
 * cannot be attributed to another.
 *
 * The synthetic log reproduces the collision without changing the suite's
 * actual test inventory.
 */
final class DirectiveAuditControlsSuiteKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/finding-gate-controls/Shell.php';
        require_once $scripts . '/directive-audit-controls/Suite.php';
    }

    #[Test]
    public function itKeepsTwoClassesWithASameNamedMethodApart(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'qmx-suite-key-collision-');

        file_put_contents($log, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="collision">
                <testcase name="itSameName" classname="Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteA" time="0.001"/>
                <testcase name="itSameName" classname="Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteB" time="0.001">
                  <failure type="PHPUnit\Framework\AssertionFailedError">planted</failure>
                </testcase>
              </testsuite>
            </testsuites>
            XML);

        try {
            $suite = Suite::of(['stdout' => '', 'stderr' => '', 'exit' => 1], $log);
        } finally {
            unlink($log);
        }

        // classname::name keeps them apart: two cases, the passing one and
        // the failing one distinguishable by which class they came from.
        self::assertSame(
            [
                'Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteA::itSameName',
                'Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteB::itSameName',
            ],
            $suite->names(),
        );
        self::assertSame(
            ['Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteB::itSameName'],
            $suite->red(),
        );
    }
}
