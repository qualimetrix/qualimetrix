<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineDocumentReader::class)]
#[CoversClass(BaselineLoader::class)]
#[CoversClass(BaselineCleanupCommand::class)]
#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(CheckCommand::class)]
final class LayerViolationBaselineMigrationTest extends TestCase
{
    private const string CHANNEL = 'architecture.layer-violation';
    private const string SOURCE = 'declaration:class:App\\Application\\Consumer@src/Application/Consumer.php';
    private string $directory;
    private string $cwd;

    protected function setUp(): void
    {
        $this->directory = TempDirectory::create('qmx-layer-migration-');
        $cwd = getcwd();
        self::assertIsString($cwd);
        $this->cwd = $cwd;
        foreach ($this->consumerFiles() as $path => $bytes) {
            $parent = \dirname($this->directory . '/' . $path);
            if (!is_dir($parent)) {
                mkdir($parent, 0o700, true);
            }
            file_put_contents($this->directory . '/' . $path, $bytes);
        }
        copy(__DIR__ . '/../Fixtures/LayerViolationMigration/legacy-layer-findings.json', $this->directory . '/baseline.json');
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        TempDirectory::remove($this->directory);
    }

    #[Test]
    public function itReportsSourceViolationsAndRetiredEntriesFromALegacyBaseline(): void
    {
        $check = $this->command(CheckCommand::class, ['--baseline' => 'baseline.json', '--format' => 'json']);
        self::assertSame(2, $check->getStatusCode(), $check->getDisplay());
        $report = json_decode($check->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $layer = array_values(array_filter($report['violations'], static fn(array $finding): bool => $finding['channel'] === self::CHANNEL));
        self::assertCount(5, $layer);
        foreach ($layer as $finding) {
            self::assertSame(self::SOURCE, $finding['subject']);
        }
        self::assertStringContainsString('union_type', $check->getDisplay());
        $types = array_column(array_column($layer, 'edge'), 'type');
        sort($types);
        self::assertSame(['new', 'new', 'property_type', 'type_hint', 'type_hint'], $types);
    }

    #[Test]
    public function itMigratesOnlyLayerEntriesUsingThePublishedCleanupSelectors(): void
    {
        $before = $this->bytes();
        $foreign = $this->preservedLines($before);
        self::assertCount(4, $foreign);
        $recorded = json_decode($before, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['src'], $recorded['scope']);
        self::assertSame(['patterns' => ['subtree:src/Excluded'], 'generated' => 'excluded'], $recorded['exclusions']);
        self::assertStringContainsString('"magnitudes":[1]', $before);
        self::assertStringContainsString('"mode":"suppress"', $before);
        $old = $this->load();
        self::assertCount(4, $old->entries);
        self::assertCount(2, $old->inertEntries);
        $selectors = [];
        foreach ($old->entries as $entry) {
            if ($entry->identity->channel->code === self::CHANNEL) {
                $selectors[] = $entry->selector()->value;
            }
        }
        foreach ($old->inertEntries as $entry) {
            self::assertSame(self::CHANNEL, $entry->channelKey);
            $selectors[] = $entry->selector->value;
        }
        self::assertCount(4, $selectors);
        $cleanup = $this->command(BaselineCleanupCommand::class, ['baseline' => 'baseline.json']);
        self::assertSame(Command::SUCCESS, $cleanup->getStatusCode(), $cleanup->getDisplay());
        self::assertStringContainsString('2 entries could be removed', $cleanup->getDisplay());
        self::assertSame($before, $this->bytes());
        foreach ($old->inertEntries as $entry) {
            self::assertStringContainsString($entry->selector->value, $cleanup->getDisplay());
        }
        $published = $this->command(BaselineCleanupCommand::class, ['baseline' => 'baseline.json', '--disable-rule' => [self::CHANNEL]]);
        self::assertSame(Command::SUCCESS, $published->getStatusCode(), $published->getDisplay());
        self::assertStringContainsString('not measured: this invocation did not run the rule', $published->getDisplay());
        foreach ($selectors as $selector) {
            self::assertStringContainsString($selector, $published->getDisplay());
        }
        self::assertStringContainsString('cannot be applied: malformed entry', $cleanup->getDisplay());
        self::assertSame($before, $this->bytes());

        $update = $this->command(BaselineUpdateCommand::class, [
            'baseline' => 'baseline.json', '--only-rule' => [self::CHANNEL], '--accept-new' => [self::CHANNEL],
        ]);
        self::assertSame(Command::SUCCESS, $update->getStatusCode(), $update->getDisplay());
        self::assertSame($foreign, $this->preservedLines($this->bytes()));
        $accepted = $this->load();
        self::assertSame(11, $accepted->totalCount());
        self::assertCount(2, $accepted->inertEntries);
        $new = array_values(array_filter($accepted->entries, static fn($entry): bool => $entry->identity->subjectKey === self::SOURCE));
        self::assertCount(5, $new);
        $newSelectors = array_map(static fn($entry): string => $entry->selector()->value, $new);

        foreach ($selectors as $offset => $selector) {
            $remove = $this->command(BaselineCleanupCommand::class, [
                'baseline' => 'baseline.json', '--only-rule' => [self::CHANNEL], '--remove' => [$selector],
            ]);
            self::assertSame(Command::SUCCESS, $remove->getStatusCode(), $remove->getDisplay());
            $remaining = $this->load();
            self::assertSame(10 - $offset, $remaining->totalCount());
            $remainingSelectors = [
                ...array_map(static fn($entry): string => $entry->selector()->value, $remaining->entries),
                ...array_map(static fn($entry): string => $entry->selector->value, $remaining->inertEntries),
            ];
            self::assertNotContains($selector, $remainingSelectors);
            foreach ($newSelectors as $newSelector) {
                self::assertContains($newSelector, $remainingSelectors);
            }
            self::assertSame($foreign, $this->preservedLines($this->bytes()));
        }
        $final = $this->load();
        self::assertSame([], $final->inertEntries);
        self::assertCount(7, $final->entries);
        $check = $this->command(CheckCommand::class, ['--baseline' => 'baseline.json', '--format' => 'json', '--only-rule' => [self::CHANNEL, 'annotation.unused-directive']]);
        self::assertSame(Command::SUCCESS, $check->getStatusCode(), $check->getDisplay());
        $report = json_decode($check->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(0, array_filter($report['violations'], static fn(array $finding): bool => $finding['channel'] === self::CHANNEL));
        self::assertSame($foreign, $this->preservedLines($this->bytes()));
    }

    /**
     * @param class-string<Command> $class
     * @param array<string, mixed> $options
     */
    private function command(string $class, array $options): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($class);
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);
        $tester->execute(['paths' => ['src'], '--no-cache' => true, '--workers' => '0', ...$options]);
        return $tester;
    }

