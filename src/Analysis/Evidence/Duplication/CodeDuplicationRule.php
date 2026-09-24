<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Detects duplicated code blocks across files.
 *
 * Generates one finding per copy of a duplicated block, located on that copy
 * and naming the other copies as its related locations. Each copy has an
 * identity of its own — see {@see copyOccurrenceKey()} — so a new copy is a
 * new finding to a baseline, to a fingerprint-matching consumer and to a git
 * scope alike.
 */
final class CodeDuplicationRule extends AbstractRule
{
    public const string NAME = 'duplication.clone';
    public const string DOCS_PAGE = 'rules/duplication.md';

    /**
     * Frozen to today's channel spelling on purpose — it does not follow a
     * future rename of {@see NAME}. Changing this value moves the
     * `occurrence` of every already-accepted finding on this channel.
     */
    private const string OCCURRENCE_KIND = 'duplication.code-duplication';

    public const int REMEDIATION_MINUTES = 15;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;

    /**
     * How many other copies one copy's finding names, in its message and as
     * its related locations; the message counts the rest. Every copy is
     * reported by a finding of its own, so a bound here drops no copy from the
     * report — without it a block copied N times would carry N² related
     * locations, which a thousand copies turn into a SARIF report that
     * exhausts memory.
     */
    private const int NAMED_COPY_LIMIT = 10;

    public function __construct(
        RuleOptionsInterface $options,
        private readonly DuplicationResultProvider $resultProvider,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects duplicated code blocks';
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($this->resultProvider->all() as $block) {
            array_push($findings, ...$this->copyFindings($context, $block));
        }

        return $findings;
    }

    public static function getOptionsClass(): string
    {
        return CodeDuplicationOptions::class;
    }

    /**
     * `duplication.clone` reports the duplicated block's line
     * count (`$block->lines` — see the emission above) as `metricValue`,
     * judged worse the higher it goes:
     * {@see CodeDuplicationOptions::getSeverity()}'s `$value >=
     * $this->error` (line 58) / `$value >= $this->warning` (line 62).
     * Emission itself is unconditional — every copy of every `DuplicateBlock`
     * produces a `Finding` regardless of size (`$severity ?? Severity::Warning` at
     * line 102 is only ever a fallback) — but that does not change the
     * direction question: the threshold comparison genuinely gates
     * *severity*, and severity is monotone in `$block->lines`, so `higher`
     * is a real fact about the code, not an inference from the channel's
     * unconditional trigger.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project),
        ];
    }

    /**
     * The block's copies are turned into locations once and every finding
     * shares them rather than building its own.
     *
     * @return list<Finding>
     */
    private function copyFindings(AnalysisContext $context, DuplicateBlock $block): array
    {
        $projectPath = SymbolPath::forProject();
        $subject = MetricSubject::aggregate($projectPath);
        $severity = $this->getEffectiveSeverity($context, $this->options, $subject, $block->lines) ?? Severity::Warning;
        $hintPart = $block->hint !== null ? \sprintf(': "%s"', $block->hint) : '';

        $locations = array_map(
            static fn(DuplicateLocation $copy): Location => new Location($copy->file, $copy->startLine, precise: true),
            $block->locations,
        );

        $findings = [];
        $copiesInFile = [];

        foreach ($locations as $index => $location) {
            $file = $block->locations[$index]->pathString();
            $copyInFile = $copiesInFile[$file] = ($copiesInFile[$file] ?? -1) + 1;
            $named = self::namedOthers($block->occurrences(), $index);
            $unnamed = $block->occurrences() - 1 - \count($named);

            $findings[] = new Finding(
                location: $location,
                subject: $subject,
                symbolPath: $projectPath,
                ruleName: $this->getName(),
                code: $this->getName(),
                message: \sprintf(
                    'Duplicated code block (%d lines, %d occurrences)%s — also at %s%s',
                    $block->lines,
                    $block->occurrences(),
                    $hintPart,
                    implode(', ', array_map(static fn(int $other): string => $block->locations[$other]->toString(), $named)),
                    $unnamed > 0 ? \sprintf(' and %d more', $unnamed) : '',
                ),
                severity: $severity,
                metricValue: $block->lines,
                relatedLocations: array_map(static fn(int $other): Location => $locations[$other], $named),
                recommendation: 'Extract duplicated code into a shared method or class.',
                occurrenceKey: self::copyOccurrenceKey($block->contentHash, $file, $copyInFile),
            );
        }

        return $findings;
    }

    /**
     * A copy is the block's content, the file holding the copy and the
     * copy's place among the block's copies in that file, counted in line
     * order. No line number enters it, so code added or removed around a copy
     * does not re-key it. A copy moved to another file, or a file renamed, is
     * a new copy and leaves a stale one behind; a copy pasted above another
     * in the same file takes the lower place, and the one it displaced reads
     * as the new copy — the count of new copies stays right.
     */
    private static function copyOccurrenceKey(string $contentHash, string $file, int $copyInFile): OccurrenceKey
    {
        return OccurrenceKey::semantic(self::OCCURRENCE_KIND, [
            'contentHash' => $contentHash,
            'file' => $file,
            'copyInFile' => $copyInFile,
        ]);
    }

    /**
     * The first {@see NAMED_COPY_LIMIT} copies other than `$index`, in the
     * block's order.
     *
     * @return list<int>
     */
    private static function namedOthers(int $copies, int $index): array
    {
        $candidates = range(0, min($copies, self::NAMED_COPY_LIMIT + 1) - 1);

        return \array_slice(array_values(array_diff($candidates, [$index])), 0, self::NAMED_COPY_LIMIT);
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
