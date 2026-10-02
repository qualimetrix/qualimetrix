<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A `suppress_namespace_channels` key reaches the run under the spelling its
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
    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-channel-key-' . bin2hex(random_bytes(6));
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
        $workingDirectory = getcwd();
        if ($workingDirectory === false || !chdir($this->tempDir)) {
            throw new RuntimeException('Cannot enter the fixture working directory');
        }
        $this->originalWorkingDirectory = $workingDirectory;

    }

    protected function tearDown(): void
    {
        if (!chdir($this->originalWorkingDirectory)) {
            throw new RuntimeException('Cannot restore the working directory');
        }

        self::removeDirectory($this->tempDir);
    }

    /** The control: without the key, the namespace aggregate is published. */
    #[Test]
    public function itPublishesTheNamespaceAggregateWithoutTheKey(): void
    {
        $tester = $this->runCheck($this->config(''));

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertContains('size.class-count', $this->channelsOf($tester));
    }

    #[Test]
    public function itExcludesTheNamespaceAggregateOfAHyphenatedChannel(): void
    {
        $tester = $this->runCheck($this->config("      size.class-count:\n        - subtree: Fx\\Deep"));

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($tester));
    }

    #[Test]
    public function itExcludesUnderTheHyphenatedChannelLevelPair(): void
    {
        $tester = $this->runCheck($this->config("      size.class-count:namespace:\n        - subtree: Fx\\Deep"));

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($tester));
    }

    #[Test]
    public function itRefusesAKeyForAChannelThatNeverReportsAtNamespaceLevel(): void
    {
        $tester = $this->runCheck($this->config(
            "      code-smell.boolean-argument:\n        - subtree: Fx\\Deep",
            owner: 'code-smell.boolean-argument',
        ));

        $this->assertNamespaceRefusal($tester, 'code-smell.boolean-argument');
        $lawful = $this->runCheck($this->config("      size.class-count:\n        - subtree: Fx\\Deep"));
        self::assertSame(0, $lawful->getStatusCode(), $lawful->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($lawful));
        self::assertContains('code-smell.boolean-argument', $this->channelsOf($lawful));
    }

    #[Test]
    public function itRefusesAHyphenatedGroupWithoutAnApplicableChannel(): void
    {
        $tester = $this->runCheck($this->config(
            "      code-smell.*:\n        - subtree: Fx\\Deep",
            owner: 'code-smell.boolean-argument',
        ));

        $this->assertNamespaceRefusal($tester, 'code-smell.*');
        $lawful = $this->runCheck($this->config("      size.*:\n        - subtree: Fx\\Deep"));
        self::assertSame(0, $lawful->getStatusCode(), $lawful->getErrorOutput());
        self::assertNotContains('size.class-count', $this->channelsOf($lawful));
        self::assertContains('code-smell.boolean-argument', $this->channelsOf($lawful));
    }

    private function assertNamespaceRefusal(CommandTester $tester, string $key): void
    {
        self::assertSame(3, $tester->getStatusCode());
        self::assertSame([
            'error' => 'Configuration error: Option "suppress_namespace_channels" for rule "code-smell.boolean-argument", keyed by "' . $key . '", addresses "code-smell.boolean-argument", and it does not report at level "namespace" — the levels available are "callable". The pair can never match anything.',
            'exit_code' => 3,
            'position' => [
                'path' => ['rules', 'code-smell.boolean-argument', 'suppress_namespace_channels', $key],
                'written' => $key,
                'accepted' => [],
                'closed' => false,
            ],
            'source' => [['kind' => 'file', 'name' => $this->tempDir . '/qmx.yaml', 'imported_by' => null]],
        ], json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));
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
            $metric . "\nrules:\n  computed:\n    suppress_namespace_channels:\n      computed.my-score:\n        - subtree: Fx\\Deep\n",
            disableComputed: false,
        );

        self::assertSame(0, $withoutKey->getStatusCode(), $withoutKey->getErrorOutput());
        self::assertContains('computed.my-score', $this->channelsOf($withoutKey));

        self::assertSame(0, $withKey->getStatusCode(), $withKey->getErrorOutput());
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
        $tester = $this->runCheck($this->config("      size.class-count#size.class-count:\n        - subtree: Fx\\Deep"));

        self::assertSame(3, $tester->getStatusCode());
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            'Configuration error: Option "suppress_namespace_channels" for rule "size.class-count", keyed by "size.class-count#size.class-count", is not a channel selector. The "ruleName#code" spelling of a channel is gone: a channel is named by its code alone. Write "size.class-count".',
            $envelope['error'],
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
            : "    suppress_namespace_channels:\n" . $keyLine . "\n";

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
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter, new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader());
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
