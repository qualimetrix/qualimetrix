<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleanupReason;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateDisposition;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateRefusalReason;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Policy\Baseline\UncapturedReason;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;

/**
 * One place where the whole promise of
 * {@see ChannelAcceptability::ConfigurationError} is visible at once: a
 * channel that reports a configuration mistake cannot enter the ratchet by
 * **any** of the five routes an ordinary channel can.
 *
 * The five are not variations of one check. They are five independent
 * branches that each used to ask the same, weaker question — "is this
 * channel declared at all?" — and each would have answered "yes, so it is
 * ordinary debt":
 *
 * 1. the loader, on a hand-written entry ({@see BaselineEntryParser});
 * 2. `baseline:generate`, capturing a measured run ({@see BaselineGenerator});
 * 3. `baseline:update`, re-recording an existing entry ({@see BaselineUpdater});
 * 4. `baseline:cleanup`, listing what should go ({@see BaselineCleaner});
 * 5. `check`, applying the file as a ceiling ({@see BaselineCeilingStage}).
 *
 * Each assertion also pins the *reason*, not merely the refusal: reporting
 * "no rule declares this channel" for a channel that is declared would send
 * a user to delete a line when the fix is to repair their configuration.
 */
#[CoversClass(ChannelAcceptability::class)]
#[CoversClass(ChannelDeclaration::class)]
final class ConfigurationErrorChannelRejectionTest extends TestCase
{
    private const string RULE_NAME = 'architecture.coverage';

    #[Test]
    public function itRefusesToLoadAHandWrittenEntryOnAConfigurationErrorChannel(): void
    {
        $parser = new BaselineEntryParser(self::registry());

        $inert = $parser->parse('project:', [
            'channel' => self::RULE_NAME . '#' . self::RULE_NAME,
            'count' => 1,
        ]);

        self::assertInstanceOf(InertBaselineEntry::class, $inert);
        self::assertSame(InertEntryReason::ConfigurationErrorChannel, $inert->reason);
        self::assertStringContainsString('configuration error', $inert->detail);
    }

    /**
     * `mode: suppress` is the widest acceptance the format has — "accept
     * this identity regardless of magnitude and count". If any spelling of
     * an entry could smuggle a configuration error past the loader, it would
     * be this one.
     */
    #[Test]
    public function itRefusesToLoadSuchAnEntryEvenInSuppressMode(): void
    {
        $parser = new BaselineEntryParser(self::registry());

        $inert = $parser->parse('project:', [
            'channel' => self::RULE_NAME . '#' . self::RULE_NAME,
            'count' => 1,
            'mode' => BaselineEntryMode::Suppress->value,
        ]);

        self::assertInstanceOf(InertBaselineEntry::class, $inert);
        self::assertSame(InertEntryReason::ConfigurationErrorChannel, $inert->reason);
    }

    #[Test]
    public function itDoesNotCaptureAConfigurationErrorFindingIntoAGeneratedBaseline(): void
    {
        $generator = new BaselineGenerator(self::registry(), new FixedClock());

        $capture = $generator->generate([self::finding()], ['src']);

        self::assertSame([], $capture->baseline->entries);
        self::assertCount(1, $capture->uncaptured);
        self::assertSame(UncapturedReason::ConfigurationErrorChannel, $capture->uncaptured[0]->reason);
    }

    #[Test]
    public function itRefusesToUpdateAnEntryOnAConfigurationErrorChannel(): void
    {
        $updater = new BaselineUpdater(self::registry(), new FixedClock());
        $finding = self::finding();
        $entry = new BaselineEntry(BaselineIdentity::forViolation($finding), null, 5);

        $result = $updater->update(self::baselineOf($entry), [$finding], RunScope::fromRecorded(['src']));

        self::assertSame(BaselineUpdateDisposition::Refused, $result->outcomes[0]->disposition);
        self::assertSame(BaselineUpdateRefusalReason::ConfigurationErrorChannel, $result->outcomes[0]->refusalReason);
        self::assertSame(5, $result->baseline->entries[0]->count, 'The stored entry is left exactly as it was.');
    }

    #[Test]
    public function itListsSuchAnEntryForCleanupOnItsOwnReasonEvenWhileTheFindingIsStillMeasured(): void
    {
        $finding = self::finding();
        $entry = new BaselineEntry(BaselineIdentity::forViolation($finding), null, 1);

        $candidates = (new BaselineCleaner(new FixedClock()))->candidates(
            self::baselineOf($entry),
            [$finding],
            self::registry(),
        );

        self::assertCount(1, $candidates);
        self::assertSame(BaselineCleanupReason::ChannelIsConfigurationError, $candidates[0]->reason);
    }

    /**
     * The ceiling is the path where a wrong answer is silent: the finding
     * would simply disappear from the output. It must be reported, and the
     * entry that failed to bound it must be named as inert so the user is
     * not left inferring why.
     */
    #[Test]
    public function itNeverAcceptsAConfigurationErrorGroupAtTheCeilingAndReportsTheEntryAsInert(): void
    {
        $finding = self::finding();
        $entry = new BaselineEntry(BaselineIdentity::forViolation($finding), null, 10, BaselineEntryMode::Suppress);
        $stage = new BaselineCeilingStage(self::baselineOf($entry), self::registry());

        $outcome = $stage->judgeAll([$finding]);

        self::assertSame([$finding], $outcome->result->violations);
        self::assertSame([], $outcome->result->removed);
        self::assertCount(1, $outcome->inertEntries);
        self::assertSame(InertEntryReason::ConfigurationErrorChannel, $outcome->inertEntries[0]->reason);
    }

    /**
     * The control case, and the one the whole package is scoped by: the
     * sibling channel of the very same rule is ordinary code debt and keeps
     * behaving exactly as before.
     */
    #[Test]
    public function itStillAcceptsTheSiblingLayerViolationChannelAsDebt(): void
    {
        $finding = self::finding('architecture.layer-violation', 'architecture.layer-violation');
        $entry = new BaselineEntry(BaselineIdentity::forViolation($finding), null, 1);
        $stage = new BaselineCeilingStage(self::baselineOf($entry), self::registry());

        $outcome = $stage->judgeAll([$finding]);

        self::assertSame([], $outcome->result->violations);
        self::assertSame([$finding], $outcome->result->removed);
        self::assertSame([], $outcome->inertEntries);
    }

    private static function registry(): StubChannelDeclarationRegistry
    {
        $registry = StubChannelDeclarationRegistry::withDefaults();
        $registry->declare(
            self::RULE_NAME . '#' . self::RULE_NAME,
            ChannelDeclaration::configurationError(),
        );

        return $registry;
    }

    private static function finding(
        string $ruleName = self::RULE_NAME,
        string $violationCode = self::RULE_NAME,
    ): Violation {
        return new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'the declared layers do not cover the analysed code',
            severity: Severity::Error,
        );
    }

    private static function baselineOf(BaselineEntry $entry): Baseline
    {
        return new Baseline(generated: new DateTimeImmutable(), scope: ['src'], entries: [$entry]);
    }
}