    private function load(): Baseline
    {
        $command = (new ContainerFactory())->create()->get(BaselineUpdateCommand::class);
        self::assertInstanceOf(BaselineUpdateCommand::class, $command);
        $loader = (new ReflectionProperty($command, 'loader'))->getValue($command);
        self::assertInstanceOf(BaselineLoader::class, $loader);
        return $loader->load((new BaselineDocumentReader())->preflight('baseline.json'));
    }

    private function bytes(): string
    {
        $bytes = file_get_contents('baseline.json');
        self::assertIsString($bytes);
        return $bytes;
    }

    /** @return list<string> */
    private function preservedLines(string $bytes): array
    {
        return array_values(array_filter(explode("\n", $bytes), static fn(string $line): bool => str_contains($line, '"scope"')
            || str_contains($line, '"exclusions"')
            || str_contains($line, '"channel":"complexity.ccn"')
            || str_contains($line, '"channel":"code-smell.boolean-argument"')));
    }

    /** @return array<string, string> */
    private function consumerFiles(): array
    {
        return [
            'composer.json' => <<<'CONTENT'
                {"autoload":{"psr-4":{"App\\":"src/"}}}
                CONTENT,
            'qmx.yaml' => <<<'CONTENT'
                exclude:
                  - subtree: src/Excluded
                architecture:
                  layers:
                    - name: application
                      patterns: ['App\Application']
                    - name: domain
                      patterns: ['App\Domain']
                  allow:
                    application: []
                    domain: []
                  coverage-gap: ignore
                rules:
                  complexity.ccn:
                    callable:
                      warning: 2
                      error: 100
                CONTENT,
            'src/Application/Consumer.php' => <<<'CONTENT'
                <?php

                namespace App\Application;

                use App\Domain\UnionTarget;
                use App\Domain\OtherUnionTarget;
                use App\Domain\PromotedTarget;
                use App\Domain\OrdinaryTarget;
                use App\Domain\IgnoredTarget;

                final class Consumer
                {
                    public function __construct(private PromotedTarget $target) {}

                    public function union(UnionTarget|OtherUnionTarget $value) {}

                    public function ordinary()
                    {
                        return new OrdinaryTarget();
                    }

                    public function ignored()
                    {
                        return new IgnoredTarget();
                    }

                    public function branch(bool $overwrite): int
                    {
                        if ($overwrite) {
                            return 1;
                        }
                        return 0;
                    }
                }
                CONTENT,
            'src/Domain/UnionTarget.php' => <<<'CONTENT'
                <?php

                namespace App\Domain;

                final class UnionTarget {}
                CONTENT,
            'src/Domain/OtherUnionTarget.php' => <<<'CONTENT'
                <?php

                namespace App\Domain;

                final class OtherUnionTarget {}
                CONTENT,
            'src/Domain/PromotedTarget.php' => <<<'CONTENT'
                <?php

                namespace App\Domain;

                final class PromotedTarget {}
                CONTENT,
            'src/Domain/OrdinaryTarget.php' => <<<'CONTENT'
                <?php

                namespace App\Domain;

                final class OrdinaryTarget {}
                CONTENT,
            'src/Domain/IgnoredTarget.php' => <<<'CONTENT'
                <?php

                namespace App\Domain;

                /**
                 * @qmx-ignore architecture.layer-violation Accepted incoming dependency while migrating.
                 */
                final class IgnoredTarget {}
                CONTENT,
            'src/Excluded/Excluded.php' => <<<'CONTENT'
                <?php

                namespace App\Excluded;

                final class Excluded {}
                CONTENT,

        ];
    }
}
