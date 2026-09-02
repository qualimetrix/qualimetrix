<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\DirectiveAuditPresenter;

/**
 * Both projections tally every verdict the vocabulary defines.
 *
 * The counts are built over `DirectiveEffect::cases()` while the two summaries
 * used to name four keys by hand, so a fifth case would have been counted and
 * printed nowhere — silently, in both projections at once. Neither assertion
 * below names the four values: a test that listed them would grow the same
 * hand-written list it is guarding against.
 */
final class DirectiveAuditSummaryProjectionTest extends TestCase
{
    #[Test]
    public function itPublishesOneSummaryKeyPerVerdictTheVocabularyDefines(): void
    {
        /** @var array{summary: array<string, int>} $decoded */
        $decoded = json_decode(self::presenterOverEveryVerdict()->json(0), true, 512, \JSON_THROW_ON_ERROR);

        $expected = ['total' => \count(DirectiveEffect::cases())];
        foreach (DirectiveEffect::cases() as $effect) {
            $expected[$effect->value] = 1;
        }

        self::assertSame($expected, $decoded['summary']);
    }

    #[Test]
    public function itPrintsOneTallyPerVerdictTheVocabularyDefinesInTheTextSummary(): void
    {
        $text = self::presenterOverEveryVerdict()->text();
        $summary = array_values(array_filter(
            explode("\n", $text),
            static fn(string $line): bool => str_contains($line, 'directive(s):'),
        ));

        self::assertCount(1, $summary, 'The text projection prints exactly one summary line.');

        [$header, $tallies] = explode(':', $summary[0], 2);

        self::assertSame(\sprintf('  %d directive(s)', \count(DirectiveEffect::cases())), $header);

        $tallied = self::tallies($tallies);

        self::assertSame(
            array_fill(0, \count(DirectiveEffect::cases()), '1'),
            array_column($tallied, 0),
            'Every verdict of the vocabulary is tallied, one occurrence each.',
        );

        $names = array_column($tallied, 1);

        self::assertSame(
            \count(DirectiveEffect::cases()),
            \count(array_unique($names)),
            'Each verdict is tallied under a name of its own: two cases printed under one word are one'
                . ' of them going unreported.',
        );
    }

    /** One verdict per case, so a projection that forgets one prints a shorter list than the vocabulary. */
    private static function presenterOverEveryVerdict(): DirectiveAuditPresenter
    {
        $verdicts = [];
        $line = 0;

        foreach (DirectiveEffect::cases() as $effect) {
            $verdicts[] = new DirectiveVerdict(
                new DirectiveSite(RelativePath::fromString('src/Example.php'), ++$line, 'threshold', 'rule.name'),
                $effect,
            );
        }

        return new DirectiveAuditPresenter(
            new DirectiveAuditReport($verdicts, new AnalysisCoverage([], [], []), 0),
            [],
            [],
        );
    }

    /**
     * A `1 effective, 1 applied-boundary-only, …` tail, as count/name pairs.
     *
     * The names are read out rather than assumed: a projection that printed the
     * right number of tallies under one repeated word would satisfy a check
     * that only counted numbers, and one of the verdicts would be unreported.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function tallies(string $tallies): array
    {
        preg_match_all('/(\d+) ([a-z-]+)/', $tallies, $matches, \PREG_SET_ORDER);

        return array_map(
            static fn(array $match): array => [$match[1], $match[2]],
            $matches,
        );
    }
}
