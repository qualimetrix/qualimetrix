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
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
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
        self::assertArrayNotHasKey(ConfigSchema::EXCLUDES, self::overrides([]));
    }

    #[Test]
    public function itStillCarriesAnOrdinaryValue(): void
    {
        self::assertSame('json', self::overrides(['format' => 'json'])[ConfigSchema::FORMAT] ?? null);
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private static function overrides(array $options): array
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
                return new ConfigurationDocument([], AbsolutePath::fromString('/project'));
            }
        };

        $captured = (new ConfigurationInputAdapter($pipeline))->adapt($input, '/project')->cliValues;

        return $captured;
    }
}
