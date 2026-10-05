<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineUpdater::class)]
final class BaselineRecordExclusionsTest extends TestCase
{
    private string $root;
    private string $cwd;
    private string $path;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->root = TempDirectory::create('qmx-record-exclusions-');
        $this->path = $this->root . '/baseline.json';
        mkdir($this->root . '/src/Domain', 0777, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/src/Foo.php', '<?php namespace App; class Foo { public function bar(): object { return new \\App\\Domain\\Bar(); } }');
        file_put_contents($this->root . '/src/Domain/Bar.php', '<?php namespace App\\Domain; class Bar {}');
        file_put_contents($this->root . '/src/Extra.php', '<?php namespace App; class Extra {}');
        chdir($this->root);
        $this->configuration(false);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        TempDirectory::remove($this->root);
    }

    #[Test]
    public function itRecordsTheDefinitionAndMakesTheAffectedAggregateComparable(): void
    {
        $generated = $this->execute(BaselineGenerateCommand::class, ['baseline' => $this->path, 'paths' => ['src'], '--only-rule' => ['architecture.layer-violation']]);
        self::assertSame(0, $generated->getStatusCode(), $generated->getDisplay() . $generated->getErrorOutput());
        $payload = $this->payload();
        self::assertNotEmpty($payload['entries']);
        foreach ($payload['entries'] as &$entries) {
            foreach ($entries as &$entry) {
                $entry['mode'] = 'suppress';
            }
            unset($entry);
        }
        unset($entries);
        file_put_contents($this->path, json_encode($payload, \JSON_THROW_ON_ERROR));
        $before = (string) file_get_contents($this->path);
        $this->configuration(true);

        $ordinary = $this->execute(BaselineUpdateCommand::class, ['baseline' => $this->path, 'paths' => ['src'], '--only-rule' => ['architecture.layer-violation']]);
        self::assertSame(0, $ordinary->getStatusCode(), $ordinary->getDisplay() . $ordinary->getErrorOutput());
        self::assertStringContainsString('exclusions-differ', $ordinary->getDisplay());
        self::assertSame($before, file_get_contents($this->path));

        $record = $this->execute(BaselineUpdateCommand::class, ['baseline' => $this->path, 'paths' => ['src'], '--record-exclusions' => true, '--only-rule' => ['architecture.layer-violation']]);
        self::assertSame(0, $record->getStatusCode(), $record->getDisplay() . $record->getErrorOutput());
        self::assertStringContainsString('re-recorded', $record->getDisplay());
        self::assertStringContainsString('exclusions changed: 1 occurrence -> 1 occurrence', $record->getDisplay());
        $after = $this->payload();
        self::assertSame(['patterns' => ['exact:src/Extra.php'], 'generated' => 'excluded'], $after['exclusions']);
        self::assertSame($payload['scope'], $after['scope']);
        foreach ($after['entries'] as $entries) {
            foreach ($entries as $entry) {
                self::assertSame('suppress', $entry['mode']);
            }
        }
        $check = $this->execute(CheckCommand::class, ['paths' => ['src'], '--baseline' => $this->path, '--only-rule' => ['architecture.layer-violation'], '--format' => 'json']);
        self::assertSame(0, $check->getStatusCode(), $check->getDisplay() . $check->getErrorOutput());
        self::assertStringNotContainsString('exclusions-differ', $check->getDisplay());
    }

    #[Test]
    public function itRefusesDifferentPathsEvenUnderForceWithoutWriting(): void
    {
        (new BaselineWriter())->write(new Baseline((new FixedClock())->now(), ['src'], [], new RecordedExclusions([], GeneratedFilePolicy::Exclude)), TargetPath::resolve($this->path), AbsolutePath::fromString($this->root));
        unlink($this->path . '.lock');
        $before = (string) file_get_contents($this->path);
        foreach ([false, true] as $force) {
            $tester = $this->execute(BaselineUpdateCommand::class, ['baseline' => $this->path, 'paths' => ['src/Foo.php'], '--record-exclusions' => true, '--force' => $force]);
            self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
            self::assertStringContainsString('exactly the recorded paths', $tester->getDisplay() . $tester->getErrorOutput());
            self::assertSame($before, file_get_contents($this->path));
            self::assertFileDoesNotExist($this->path . '.lock');
        }
    }

    private function configuration(bool $exclude): void
    {
        file_put_contents($this->root . '/qmx.yaml', ($exclude ? "exclude: [{exact: 'src/Extra.php'}]\n" : '') . <<<'YAML'
            architecture:
              layers:
                - name: app
                  patterns: ['App\Foo', 'App\Extra']
                - name: domain
                  patterns: ['App\Domain\**']
              allow:
                app: []
                domain: []
              coverage-gap: ignore
            YAML);
    }

    /**
     * @param class-string<Command> $class
     * @param array<string, mixed> $input
     */
    private function execute(string $class, array $input): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($class);
        \assert($command instanceof Command);
        $tester = new CommandTester($command);
        $tester->execute([...$input, '--no-progress' => true], ['capture_stderr_separately' => true]);
        return $tester;
    }

    /** @return array{entries: array<string, list<array<string, mixed>>>, scope: list<string>, exclusions: array<string, mixed>} */
    private function payload(): array
    {
        return json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);
    }
}
