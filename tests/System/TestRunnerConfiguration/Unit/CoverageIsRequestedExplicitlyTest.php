<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\System\TestRunnerConfiguration\Unit;

use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoverageIsRequestedExplicitlyTest extends TestCase
{
    /**
     * With the block back, a direct `vendor/bin/phpunit` runs no test at all
     * wherever no coverage driver is *active* -- an installed Xdebug in its
     * default mode is enough -- while `composer test` stays green.
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
