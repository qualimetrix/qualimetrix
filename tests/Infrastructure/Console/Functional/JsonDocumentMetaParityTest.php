<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\ChannelRenameReporter;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\DirectiveAuditPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three commands that answer in JSON outside `check` carry the same
 * `meta` block `check --format=json` does, so a reader that found the block
 * in one document finds it, spelled the same, in every other.
 *
 * Compared against the `json` formatter's own output rather than against a
 * key list written here: a list in this file would agree with itself after
 * `json` grew a key and the commands did not.
 */
#[CoversClass(DirectiveAuditPresenter::class)]
#[CoversClass(ChannelRenameReporter::class)]
#[CoversClass(LayerAssignmentCommand::class)]
final class JsonDocumentMetaParityTest extends TestCase
{
    /** Keys whose value is a fact about the run, not about the product. */
    private const array RUN_SPECIFIC_KEYS = ['timestamp'];

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-json-meta-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o755, true);
        file_put_contents($this->tempDir . '/src/Thing.php', "<?php\n\nnamespace App\\Service;\n\nfinal class Thing {}\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function itPublishesTheDocumentationAddressesInTheJsonFormatsMeta(): void
    {
        $meta = self::jsonFormatMeta();

        self::assertSame(['version', 'package', 'timestamp', 'docs', 'llmsTxt'], array_keys($meta));
        self::assertSame(ProductIdentity::docsUrl(), $meta['docs']);
        self::assertSame(ProductIdentity::llmsTxtUrl(), $meta['llmsTxt']);
    }

    /**
     * @param 'directives'|'layer-assignment'|'rename-channels' $command
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function itCarriesTheJsonFormatsMetaKeyForKey(string $command): void
    {
        $document = $this->documentOf($command);

        self::assertArrayHasKey('meta', $document, 'meta leads the document');
        self::assertSame('meta', array_key_first($document));

        $meta = $document['meta'];
        self::assertIsArray($meta);
        $reference = self::jsonFormatMeta();

        self::assertSame(array_keys($reference), array_keys($meta));

        foreach ($reference as $key => $value) {
            if (\in_array($key, self::RUN_SPECIFIC_KEYS, true)) {
                continue;
            }

            self::assertSame($value, $meta[$key], \sprintf('meta.%s', $key));
        }

        self::assertIsString($meta['timestamp']);
        self::assertNotFalse(
            DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $meta['timestamp']),
            'timestamp is written in the same format as the json formatter writes it',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'directives' => ['directives'];
        yield 'debug:layer-assignment' => ['layer-assignment'];
        yield 'baseline:rename-channels' => ['rename-channels'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonFormatMeta(): array
    {
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);
        \assert($registry instanceof FormatterRegistryInterface);

        $document = json_decode(
            $registry->get('json')->format(ReportBuilder::create()->build(), new FormatterContext()),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \assert(\is_array($document) && \is_array($document['meta']));

        /** @var array<string, mixed> */
        return $document['meta'];
    }

    /**
     * @param 'directives'|'layer-assignment'|'rename-channels' $command
     *
     * @return array<string, mixed>
     */
    private function documentOf(string $command): array
    {
        $tester = match ($command) {
            'directives' => $this->execute(DirectivesCommand::class, [
                'paths' => [$this->tempDir . '/src'],
                '--config' => $this->writeFile('qmx.yaml', "paths: []\n"),
                '--format' => 'json',
            ]),
            'layer-assignment' => $this->execute(LayerAssignmentCommand::class, [
                'fqn' => 'App\\Service\\Thing',
                '--config' => $this->writeFile('layers.yaml', \sprintf(
                    "paths: ['%s']\narchitecture:\n  layers:\n    - name: service\n      patterns: ['App\\Service\\**']\n"
                    . "  allow:\n    service: []\n  coverage-gap: ignore\n",
                    $this->tempDir . '/src',
                )),
                '--format' => 'json',
            ]),
            'rename-channels' => $this->execute(BaselineRenameChannelsCommand::class, [
                'baseline' => $this->writeFile('baseline.json', (string) json_encode([
                    'version' => BaselineFormatVersion::CURRENT,
                    'generated' => '2026-01-01T00:00:00+00:00',
                    'scope' => ['src'],
                    'entries' => ['class:App\\Foo' => [['channel' => 'alpha.one', 'count' => 1]]],
                ], \JSON_THROW_ON_ERROR)),
                'map' => $this->writeFile('map.tsv', "old\tnew\treason\nalpha.one\talpha.renamed\twhy\n"),
                '--format' => 'json',
            ]),
        };

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());

        $document = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> */
        return $document;
    }

    /**
     * @param class-string<Command> $class
     * @param array<string, mixed> $input
     */
    private function execute(string $class, array $input): CommandTester
    {
        $command = (new ContainerFactory())->create()->get($class);
        self::assertInstanceOf($class, $command);

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
