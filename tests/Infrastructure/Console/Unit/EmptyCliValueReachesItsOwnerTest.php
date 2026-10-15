<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * `--format=` is a value the author typed. The adapter used to drop it, so the
 * CLI door accepted in silence exactly what the YAML door refused; the owners
 * never saw it and could not answer.
 */
final class EmptyCliValueReachesItsOwnerTest extends TestCase
{
    #[Test]
    public function itRefusesAnEmptyBaselineChannelAtItsConsoleOwner(): void
    {
        $root = sys_get_temp_dir() . '/qmx-empty-channel-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        file_put_contents($root . '/A.php', '<?php namespace App; final class A {}');
        file_put_contents($root . '/qmx.yaml', '{}');
        try {
            $app = \Qualimetrix\Infrastructure\Console\Application\Application::forProduct(
                static fn(\Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics $diagnostics) => (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create($diagnostics),
                \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register(),
            );
            $app->setAutoExit(false);
            $tester = new \Symfony\Component\Console\Tester\ApplicationTester($app);
            $code = $tester->run(['command' => 'baseline:explain', 'subject' => 'class:App\\A', 'paths' => ['A.php'], '--working-dir' => $root, '--channel' => '', '--no-cache' => true, '--workers' => '0'], ['capture_stderr_separately' => true]);
            self::assertSame(3, $code, $tester->getErrorOutput());
            self::assertSame('', $tester->getDisplay());
            self::assertStringContainsString('--channel', $tester->getErrorOutput());
            self::assertStringContainsString('must not be empty', $tester->getErrorOutput());
        } finally {
            unlink($root . '/A.php');
            unlink($root . '/qmx.yaml');
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideScalarDoors(): iterable
    {
        yield '--format' => ['format', ConfigSchema::FORMAT];
        yield '--cache-dir' => ['cache-dir', ConfigSchema::CACHE_DIR];
        yield '--fail-on' => ['fail-on', ConfigSchema::FAIL_ON];
        yield '--memory-limit' => ['memory-limit', ConfigSchema::MEMORY_LIMIT];
    }

    #[Test]
    #[DataProvider('provideScalarDoors')]
    public function itCarriesTheEmptyStringThroughToTheOwner(string $option, string $key): void
    {
        self::assertSame('', self::overrides([$option => ''])[$key] ?? null);
    }

    #[Test]
    #[DataProvider('provideScalarDoors')]
    public function itStillWritesNothingWhenTheOptionIsAbsent(string $option, string $key): void
    {
        self::assertArrayNotHasKey($key, self::overrides([]));
    }

    #[Test]
    public function itStillWritesNothingForAnArrayOptionNobodyRepeated(): void
    {
        self::assertArrayNotHasKey(ConfigSchema::EXCLUDE, self::overrides([]));
    }

    #[Test]
    public function itStillCarriesAnOrdinaryValue(): void
    {
        self::assertSame('json', self::overrides(['format' => 'json'])[ConfigSchema::FORMAT] ?? null);
    }

    /**
     * A selector is checked where the option still names it, and handed on in
     * the mapping form a document writes it in, so both doors reach one node.
     */
    #[Test]
    public function itDecodesExplicitExcludeSelectorsAtTheCliBoundary(): void
    {
        self::assertSame(
            [['subtree' => 'build'], ['regex' => '(?:[^/]+/)*cache']],
            self::overrides(['exclude' => ['subtree:build', 'regex:(?:[^/]+/)*cache']])[ConfigSchema::EXCLUDE] ?? null,
        );
    }

    #[Test]
    public function itNamesTheOptionThatWroteEachValue(): void
    {
        self::assertSame(
            [ConfigSchema::EXCLUDE => '--exclude', ConfigSchema::FORMAT => '--format', ConfigSchema::DISABLED_RULES => '--disable-rule'],
            self::request(['exclude' => ['subtree:build'], 'format' => 'json', 'disable-rule' => ['size.loc']])->cliOptionNames,
        );
    }

    /**
     * @param array<string, string|list<string>> $options
     *
     * @return array<string, mixed>
     */
    private static function overrides(array $options): array
    {
        return self::request($options)->cliValues;
    }

    /** @param array<string, string|list<string>> $options */
    private static function request(array $options): ConfigurationResolutionRequest
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED),
            new InputOption('disable-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('only-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED),
            new InputOption('exclude-health', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('memory-limit', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput(
            array_combine(array_map(static fn(string $name): string => '--' . $name, array_keys($options)), $options),
            $definition,
        );

        $pipeline = new class implements ConfigurationPipelineInterface {
            public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument
            {
                return LayeredDocument::of([], AbsolutePath::fromString('/project'));
            }
        };

        return (new ConfigurationInputAdapter(new RuleOptionDocumentForms(), $pipeline, new ErrorStream(), self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class)))->adapt($input, '/project');
    }
}
