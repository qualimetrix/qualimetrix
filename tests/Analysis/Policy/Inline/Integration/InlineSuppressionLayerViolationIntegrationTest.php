<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Infrastructure\Console\Support\PreparedAnalysis;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('integration')]
final class InlineSuppressionLayerViolationIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/IgnoreSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\IgnoreSample';

    #[Test]
    public function itDropsOnlyTheSourceViolationWhoseDeclarationCarriesQmxIgnore(): void
    {
        $analysisResult = $this->analyse([
            'layers' => [
                ['name' => 'controller', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Controller']],
                ['name' => 'service', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Service']],
                ['name' => 'repository', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Repository']],
                ['name' => 'domain', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Domain']],
            ],
            'allow' => [
                'controller' => ['service'],
                'service' => ['repository', 'domain'],
                'repository' => ['domain'],
                'domain' => [],
            ],
            'coverage-gap' => 'ignore',
        ]);

        // Sanity: AnalysisPipeline must surface BOTH controllers as raw
        // findings — suppression is applied downstream, not inside the
        // pipeline. If only one fires here, the fixture is broken, not the
        // suppression filter.
        $rawSources = array_map(
            static fn(Finding $v): string => $v->symbolPath->toString(),
            $this->filterByRule($analysisResult->findings(), LayerViolationRule::NAME),
        );
        self::assertNotEmpty(
            array_filter($rawSources, static fn(string $s): bool => str_contains($s, 'PolicedController')),
            'Pipeline must emit a raw layer-violation for PolicedController.',
        );
        self::assertNotEmpty(
            array_filter($rawSources, static fn(string $s): bool => str_contains($s, 'SilencedController')),
            'Pipeline must emit a raw layer-violation for SilencedController too — '
            . 'the suppression layer runs downstream and must be exercised to drop it.',
        );

        $suppressionFilter = new SuppressionFilter();
        $filtered = $suppressionFilter->apply($analysisResult->findings(), $analysisResult->directives->suppressions)->retained;

        $filteredSources = array_map(
            static fn(Finding $v): string => $v->symbolPath->toString(),
            $this->filterByRule($filtered, LayerViolationRule::NAME),
        );

        self::assertNotEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'PolicedController')),
            'After suppression: PolicedController without @qmx-ignore must remain.',
        );
        self::assertEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'SilencedController')),
        );

        foreach ($this->filterByRule($filtered, LayerViolationRule::NAME) as $finding) {
            self::assertSame($finding->symbolPath->toCanonical(), $finding->subject->toSymbolPath()->toCanonical());
        }
    }

    #[Test]
    #[DataProvider('declarationControls')]
    public function itAppliesOnlySourceDeclarationControlsInTheRealCommand(string $sourceTag, string $targetTag, int $violations, int $unused): void
    {
        $root = sys_get_temp_dir() . '/qmx-layer-controls-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        $cwd = getcwd();
        self::assertIsString($cwd);
        try {
            file_put_contents($root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
            file_put_contents($root . '/qmx.yaml', <<<'YAML'
                architecture:
                  layers:
                    - name: source
                      patterns: ['App\Source']
                    - name: target
                      patterns: ['App\Target']
                  allow:
                    source: []
                    target: []
                  coverage-gap: ignore
                YAML);
            file_put_contents($root . '/src/Controlled.php', "<?php\nnamespace App\\Source;\n" . $sourceTag . "\nfinal class Controlled { public function make() { return new \\App\\Target\\Repository(); } }\n");
            file_put_contents($root . '/src/Plain.php', '<?php namespace App\\Source; final class Plain { public function make() { return new \\App\\Target\\Repository(); } }');
            file_put_contents($root . '/src/Repository.php', "<?php\nnamespace App\\Target;\n" . $targetTag . "\nfinal class Repository {}\n");
            chdir($root);
            $command = (new ContainerFactory())->create()->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            $tester->execute(['paths' => ['src'], '--format' => 'json', '--no-cache' => true, '--workers' => '0', '--only-rule' => [LayerViolationRule::NAME, 'annotation.unused-directive']]);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            $findings = $report['violations'];
            $layer = array_values(array_filter($findings, static fn(array $finding): bool => $finding['code'] === LayerViolationRule::NAME));
            self::assertCount($violations, $layer);
            $unusedFindings = array_values(array_filter($findings, static fn(array $finding): bool => $finding['code'] === 'annotation.unused-directive'));
            self::assertCount($unused, $unusedFindings);
            if ($unused === 1) {
                self::assertSame('src/Repository.php', $unusedFindings[0]['file']);
                self::assertStringContainsString('matched nothing', $unusedFindings[0]['message']);
            }
            foreach ($layer as $finding) {
                self::assertStringContainsString('App\\Source', $finding['subject']);
            }
        } finally {
            chdir($cwd);
            $sourceFiles = glob($root . '/src/*');
            self::assertIsArray($sourceFiles);
            foreach ($sourceFiles as $file) {
                unlink($file);
            }
            rmdir($root . '/src');
            $rootFiles = glob($root . '/*');
            self::assertIsArray($rootFiles);
            foreach ($rootFiles as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function declarationControls(): iterable
    {
        $tag = "/**\n * @qmx-ignore architecture.layer-violation Accepted dependency.\n */";
        yield 'plain declarations' => ['', '', 2, 0];
        yield 'source declaration' => [$tag, '', 1, 0];
        yield 'target declaration' => ['', $tag, 2, 1];
    }

    /** @param array<string, mixed> $architecture */
    private function analyse(array $architecture): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $fixture = PreparedAnalysis::start($root, [$root], ['architecture' => $architecture, 'include_generated' => true]);
        try {
            $holder = $fixture->container()->get(LayerPolicyPreparationInterface::class);
            self::assertInstanceOf(ArchitecturePolicy::class, $holder);
            $pipeline = $fixture->container()->get(AnalysisPipelineInterface::class);
            self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
            return $pipeline->analyze($fixture->prepared()->runConfiguration);
        } finally {
            $fixture->close();
        }
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function filterByRule(array $findings, string $ruleName): array
    {
        return array_values(array_filter(
            $findings,
            static fn(Finding $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
