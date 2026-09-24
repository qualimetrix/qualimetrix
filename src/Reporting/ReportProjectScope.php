<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

/**
 * What the run's paths were, measured against the project's `composer.json`
 * autoload, as every format publishes it.
 *
 * Some channels speak only on a run that covers the whole project — a layer
 * that matched nothing, an `exclude:` that removed nothing — and fall silent on
 * a run over a slice of it. The console says so on stderr, which neither `-q`
 * nor a machine format keeps; without this a CI pipeline could not tell "no
 * stale configuration" from "not judged on this run".
 *
 * Three states: `covered` (the paths reach every declared autoload target),
 * `narrowed` (they miss some; the channels judged only on a whole-project run
 * were not judged) and `unknown` (the manifest declares no readable production
 * autoload, so the analysed paths were taken as the whole project and those
 * channels judged them — except the namespace values of the channels listed,
 * which nothing locates without a declared autoload).
 *
 * A structured format publishes it where it says something about the report
 * itself: a document under a key of its own, in every state; `sarif` as a
 * notification, `github` as a notice, `html` as a banner and a human format as
 * a line, when the state is not `covered`. `gitlab` and `checkstyle` omit it:
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

    /**
     * @param list<string> $uncoveredAutoloadTargets
     * @param list<string> $unjudgedChannels
     */
    private function __construct(
        public string $state,
        public array $uncoveredAutoloadTargets,
        public array $unjudgedChannels,
    ) {}

    public static function covered(): self
    {
        return new self(self::COVERED, [], []);
    }

    /**
     * @param list<string> $unjudgedChannels the channels whose namespace values were not judged
     */
    public static function unknown(array $unjudgedChannels): self
    {
        return new self(self::UNKNOWN, [], $unjudgedChannels);
    }

    /**
     * @param list<string> $uncoveredAutoloadTargets the declared targets no analysed path contains
     * @param list<string> $unjudgedChannels the channels that speak only on a whole-project run
     */
    public static function narrowed(array $uncoveredAutoloadTargets, array $unjudgedChannels): self
    {
        return new self(self::NARROWED, $uncoveredAutoloadTargets, $unjudgedChannels);
    }

    /**
     * One shape in every state, so a consumer reads the same keys whether the
     * run was narrowed or not.
     *
     * @return array{state: string, uncoveredAutoloadTargets: list<string>, unjudgedChannels: list<string>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'uncoveredAutoloadTargets' => $this->uncoveredAutoloadTargets,
            'unjudgedChannels' => $this->unjudgedChannels,
        ];
    }

    /**
     * The sentence every non-document format uses; `null` for `covered`, the
     * state that needs no explaining.
     */
    public function describe(): ?string
    {
        return match ($this->state) {
            self::NARROWED => \sprintf(
                'Project scope narrowed: the analysed paths do not cover autoload target(s) %s, so these channels,'
                . ' judged only on a whole-project run, were not judged: %s.',
                implode(', ', $this->uncoveredAutoloadTargets),
                implode(', ', $this->unjudgedChannels),
            ),
            self::UNKNOWN => 'Project scope unknown: composer.json declares no production autoload a walk of the project reaches,'
                . ' so the analysed paths were taken as the whole project and whole-project channels judged them.'
                . ($this->unjudgedChannels === [] ? '' : \sprintf(
                    ' Without a declared autoload a configured namespace has no location, so the namespace values of'
                    . ' these channels were not judged: %s.',
                    implode(', ', $this->unjudgedChannels),
                )),
            default => null,
        };
    }
}
