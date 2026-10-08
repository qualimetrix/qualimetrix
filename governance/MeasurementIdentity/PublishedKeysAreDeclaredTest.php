<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\MeasurementIdentity;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinitionCatalogInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

final class PublishedKeysAreDeclaredTest extends TestCase
{
    #[Test]
    public function itPublishesOnlyKeysDeclaredAtTheirExportedLevel(): void
    {
        $container = (new ContainerFactory())->configure();
        $container->getAlias(MetricDefinitionCatalogInterface::class)->setPublic(true);
        $container->compile();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $fixture = \dirname(__DIR__, 2) . '/tests/Analysis/Evidence/Measurement/Fixtures/AggregationExport';
        $config = sys_get_temp_dir() . '/qmx-published-keys-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($config, "rules: {}\n");
        $previous = getcwd();
        self::assertNotFalse($previous);
        chdir($fixture);
        try {
            $tester = new CommandTester($command);
            self::assertSame(0, $tester->execute([
                'paths' => ['.'],
                '--config' => $config,
                '--format' => 'metrics',
                '--workers' => '0',
                '--no-cache' => true,
                '--fail-on' => 'none',
            ], ['capture_stderr_separately' => true]));
            $document = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        } finally {
            chdir($previous);
            unlink($config);
        }
        self::assertIsArray($document);
        self::assertTrue($document['coverage']['complete']);
        self::assertNotEmpty($document['symbols']);
        $measured = $container->get(MetricDefinitionCatalogInterface::class);
        $computed = $container->get(ComputedMetricDefinitionCatalogInterface::class);
        self::assertInstanceOf(MetricDefinitionCatalogInterface::class, $measured);
        self::assertInstanceOf(ComputedMetricDefinitionCatalogInterface::class, $computed);
        $declared = [];
        foreach ($measured->all() as $definition) {
            foreach ($definition->publicationLevels() as $level) {
                $declared[$level->value][$definition->name] = true;
            }
            foreach (SymbolLevel::cases() as $level) {
                foreach ($definition->publishedSuffixes($level) as $suffix) {
                    $declared[$level->value][$definition->name . '.' . $suffix] = true;
                }
            }
        }
        foreach ($computed->all() as $definition) {
            foreach ($definition->reportingLevels() as $level) {
                $declared[$level->value][$definition->name] = true;
            }
        }
        $reached = [];
        $types = [];
        $classes = [];
        $undeclared = [];
        foreach ($document['symbols'] as $symbol) {
            $level = match ($symbol['type']) {
                'method', 'function' => SymbolLevel::Callable,
                'class' => SymbolLevel::Class_,
                'namespace' => SymbolLevel::Namespace_,
                'file' => SymbolLevel::File,
                'project' => SymbolLevel::Project,
                default => throw new LogicException('The export contains an unknown symbol type.'),
            };
            $reached[$level->value] = true;
            $types[$symbol['type']] = true;
            if ($symbol['type'] === 'class') {
                $classes[] = $symbol;
            }
            foreach (array_keys($symbol['metrics']) as $key) {
                if (!isset($declared[$level->value][$key])) {
                    $undeclared[] = $level->value . ' / ' . $key . ' / ' . $symbol['subject'];
                }
            }
        }
        self::assertCount(8, $classes);
        self::assertCount(3, array_filter($classes, static fn(array $symbol): bool => $symbol['name'] === 'AggregationExport\\Leaf\\Example'));
        foreach (['method', 'function', 'class', 'namespace', 'file', 'project'] as $type) {
            self::assertArrayHasKey($type, $types, 'The export fixture did not reach ' . $type);
        }
        self::assertSame([], $undeclared, 'Published keys with no definition: ' . implode(', ', $undeclared));
        foreach (SymbolLevel::cases() as $level) {
            self::assertArrayHasKey($level->value, $reached, 'The export fixture did not reach ' . $level->value);
        }
    }
}
