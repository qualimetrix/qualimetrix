<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\System\TestRunnerConfiguration\Unit;

use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoverageIsRequestedExplicitlyTest extends TestCase
{
    /**
     * A `<coverage>` block asks for a report on every run, and a run that
     * cannot write one fails before executing a single test: with no coverage
     * driver active PHPUnit warns, `failOnWarning` turns the warning into a
     * failure, and `vendor/bin/phpunit --filter X` answers "No tests
     * executed!". `composer test` passes `--no-coverage` and would stay green,
     * so nothing else in the repository notices the block coming back.
     */
    #[Test]
    public function itKeepsTheCoverageReportWritersOutOfTheTrackedConfiguration(): void
    {
        $configuration = file_get_contents(__DIR__ . '/../../../../phpunit.xml.dist');
        self::assertIsString($configuration);

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($configuration));

        self::assertSame(
            0,
            $document->getElementsByTagName('coverage')->length,
            'phpunit.xml.dist must not request coverage reports: coverage is asked for by'
            . ' `composer test:coverage`, which fails loudly when no driver is active.',
        );
    }
}
