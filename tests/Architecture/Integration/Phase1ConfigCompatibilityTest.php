<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Architecture\Support\ArchitectureViolationProjector;

/**
 * Pins the Phase-1-shape (post-ADR 0006) YAML schema against every
 * Stage 2 step of Phase 2: patterns-only layer entries, bare-string allow
 * targets, no templates, no `match:` flag.
 *
 * The test loads a representative Phase-1 config through the real
 * {@see ArchitectureConfigurationFactory} (exercising the full YAML →
 * validators → typed-VO path), binds the result on the shared
 * {@see ArchitectureProcessorInterface}, runs the live pipeline against
 * the canonical {@code ArchitectureSample} fixture, and asserts the
 * normalised violation set matches a golden JSON snapshot.
 *
 * Regenerate the snapshot with {@code QMX_GOLDEN_UPDATE=1} after an
 * intentional behaviour change.
 *
 * Per ADR 0007 (D6) every Stage 2 step is required to leave this test
 * green; a failure signals an unintended regression in Phase-1-shape
 * config handling.
 */
#[Group('integration')]
final class Phase1ConfigCompatibilityTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/Sample';
    private const string GOLDEN_IGNORE_PATH = __DIR__ . '/../Fixtures/Sample/phase1-compat-violations.json';
    private const string GOLDEN_WARN_PATH = __DIR__ . '/../Fixtures/Sample/phase1-compat-violations-warn.json';

    #[Test]
    public function phase1ShapeYamlLoadsAndProducesPinnedViolationSet(): void
    {
        $this->runPhase1Scenario(self::phase1ConfigArray('ignore'), self::GOLDEN_IGNORE_PATH);
    }

    #[Test]
    public function phase1ShapeWithCoverageWarnEmitsExpectedDiagnostic(): void
    {
        // Coverage:warn keeps the same allow-list violations and adds the
        // coverage diagnostic if any classes fall outside layers. The fixture
        // is fully covered by the four layers, so the diagnostic must NOT
        // fire — yet the pipeline path is exercised.
        $this->runPhase1Scenario(self::phase1ConfigArray('warn'), self::GOLDEN_WARN_PATH);
    }

    /**
     * @param array<string, mixed> $configArray
     */
    private function runPhase1Scenario(array $configArray, string $goldenPath): void
    {
        $factory = new ArchitectureConfigurationFactory();
        $result = $factory->fromArray($configArray);

        self::assertSame([], $result->warnings, 'Phase-1 config must not produce deferred warnings.');

        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureProcessorInterface::class);
        self::assertInstanceOf(ArchitectureProcessorInterface::class, $holder);
        $holder->bind($result->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $analysis = $pipeline->analyze(AbsolutePath::fromString(self::FIXTURE_PATH));
        $actual = ArchitectureViolationProjector::project($analysis->violations);

        if (getenv('QMX_GOLDEN_UPDATE') === '1') {
            $payload = [
                '_comment' => 'Golden fixture for Phase1ConfigCompatibilityTest. Pins the Phase-1-shape (post-ADR 0006) YAML schema across Phase 2 steps. Stored fields: rule, severity, source, target, type. Sorted by (rule, source, target, type). Regenerate via QMX_GOLDEN_UPDATE=1.',
                'violations' => $actual,
            ];
            file_put_contents($goldenPath, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
            self::markTestSkipped('Golden file regenerated. Re-run without QMX_GOLDEN_UPDATE to verify.');
        }

        $contents = file_get_contents($goldenPath);
        self::assertNotFalse($contents, 'Golden file must exist: ' . $goldenPath);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('violations', $decoded);
        self::assertIsArray($decoded['violations']);

        self::assertSame(
            $decoded['violations'],
            $actual,
            'Phase-1 config violation set drifted. Set QMX_GOLDEN_UPDATE=1 to regenerate after a deliberate change.',
        );
    }

    /**
     * Phase-1-shape config that mirrors what a real user would write under
     * v0.17 (post-ADR 0006, pre-Phase 2). Deliberately exercises multiple
     * pattern shapes — literal prefix, glob with double-star, trailing
     * backslash, and a multi-pattern layer — so each Stage 2 step exercises
     * the full Phase-1 surface, not just the textbook single-prefix form.
     *
     * @return array<string, mixed>
     */
    private static function phase1ConfigArray(string $coverage): array
    {
        return [
            'layers' => [
                [
                    'name' => 'controller',
                    // Glob double-star — exercises the descendants-only branch.
                    'patterns' => ['Fixtures\\Sample\\Controller\\**'],
                ],
                [
                    'name' => 'service',
                    // Trailing backslash — must normalise to the bare prefix.
                    'patterns' => ['Fixtures\\Sample\\Service\\'],
                ],
                [
                    'name' => 'repository',
                    // Multi-pattern layer — the second pattern matches nothing
                    // in the fixture but must be accepted and not crash.
                    'patterns' => [
                        'Fixtures\\Sample\\Repository',
                        'Fixtures\\Sample\\LegacyRepository',
                    ],
                ],
                [
                    'name' => 'domain',
                    // Literal-prefix pattern — the textbook Phase-1 form.
                    'patterns' => ['Fixtures\\Sample\\Domain'],
                ],
            ],
            'allow' => [
                'controller' => ['service', 'domain'],
                'service' => ['repository', 'domain'],
                'repository' => ['domain'],
                'domain' => [],
            ],
            'coverage' => $coverage,
        ];
    }

}
