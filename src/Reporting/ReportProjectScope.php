<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;

/**
 * What the captured project paths and discovered PHP showed about this run's
 * scope, as the supported formats publish it.
 *
 * Some channels cannot judge absent code on a partial or uncertain run. The
 * console says so on stderr, which neither `-q` nor a machine format keeps;
 * without this a CI pipeline could not tell "no stale configuration" from
 * "not judged on this run".
 *
 * Four states describe the measured path denominator: `covered` has no known
 * PHP outside the selected paths, `narrowed` has observed PHP outside them,
 * `unknown` cannot establish that denominator, and `unmeasured` has an
 * incomplete source universe over a subset. State alone does not say which
 * claims were judged. Namespace absence (Q1) closes for missing paths,
 * unknown universe, generated files or excluded PHP; exclude selectors (Q2)
 * close only for missing paths or unknown universe. Excluded and generated
 * code cannot by themselves close Q2.
 *
 * **A run can judge one configured value while withholding another in the
 * same channel.** A literal path under removed code or a namespace with no
 * usable PSR-4 location can remain unjudged while a neighbouring value is
 * judged. `unjudgedValues` names each skipped channel, option and pattern;
 * `unjudgedChannels` names a channel only when no value in it was judged.
 * This applies even when the final state is `narrowed` or `unknown`.
 *
 * A structured format publishes it where it says something about the report
 * itself: a document under a key of its own, in every state; `sarif` as a
 * notification, `github` as a notice, `html` as a banner and a human format as
 * a line, whenever {@see describe()} has something to say — every state but a
 * `covered` run that skipped no value and has no source reason.
 * `gitlab` and `checkstyle` omit it:
 * their consumers count every entry as a finding, and a narrowed run is a
 * choice of the caller's, not a defect of the run to be counted.
 */
