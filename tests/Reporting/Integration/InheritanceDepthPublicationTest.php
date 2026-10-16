<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Report;
use SimpleXMLElement;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(Report::class)]
final class InheritanceDepthPublicationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx_dit_publication_' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory . '/src');
        file_put_contents($this->directory . '/composer.json', '{"name":"probe/depth","autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->directory . '/src/Depth.php', <<<'PHP'
            <?php
            namespace App;
            class FloorBelow extends \Missing\Base {}
            /**
             * @qmx-threshold design.dit warning=0 error=99 -- Exercise an admitted lower bound above its own threshold.
             */
            class FloorAbove extends \Missing\Base {}
            class LoopA extends LoopB {}
            class LoopB extends LoopA {}
            class Exact {}
            PHP);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[Test]
    public function itPublishesQuietCollectedOutcomesWithoutChangingFindingsOrScope(): void
    {
        $body = $this->runBinary('json');
        $report = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(self::outcomes(), $report['inheritanceDepthOutcomes'] ?? null);
        self::assertSame('covered', $report['projectScope']['state']);
        self::assertSame([], $report['projectScope']['unjudgedChannels']);
        self::assertSame([], $report['projectScope']['unjudgedValues']);
        self::assertSame([], $report['computedMetricOutcomes']);
        self::assertSame(1, $report['summary']['violationCount']);
        self::assertSame(1, $report['summary']['warningCount']);
        self::assertSame(0, $report['summary']['errorCount']);
        self::assertSame(30, $report['summary']['techDebtMinutes']);
        self::assertSame('design.dit', $report['violations'][0]['code']);
        self::assertSame('declaration:class:App\\FloorAbove@src/Depth.php', $report['violations'][0]['subject']);
        self::assertSame(1, $report['violations'][0]['metricValue']);
        self::assertSame(0, $report['violations'][0]['threshold']);
        self::assertStringContainsString('at least 1', $report['violations'][0]['message']);
        self::assertSame(2, $report['abstentions'][0]['count']);
        self::assertSame('dit-present', $report['abstentions'][0]['gate']);
        self::assertSame(self::outcomes()[1]['examples'], $report['abstentions'][0]['examples']);
    }

    #[Test]
    public function itRetainsCollectedOutcomesWhenTheRuleIsDisabled(): void
    {
        $report = json_decode($this->runBinary('json', ['--disable-rule=design.dit']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(self::outcomes(), $report['inheritanceDepthOutcomes'] ?? null);
        self::assertSame([], $report['abstentions']);
        self::assertSame([], $report['violations']);
        self::assertSame(0, $report['summary']['violationCount']);
        self::assertSame(0, $report['summary']['techDebtMinutes']);
    }

    #[Test]
    #[DataProvider('formats')]
    public function itUsesMetadataSeamsAndLeavesFindingOnlyFormatsAlone(string $format): void
    {
        $body = $this->runBinary($format);
        if ($format === 'html') {
            self::assertStringContainsString('data-qmx-inheritance-depth="incomplete"', $body);
            self::assertMatchesRegularExpression('/<script type="application\/json" id="report-data">(.*?)<\/script>/s', $body);
            self::assertSame(1, preg_match('/<script type="application\/json" id="report-data">(.*?)<\/script>/s', $body, $matches));
            self::assertArrayHasKey(1, $matches);
            $data = json_decode($matches[1], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(self::outcomes(), $data['inheritanceDepthOutcomes']);
            self::assertStringContainsString('DIT measurement incomplete', $body);
        } elseif ($format === 'sarif') {
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
            $run = $data['runs'][0];
            self::assertCount(1, $run['results']);
            self::assertSame(true, $run['invocations'][0]['executionSuccessful']);
            $notifications = array_values(array_filter($run['invocations'][0]['toolExecutionNotifications'], static fn(array $note): bool => $note['descriptor']['id'] === 'QMX-INHERITANCE-DEPTH-INCOMPLETE'));
            self::assertCount(1, $notifications);
            self::assertSame(self::outcomes(), $notifications[0]['properties']['inheritanceDepthOutcomes']);
            self::assertSame('note', $notifications[0]['level']);
        } elseif ($format === 'gitlab') {
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(1, $data);
            self::assertSame('design.dit', $data[0]['check_name']);
        } elseif ($format === 'checkstyle') {
            $data = new SimpleXMLElement($body);
            $errors = $data->xpath('//error');
            self::assertIsArray($errors);
            self::assertCount(1, $errors);
            self::assertSame('qmx.design.dit', (string) $data->file->error['source']);
        } else {
            self::assertStringContainsString('DIT measurement incomplete: lower-bound for 2 class declaration(s)', $body);
            self::assertStringContainsString('DIT measurement incomplete: absent-depth for 2 class declaration(s)', $body);
            self::assertStringNotContainsString('Exact@src/Depth.php', $body);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (['html', 'sarif', 'text', 'summary', 'health', 'gitlab', 'checkstyle'] as $format) {
            yield $format => [$format];
        }
    }

    /** @return list<array{status: string, count: int, examples: list<string>}> */
    private static function outcomes(): array
    {
        return [
            ['status' => 'lower-bound', 'count' => 2, 'examples' => ['declaration:class:App\\FloorAbove@src/Depth.php', 'declaration:class:App\\FloorBelow@src/Depth.php']],
            ['status' => 'absent-depth', 'count' => 2, 'examples' => ['declaration:class:App\\LoopA@src/Depth.php', 'declaration:class:App\\LoopB@src/Depth.php']],
        ];
    }

    /** @param list<string> $arguments */
    private function runBinary(string $format, array $arguments = []): string
    {
        $process = new Process([
            \PHP_BINARY, \dirname(__DIR__, 3) . '/bin/qmx', 'check', 'src',
            '--format=' . $format, '--workers=0', '--no-cache', '--no-progress',
            '--no-ansi', ...($arguments === [] ? ['--only-rule=design.dit'] : ['--only-rule=code-smell.eval']), '--dit-warning=2', '--dit-error=99', '--fail-on=none', '-q', ...($format === 'json' ? [] : ['--output=' . $this->directory . '/report.' . $format]), ...$arguments,
        ], $this->directory);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());

        $body = $format === 'json' ? $process->getOutput() : file_get_contents($this->directory . '/report.' . $format);
        self::assertIsString($body);

        return $body;
    }
}
