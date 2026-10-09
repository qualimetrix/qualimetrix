<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Path\AbsolutePath;
use WeakReference;

#[CoversClass(ComputedMetricAnalysis::class)]
final class ComputedMetricAnalysisTest extends TestCase
{
    #[Test]
    public function itPreservesTheInstalledFormulaSourceWhenLaterResolutionFails(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document(['computed_metrics' => [
            'computed.x' => ['formula' => 'sqrt(-1)', 'levels' => ['project']],
        ]])));
        try {
            $analysis->resolve($this->document(['exclude_health' => ['unknown']]));
            self::fail('Invalid candidate configuration must refuse.');
        } catch (ConfigurationRefusal) {
            $definition = $analysis->find('computed.x');
            self::assertNotNull($definition);
            $refusal = $analysis->refuseFormula($definition, \Qualimetrix\Core\Symbol\SymbolLevel::Project, 'Runtime failure');
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['computed_metrics', 'computed.x', 'formula'], $refusal->position()?->segments);
            self::assertSame('sqrt(-1)', $definition->getFormulaForLevel(\Qualimetrix\Core\Symbol\SymbolLevel::Project));
        }
    }

    #[Test]
    public function itRefusesLoudlyWhenARealTokenHasLostAnAuthoredFormulaSource(): void
    {
        $definition = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition(
            'computed.x',
            ['project' => 'sqrt(-1)'],
            '',
            [\Qualimetrix\Core\Symbol\SymbolLevel::Project],
        );
        $authorship = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricAuthorship();
        $token = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions(
            [$definition],
            static fn(\Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition $selected, \Qualimetrix\Core\Symbol\SymbolLevel $level, string $summary): ConfigurationRefusal => $authorship->refuseFormula($selected, $level->value, $summary),
        );
        $refusal = $token->refuseFormula($definition, \Qualimetrix\Core\Symbol\SymbolLevel::Project, 'Runtime failure');
        self::assertStringContainsString('authored formula source is unavailable', $refusal->summary());
        self::assertSame(ConfigurationSource::Resolved, $refusal->sources()[0]->source());
    }

    #[Test]
    public function itPreservesTheResolvedCoordinateForSyntheticDefinitionsWithoutARefusalCallback(): void
    {
        $definition = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition(
            'computed.x',
            ['project' => 'sqrt(-1)'],
            '',
            [\Qualimetrix\Core\Symbol\SymbolLevel::Project],
        );
        $token = new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([$definition]);
        $refusal = $token->refuseFormula($definition, \Qualimetrix\Core\Symbol\SymbolLevel::Project, 'Runtime failure');
        self::assertSame(ConfigurationSource::Resolved, $refusal->sources()[0]->source());
        self::assertSame(['computed_metrics', 'computed.x'], $refusal->position()?->segments);
        self::assertSame('Runtime failure', $refusal->summary());
    }

    #[Test]
    public function itDefaultReturnsEmptyArray(): void
    {
        self::assertSame([], $this->analysis()->all());
    }

    #[Test]
    public function itSetAndGetDefinitions(): void
    {
        $analysis = $this->analysis();
        $resolved = $analysis->resolve($this->document([]));
        $analysis->replace($resolved);

        self::assertCount(6, $analysis->all());
        self::assertNotNull($analysis->find('health.overall'));
        self::assertSame($resolved->all(), $analysis->all());
    }

    #[Test]
    public function itPreservesInstalledDefinitionsWhenResolutionFails(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document([])));

        try {
            $analysis->resolve($this->document(['exclude_health' => ['unknown']]));
            self::fail('Expected invalid configuration.');
        } catch (ConfigurationRefusal) {
            self::assertNotNull($analysis->find('health.overall'));
        }
    }

    #[Test]
    public function itSetDefinitionsReplacePrevious(): void
    {
        $analysis = $this->analysis();
        $analysis->replace($analysis->resolve($this->document(['computed_metrics' => ['computed.first' => ['formula' => '1']]])));
        self::assertNotNull($analysis->find('computed.first'));

        $analysis->replace($analysis->resolve($this->document(['computed_metrics' => ['computed.second' => ['formula' => '2']]])));
        self::assertNull($analysis->find('computed.first'));
        self::assertNotNull($analysis->find('computed.second'));
    }

    #[Test]
    public function itReusesOneResolvedValueWithoutRetainingItsDocument(): void
    {
        $analysis = $this->analysis();
        $document = $this->document([]);
        $reference = WeakReference::create($document);
        $resolved = $analysis->resolve($document);
        self::assertSame($resolved, $analysis->resolve($document));
        self::assertNotSame($resolved, $analysis->resolve($this->document([])));
        unset($document);
        gc_collect_cycles();
        self::assertNull($reference->get());
        self::assertCount(6, $resolved->all());
    }

    private function analysis(): ComputedMetricAnalysis
    {
        return new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
        );
    }

    /** @param array<string, mixed> $written one configuration file */
    private function document(array $written): ConfigurationDocument
    {
        return new ConfigurationDocument([], AbsolutePath::fromString('/project'), DocumentComposer::compose(
            new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]),
            [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), AuthoredNode::fromPlain($written))],
        ));
    }
}
