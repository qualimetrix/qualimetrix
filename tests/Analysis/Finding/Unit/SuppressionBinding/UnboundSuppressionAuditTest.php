<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The cases the command cannot stage: a run with no namespace tree, and the
 * exact matcher semantics the suppression itself uses.
 *
 * Everything about wiring, selection and exit codes is proved by
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\SuppressionBinding\UnboundSuppressionIntegrationTest}
 * on a real run instead; a passing unit test here would say nothing about any
 * of it.
 */
#[CoversClass(UnboundSuppressionAudit::class)]
final class UnboundSuppressionAuditTest extends TestCase
{
    /**
     * A run that built no namespace tree has no universe to judge namespaces
     * against, and "no universe" is not "nothing bound": reporting every
     * configured namespace there would turn a missing measurement into an
     * accusation. The path half is unaffected — its universe is the coverage,
     * which a run always has.
     */
    #[Test]
    public function itJudgesNoNamespaceWhenTheRunBuiltNoNamespaceTree(): void
    {
        $channels = $this->channelsOf($this->audit()->findings(
            ['src/Gone'],
            ['Sample\\Gone'],
            [RelativePath::fromString('src/Service.php')],
            null,
            $this->scope(),
        ));

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_PATH], $channels);
    }

    /**
     * An empty tree is a measured answer, unlike `null`: the run declared no
     * namespace, so a configured namespace really did bind to nothing.
     */
    #[Test]
    public function itJudgesNamespacesAgainstAnEmptyTree(): void
    {
        $channels = $this->channelsOf($this->audit()->findings(
            [],
            ['Sample\\Gone'],
            [],
            [],
            $this->scope(),
        ));

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_NAMESPACE], $channels);
    }

    /**
     * Binding is decided by the same two matchers the suppression will use, so
     * a glob that the filter would honour is a hit here too. Were the two to
     * part, the channel would accuse a value that goes on suppressing findings
     * every run.
     */
    #[Test]
    public function itHonoursTheGlobAndPrefixModesTheSuppressionFiltersUse(): void
    {
        $bound = $this->audit()->findings(
            ['src/*Service.php', 'src'],
            ['Sample\\*', 'Sample'],
            [RelativePath::fromString('src/UserService.php')],
            ['Sample\\Deep'],
            $this->scope(),
        );

        self::assertSame([], $this->channelsOf($bound));
    }

    /** A prefix stops at a path boundary, exactly as the filter's does. */
    #[Test]
    public function itDoesNotTreatAPrefixOfANameAsABinding(): void
    {
        $findings = $this->audit()->findings(
            ['src/Serv'],
            [],
            [RelativePath::fromString('src/Service/User.php')],
            [],
            $this->scope(),
        );

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_PATH], $this->channelsOf($findings));
    }

    /** Nothing is judged with the producer switched off. */
    #[Test]
    public function itJudgesNothingWhenTheProducerIsDisabled(): void
    {
        $audit = $this->audit(enabled: false);

        self::assertSame([], $audit->findings(
            ['src/Gone'],
            ['Sample\\Gone'],
            [],
            [],
            $this->scope(),
        ));
    }

    /**
     * The ledger is read by both option spellings, because
     * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} applies both:
     * a channel reading only one of them would call half the configured
     * patterns unbound while the run was busy applying them.
     */
    #[Test]
    public function itReadsBothSpellingsOfThePerRuleLedgerOptions(): void
    {
        $audit = $this->audit(ledger: [
            'complexity.ccn' => ['suppress_paths' => ['src/Gone']],
            'design.dit' => ['suppressNamespaces' => ['Sample\\Gone']],
            'code-smell.goto' => ['suppressPaths' => 'src/Service.php'],
        ]);

        $findings = $audit->findings(
            [],
            [],
            [RelativePath::fromString('src/Service.php')],
            [],
            $this->scope(),
        );
        $messages = array_map(static fn(Finding $finding): string => $finding->message, $findings);

        self::assertCount(2, $findings, implode(' | ', $messages));
        self::assertStringContainsString('complexity.ccn', $messages[0]);
        self::assertStringContainsString('design.dit', $messages[1]);
    }

    /**
     * The defect this gate was added for: a legitimate entry written for
     * `qmx check .` reported as unbound by `qmx check src/`, which never
     * looked where the entry points. Both halves fire at once, so both are
     * asserted here.
     */
    #[Test]
    public function itJudgesNoValueWhoseSubjectTheRunDidNotAnalyse(): void
    {
        $findings = $this->audit()->findings(
            ['tests/Gone'],
            ['Sample\\Tests\\Gone'],
            [RelativePath::fromString('src/Service.php')],
            ['Sample'],
            $this->scope(),
        );

        self::assertSame([], $this->channelsOf($findings));
    }

    /**
     * The same two entries on a run that does reach them: unjudgeable is a
     * fact about the pair, not a permanent exemption, and a stale entry is
     * still named the moment a run can tell.
     */
    #[Test]
    public function itJudgesTheSameValuesOnARunThatReachesTheirSubject(): void
    {
        $findings = $this->audit()->findings(
            ['tests/Gone'],
            ['Sample\\Tests\\Gone'],
            [RelativePath::fromString('src/Service.php'), RelativePath::fromString('tests/ServiceTest.php')],
            ['Sample', 'Sample\\Tests'],
            $this->scope([$this->tempDir . '/src', $this->tempDir . '/tests']),
        );

        self::assertSame(
            [UnboundSuppressionOptions::UNMATCHED_PATH, UnboundSuppressionOptions::UNMATCHED_NAMESPACE],
            $this->channelsOf($findings),
        );
    }

    /**
     * A value beginning with a glob names no place, so no run can be shown to
     * reach it. The named cost of the safe direction: it is never reported.
     */
    #[Test]
    public function itJudgesNoValueThatBeginsWithAGlob(): void
    {
        $findings = $this->audit()->findings(
            ['*Gone.php'],
            [],
            [RelativePath::fromString('src/Service.php')],
            [],
            $this->scope(),
        );

        self::assertSame([], $this->channelsOf($findings));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function channelsOf(array $findings): array
    {
        return array_map(static fn(Finding $finding): string => $finding->ruleName, $findings);
    }

    /**
     * @param array<string, mixed> $ledger
     */
    private function audit(bool $enabled = true, array $ledger = []): UnboundSuppressionAudit
    {
        $execution = self::createStub(RuleExecutionInterface::class);
        // Selection is proved on a real run; here the audit's own arithmetic is
        // the subject, so `publishable()` passes everything through.
        $execution->method('publishable')->willReturnArgument(0);

        $configuration = self::createStub(RuleConfigurationInterface::class);
        $configuration->method('all')->willReturn($ledger);

        return new UnboundSuppressionAudit(
            new UnboundSuppressionOptions($enabled),
            $execution,
            $configuration,
        );
    }

    /**
     * A real tree, because the per-value gate answers by looking at one: the
     * project autoloads `Sample\` from `src/` for production and
     * `Sample\Tests\` from `tests/` for development, and the run below
     * analyses `src/` alone — the exact shape that reported three channels at
     * once before this gate existed.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-unbound-audit-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/src', 0777, true);
        mkdir($this->tempDir . '/tests', 0777, true);
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Sample\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Sample\\Tests\\' => 'tests/']],
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    private string $tempDir = '';

    /**
     * @param ?list<string> $analyzedPaths
     */
    private function scope(?array $analyzedPaths = null): ValueScopeJudgement
    {
        return new ValueScopeJudgement(
            $this->tempDir,
            (new ComposerReader())->extractPsr4Roots($this->tempDir . '/composer.json'),
            $analyzedPaths ?? [$this->tempDir . '/src'],
        );
    }
}