final readonly class ReportProjectScope
{
    /** Check name / descriptor suffix the list formats publish the entry under. */
    public const string CHECK = 'run.project-scope';

    public const string COVERED = 'covered';
    public const string NARROWED = 'narrowed';
    public const string UNKNOWN = 'unknown';
    public const string UNMEASURED = 'unmeasured';

    /**
     * @param list<string> $uncoveredAutoloadTargets
     * @param list<string> $unjudgedChannels
     * @param list<ProjectScopeReason> $reasons
     * @param list<array{channel: string, option: string, pattern: string}> $unjudgedValues
     */
    private function __construct(
        public string $state,
        public array $uncoveredAutoloadTargets,
        public array $unjudgedChannels,
        public array $unjudgedValues = [],
        public array $reasons = [],
    ) {}

    public static function covered(): self
    {
        return new self(self::COVERED, [], []);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN, [], []);
    }

    /**
     * @param list<array{channel: string, option: string, pattern: string}> $unjudgedValues
     * @param list<string> $judgedValueChannels
     */
    public static function measured(ProjectScopeMeasurement $measurement, array $unjudgedValues, array $judgedValueChannels): self
    {
        $judgement = $measurement->judgement();
        $withheld = [];
        if (!$judgement->judgesNamespaceClaims()) {
            $withheld = ProjectScopeChannels::NAMESPACE_CLAIM_CHANNELS;
        }
        foreach ($unjudgedValues as $value) {
            if (!\in_array($value['channel'], $judgedValueChannels, true)) {
                $withheld[] = $value['channel'];
            }
        }
        if (!$judgement->judgesExcludeSelectors()) {
            foreach ([...ProjectScopeChannels::VALUE_CHANNELS, ProjectScopeChannels::WALK_CHANNEL] as $channel) {
                if (!\in_array($channel, $judgedValueChannels, true)) {
                    $withheld[] = $channel;
                }
            }
        }
        $withheld = array_values(array_unique($withheld));
        sort($withheld);

        return new self(
            $measurement->state()->value,
            $measurement->uncoveredRoots,
            $withheld,
            $unjudgedValues,
            $measurement->reasons(),
        );
    }

    /**
     * @param list<string> $uncoveredAutoloadTargets the declared targets no analysed path contains
     * @param list<string> $unjudgedChannels the channels that speak only on a whole-project run
     */
    public static function narrowed(array $uncoveredAutoloadTargets, array $unjudgedChannels): self
    {
        return new self(self::NARROWED, $uncoveredAutoloadTargets, $unjudgedChannels);
    }

    /** @param list<string> $unjudgedChannels */
    public static function unmeasured(array $unjudgedChannels): self
    {
        return new self(self::UNMEASURED, [], $unjudgedChannels);
    }

    /** @param list<ProjectScopeReason> $reasons */
    public function withReasons(array $reasons): self
    {
        $unique = [];
        foreach ([...$this->reasons, ...$reasons] as $reason) {
            $unique[serialize($reason->toArray())] = $reason;
        }

        return new self($this->state, $this->uncoveredAutoloadTargets, $this->unjudgedChannels, $this->unjudgedValues, array_values($unique));
    }

    /**
     * Legacy manual projection of skipped values, with no separate list of
     * judged channels from which to derive partial-channel coverage.
     *
     * Refused on `narrowed` and `unmeasured` because this helper has no
     * evidence about which values those runs judged. Final measured runs use
     * {@see measured()} with that evidence instead.
     *
     * @param list<array{channel: string, option: string, pattern: string}> $values
     */
    public function withUnjudgedValues(array $values): self
    {
        if ($this->state === self::NARROWED || $this->state === self::UNMEASURED) {
            throw new LogicException('A narrowed run judges no configured value, so it has none to skip.');
        }

        $channels = array_values(array_unique(array_column($values, 'channel')));
        sort($channels);

        return new self(
            $this->state,
            $this->uncoveredAutoloadTargets,
            $channels,
            $values,
            $this->reasons,
        );
    }

    /**
     * One shape in every state, so a consumer reads the same keys whether the
     * run was narrowed or not.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'uncoveredAutoloadTargets' => $this->uncoveredAutoloadTargets,
            'unjudgedChannels' => $this->unjudgedChannels,
            'unjudgedValues' => $this->unjudgedValues,
            'reasons' => array_map(static fn(ProjectScopeReason $reason): array => $reason->toArray(), $this->reasons),
        ];
    }

    /**
     * The sentence every non-document format uses; `null` only for a
     * `covered` run that skipped no value and has no source reason.
     */
    public function describe(): ?string
    {
        $description = match ($this->state) {
            self::UNMEASURED => 'Project scope unmeasured: the selected autoload universe is incomplete or undeclared; channels with no judged value: ' . implode(', ', $this->unjudgedChannels) . '.' . $this->describeUnjudgedValues(),
            self::NARROWED => \sprintf(
                'Project scope narrowed: the analysed paths do not cover %s; channels with no judged value: %s.%s',
                implode(', ', $this->uncoveredAutoloadTargets),
                implode(', ', $this->unjudgedChannels),
                $this->describeUnjudgedValues(),
            ),
            self::UNKNOWN => 'Project scope unknown: the project universe cannot be established completely.'
                . ($this->unjudgedChannels === [] ? '' : ' Channels with no judged value: ' . implode(', ', $this->unjudgedChannels) . '.')
                . $this->describeUnjudgedValues(),
            default => $this->unjudgedChannels === [] && $this->unjudgedValues === []
                ? null
                : 'Project scope covered: the analysed paths cover every autoload target.'
                    . ($this->unjudgedChannels === [] ? '' : ' Channels with no judged value: ' . implode(', ', $this->unjudgedChannels) . '.')
                    . $this->describeUnjudgedValues(),
        };
        if ($this->reasons !== []) {
            $description = ($description ?? 'Project scope covered.') . ' Source reasons: ' . implode('; ', array_map(static fn(ProjectScopeReason $reason): string => (string) json_encode($reason->toArray(), \JSON_UNESCAPED_SLASHES), $this->reasons)) . '.';
        }

        return $description;
    }

    private function describeUnjudgedValues(): string
    {
        if ($this->unjudgedValues === []) {
            return '';
        }

        return ' Values not judged: ' . implode(', ', array_map(
            static fn(array $value): string => \sprintf('%s %s "%s"', $value['channel'], $value['option'], $value['pattern']),
            $this->unjudgedValues,
        )) . '.';
    }
}
