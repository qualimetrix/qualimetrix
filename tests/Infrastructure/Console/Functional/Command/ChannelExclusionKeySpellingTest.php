<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An `exclude_namespace_channels` key reaches the run under the spelling its
 * author wrote.
 *
 * Most channel names are kebab, and so is every name the computed-metric
 * validator accepts, so key normalization used to camelCase them into names
 * addressing no channel — the run then refused the key by the mangled
 * spelling, printing the correct name in the same sentence. This class runs
 * the whole chain (config file → key validation → the exclusion ledger),
 * because a loader-level assertion cannot tell "the key survived" from "the
 * key survived and still excludes what it names".
 *
 * The project's own `qmx.yaml` cannot witness any of this: the single channel
 * it excludes has no hyphen in its name.
 */
final class ChannelExclusionKeySpellingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-channel-key-' . uniqid();
        mkdir($this->tempDir . '/src', 0o777, true);

        file_put_contents($this->tempDir . '/src/First.php', <<<'PHP'
            <?php

            namespace Fx\Deep;

            class First
            {
                public function render(bool $pretty): string
                {
                    return $pretty ? 'a' : 'b';
                }
            }
            PHP);

        file_put_contents($this->tempDir . '/src/Second.php', <<<'PHP'
            <?php

            namespace Fx\Deep;

            class Second
            {
                public function render(bool $pretty): string
                {
                    return $pretty ? 'c' : 'd';
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /** The control: without the key, the namespace aggregate is published. */
    #[Test]
    public function itPublishesTheNamespaceAggregateWithoutTheKey(): void
    {
        $tester = $this->runCheck($this->config(''));

        self::assertSame(2, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertContains('size.class-count', $this->channelsOf($tester));
    }

    #[Test]
    public function itExcludesTheNamespaceAggregateOfAHyphenatedChannel(): void
    {
        $tester = $this->runCheck($this->config("      size.class-count: ['Fx\\Deep']"));

        self::assertSame(2, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($tester));
    }

    #[Test]
    public function itExcludesUnderTheHyphenatedChannelLevelPair(): void
    {
        $tester = $this->runCheck($this->config("      size.class-count:namespace: ['Fx\\Deep']"));

        self::assertSame(2, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($tester));
    }

    /**
     * A key naming a channel that never reports at namespace level is accepted
     * and excludes nothing: the validator judges production, not applicability
     * (ADR 0025). Without this, the fix would refuse exactly the keys it is
     * written for — `code-smell.boolean-argument` declares `callable` only.
     */
    #[Test]
    public function itAcceptsAKeyForAChannelThatNeverReportsAtNamespaceLevel(): void
    {
        $tester = $this->runCheck($this->config(
            "      code-smell.boolean-argument: ['Fx\\Deep']",
            owner: 'code-smell.boolean-argument',
        ));

        self::assertSame(2, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertContains('code-smell.boolean-argument', $this->channelsOf($tester));
    }

    /** The group form carries the same hyphen, and the same acceptance. */
    #[Test]
    public function itAcceptsAHyphenatedGroupKey(): void
    {
        $tester = $this->runCheck($this->config(
            "      code-smell.*: ['Fx\\Deep']",
            owner: 'code-smell.boolean-argument',
        ));

        self::assertSame(2, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertContains('code-smell.boolean-argument', $this->channelsOf($tester));
    }

    /**
     * Computed-metric names are the widest reach of the defect: the name
     * validator *prescribes* kebab, and the metrics report at namespace level,
     * so the option was unreachable for the whole vocabulary it is written for.
     */
    #[Test]
    public function itExcludesAComputedMetricNamedInKebab(): void
    {
        $metric = <<<'YAML'
            paths: [src]
            computed_metrics:
              computed.my-score:
                formula: 'm["size.loc"] * 2'
                levels: [namespace]
                warning: 1
            YAML;

        $withoutKey = $this->runCheck($metric, disableComputed: false);
        $withKey = $this->runCheck(
            $metric . "\nrules:\n  computed:\n    exclude_namespace_channels:\n      computed.my-score: ['Fx\\Deep']\n",
            disableComputed: false,
        );

        self::assertSame(2, $withoutKey->getStatusCode(), $withoutKey->getErrorOutput());
        self::assertContains('computed.my-score', $this->channelsOf($withoutKey));

        self::assertSame(2, $withKey->getStatusCode(), $withKey->getErrorOutput());
        self::assertNotContains('computed.my-score', $this->channelsOf($withKey));
    }

    /**
     * The retired `ruleName#violationCode` pair stays refused — and now the
     * refusal quotes what was written instead of a spelling the author never
     * typed.
     */
    #[Test]
    public function itRefusesTheRetiredPairSpellingUnderTheWrittenKey(): void
    {
        $tester = $this->runCheck($this->config("      size.class-count#size.class-count: ['Fx\\Deep']"));

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            'keyed by "size.class-count#size.class-count", which is not a channel selector',
            $tester->getErrorOutput(),
        );
    }

    /**
     * A `qmx.yaml` whose `size.class-count` rule optionally carries one
     * exclusion key line.
     */
    private function config(string $keyLine, string $owner = 'size.class-count'): string
    {
        $option = $keyLine === ''
            ? ''
            : "    exclude_namespace_channels:\n" . $keyLine . "\n";

        return "paths: [src]\nrules:\n  size.class-count:\n    warning: 1\n    error: 50\n"
            . ($owner === 'size.class-count' ? $option : "  {$owner}:\n" . $option);
    }

    /** @return list<string> */
    private function channelsOf(CommandTester $tester): array
    {
        /** @var array{violations: list<array{channel: string}>} $report */
        $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        return array_values(array_unique(array_column($report['violations'], 'channel')));
    }

    private function runCheck(string $config, bool $disableComputed = true): CommandTester
    {
        file_put_contents($this->tempDir . '/qmx.yaml', $config . "\n");

        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        $application = new Application(new ErrorStream());
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
            '--workers' => '0',
            '--config' => $this->tempDir . '/qmx.yaml',
            '--no-progress' => true,
            ...($disableComputed ? ['--disable-rule' => ['computed', 'health.*']] : []),
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
