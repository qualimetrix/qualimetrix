<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\{FailureClass, Fs, RaiseSites, WitnessRegistry};

final class RaiseSitesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itReadsNativePhpAndIgnoresCommentAndStringLookalikes(): void
    {
        $root = Fs::temporaryDirectory('raise-producer-test-');
        try {
            Fs::write($root . '/Producer.php', <<<'PHP'
                <?php
                // $report->fail(FailureClass::UNKNOWN, 'scope', 'text');
                final class Producer {
                    public function run($report): void {
                        $text = '$report->fail(FailureClass::UNKNOWN)';
                        $report?->fail(FailureClass::RUN_FAILED, 'candidate / scope', $text);
                    }
                }
                PHP);
            $source = RaiseSites::of($root);
            self::assertSame([], $source->problems);
            self::assertSame(['Producer'], $source->classes);
            self::assertSame([FailureClass::RUN_FAILED], array_column($source->sites, 'class'));
            self::assertSame([], WitnessRegistry::problems([FailureClass::RUN_FAILED], array_column($source->sites, 'class'), [FailureClass::RUN_FAILED]));
        } finally {
            Fs::removeRecursively($root);
        }
    }

    #[Test]
    public function itRefusesAnUnknownVocabularyProducerAndAMissingProducer(): void
    {
        $root = Fs::temporaryDirectory('raise-producer-test-');
        try {
            Fs::write($root . '/Producer.php', "<?php final class Producer { public function run(\$report): void { \$report->fail(FailureClass::UNKNOWN, 'scope', 'text'); } }\n");
            $source = RaiseSites::of($root);
            self::assertCount(1, $source->problems);
            self::assertStringContainsString('unknown or indirect failure class', $source->problems[0]);
            self::assertSame([], $source->sites);
            $problems = WitnessRegistry::problems([FailureClass::RUN_FAILED], [], [FailureClass::RUN_FAILED]);
            self::assertSame(['witness registry: run-failed is raised nowhere in the gate\'s source.'], $problems);
        } finally {
            Fs::removeRecursively($root);
        }
    }

    #[Test]
    public function itRefusesPhpTheNativeParserCannotRead(): void
    {
        $root = Fs::temporaryDirectory('raise-producer-test-');
        try {
            Fs::write($root . '/Producer.php', '<?php final class {');
            $source = RaiseSites::of($root);
            self::assertCount(1, $source->problems);
            self::assertStringContainsString('does not parse', $source->problems[0]);
        } finally {
            Fs::removeRecursively($root);
        }
    }
}
