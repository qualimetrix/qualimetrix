<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelRefusalWording;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * An impossible `channel:level` pair is refused in exactly one place.
 *
 * Two families of seams have to refuse it — configuration and CLI, which end
 * the run before analysis starts, and the inline directives, which report
 * `annotation.unresolved-directive`. Two seams answering separately is two
 * answers to one mistake, so the check is a topology one: "the same answer"
 * cannot be asserted from two green examples, because a second implementation
 * agreeing today is what drift starts as.
 *
 * **What the earlier form of this guard could not see.** It required a second
 * refusal to *ask the question* — the text `->levelsOf(` — and three seams that
 * ruled on a pair themselves never asked it: a threshold decided on the
 * presence of `:`, the namespace-exclusion option on a hardcoded
 * `SymbolLevel::Namespace_`, and the staleness accounting by dropping the level
 * altogether. A guard that looks for a trace cannot catch a seam that leaves
 * none. The stricter-sounding predicate "a refusal must reach the pair
 * grammar" is worse, not better: the condemned threshold seam called that
 * grammar, so the guard would have been green on the very shape it was
 * introduced for.
 *
 * So there are two independent detectors, each mechanical, and a seam that
 * decides a pair on its own has to trip one of them: either it reads what
 * levels a channel declares (detector one), or it says the word "level" while
 * reading the pair grammar (detector two). Saying nothing and reading nothing
 * leaves no way to rule on a level at all.
 *
 * - **Detector one — reading declared levels.** Three text forms reach that
 *   fact and no fourth does: `->levelsOf()`, `declarationFor()` together with
 *   `->levels`, and `ChannelDeclarationReader::read()`. Every production file
 *   using one stands in {@see LEVEL_READERS} with the reason it may, and the
 *   list is checked against the code in *both* directions — an unlisted reader
 *   is a second refusal being written, and a listed file that no longer reads
 *   is a list starting to rot.
 * - **Detector two — authorship of the refusal text.** A sentence naming a
 *   level while the file reads the pair grammar is built inside the seam:
 *   {@see ChannelLevelRefusalWording} is where those words live. Every other
 *   file that writes such prose stands in {@see LEVEL_WORDING_AUTHORS} with the
 *   reason its sentence is about something other than an authored pair.
 *
 * Both lists enumerate a set a grep enumerates *whole* — a fixed number of text
 * forms — and not the set "who accepts selector text", which nothing
 * enumerates. That is the difference from the pinned list the plan warned
 * about.
 *
 * **The named blind spot of both detectors**: reflection and a method name
 * built from a string are invisible to text. Sweeping `src/` for `->{$`,
 * `call_user_func`, `->$method(` and `ReflectionMethod` yields exactly one hit,
 * `ChannelDeclarationReader` itself, which reflects over a *rule's* declarations
 * — its documented mechanism, not a fourth way to read the universe. Detector
 * two is additionally blind to a seam that names a level while touching neither
 * the selector grammar nor the level separator constant; such a seam has no way
 * to find the `:` it would be ruling on, which is what makes the pairing of the
 * two conditions the recogniser rather than either alone.
 *
 * Each detector is held against its own subject twice: the legitimate anchor
 * must be found, or an empty offender list would prove nothing, and a synthetic
 * silent decider must be flagged, or the recogniser would be green on the shape
 * it exists to catch.
 */
#[CoversClass(ChannelLevelAddressing::class)]
#[CoversClass(ChannelLevelRefusalWording::class)]
final class ChannelLevelRefusalTopologyTest extends TestCase
{
    /**
     * Every production file that reads which levels a channel declares, and why
     * that is not a second refusal.
     *
     * @var array<string, string>
     */
    private const array LEVEL_READERS = [
        'src/Analysis/Finding/Contract/Rule/ChannelLevelAddressing.php' =>
            'the seam itself: the one place that judges an authored channel:level pair',
        'src/Infrastructure/Rule/ChannelUniverse.php' =>
            'the adapter that answers the question rather than asking it: levelsOf() reads the declaration'
            . ' registry it holds',
        'src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php' =>
            'builds the registry: it reads every rule class to compose the universe, and no user text reaches it',
        'src/Analysis/Policy/Inline/Directive/DirectiveUsage.php' =>
            'reads a declaration for one property that is not a level — whether the channel belongs to a'
            . ' configuration validator, which no annotation can suppress — and delegates every judgement'
            . ' about an authored channel:level pair to ChannelLevelAddressing, which is why it holds one',
        'src/Infrastructure/Console/Command/BaselineConfiguredThresholds.php' =>
            'enumerates the configured warning boundary of each channel at each level it declares, for'
            . ' baseline:explain; it judges no authored text and refuses nothing',
        'src/Analysis/Finding/Contract/Rule/AbstractRule.php' =>
            'a rule reading its own declarations to say which of its levels this configuration let run'
            . ' (levelActivity()); the levels come from the rule itself, no authored text is involved,'
            . ' and nothing here refuses anything',
        'src/Analysis/Finding/RuleExecution.php' =>
            'completes that same snapshot for channels a producer owns but does not declare itself —'
            . ' its configuration validator\'s — by reading the registry, not any authored pair',
    ];

    /**
     * Every production file outside the seam that writes prose naming a level
     * while reading the pair grammar, and why its sentence is not a refusal of
     * an authored pair.
     *
     * @var array<string, string>
     */
    private const array LEVEL_WORDING_AUTHORS = [
        'src/Analysis/Finding/Contract/Rule/ChannelLevelRefusalWording.php' =>
            'the seam itself: every sentence a refusal of an authored pair is made of',
        'src/Infrastructure/Console/ChannelExclusionKeyValidator.php' =>
            'the level `exclude_namespace_channels` applies at is a property of that option\'s own runtime, not a'
            . ' question about the channel universe; the seam has already judged the pair by then',
        'src/Infrastructure/Console/RuleInputValidator.php' =>
            '" at that level" is appended to a "matches nothing registered" refusal, after the seam accepted the'
            . ' pair: it says where the miss was, not that the pair is impossible',
        'src/Analysis/Finding/Contract/FindingChannel.php' =>
            'the channel name authority refusing a code with a level inside it: a statement about one malformed'
            . ' code, not about a pair addressed at the universe',
        'src/Analysis/Evidence/ComputedMetrics/ComputedMetricOverrideReader.php' =>
            'refuses a computed metric name whose last segment is a level word, at the moment the name is read'
            . ' from configuration: there is no channel and no pair yet',
    ];

    /** Detector one has to find the seam, or its scan has stopped recognising anything. */
    private const string LEVEL_READING_ANCHOR = 'src/Analysis/Finding/Contract/Rule/ChannelLevelAddressing.php';

    /** Detector two has to find the wording half of the seam, for the same reason. */
    private const string LEVEL_WORDING_ANCHOR =
        'src/Analysis/Finding/Contract/Rule/ChannelLevelRefusalWording.php';

    #[Test]
    public function everyProductionFileReadingDeclaredLevelsIsPinnedWithAReason(): void
    {
        $found = self::scan(self::readsDeclaredLevels());

        self::assertContains(
            self::LEVEL_READING_ANCHOR,
            $found,
            'The seam itself was not found reading declared levels, so an empty offender list proves nothing.',
        );
        self::assertSame(
            [],
            array_values(array_diff($found, array_keys(self::LEVEL_READERS))),
            'A file reads which levels a channel declares without standing in LEVEL_READERS. Either it is a'
            . ' second refusal for an impossible channel:level pair — ask ChannelLevelAddressing instead — or it'
            . ' is a legitimate reader, in which case pin it with the reason it is not a refusal.',
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::LEVEL_READERS), $found)),
            'A file pinned in LEVEL_READERS no longer reads declared levels. A list nobody checks backwards is a'
            . ' list that rots: drop the row.',
        );
    }

    #[Test]
    public function everyProductionFileWordingALevelRefusalIsPinnedWithAReason(): void
    {
        $found = self::scan(self::wordsALevel());

        self::assertContains(
            self::LEVEL_WORDING_ANCHOR,
            $found,
            'The wording half of the seam was not found, so an empty offender list proves nothing.',
        );
        self::assertSame(
            [],
            array_values(array_diff($found, array_keys(self::LEVEL_WORDING_AUTHORS))),
            'A file names a level in prose while reading the pair grammar, without standing in'
            . ' LEVEL_WORDING_AUTHORS. A seam that rules on a pair silently still has to say so in words: route'
            . ' the sentence through ChannelLevelRefusalWording, or pin the file with the reason its sentence is'
            . ' about something else.',
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::LEVEL_WORDING_AUTHORS), $found)),
            'A file pinned in LEVEL_WORDING_AUTHORS no longer words a level. Drop the row rather than keeping a'
            . ' reason for something that is not there.',
        );
    }

    /**
     * A seam that reads declared levels to rule on a pair of its own is caught
     * whichever of the three text forms it uses.
     */
    #[Test]
    public function detectorOneFlagsASilentDeciderReadingDeclaredLevels(): void
    {
        foreach (self::silentDecidersReadingLevels() as $shape => $source) {
            self::assertTrue(
                (self::readsDeclaredLevels())($source),
                \sprintf('Detector one is blind to a silent decider written as %s.', $shape),
            );
        }
    }

    /**
     * And a seam that words its own refusal about a level, without asking
     * anything, is caught by the other detector.
     */
    #[Test]
    public function detectorTwoFlagsASilentDeciderWordingItsOwnLevelRefusal(): void
    {
        self::assertTrue(
            (self::wordsALevel())(self::silentDeciderWordingALevel()),
            'Detector two is blind to a seam that refuses a level of its own accord.',
        );
    }

    /**
     * Prose about a level that never touches the pair grammar is not this
     * guard's subject: a rule description or a log-level option would otherwise
     * have to be pinned, and every such row weakens the two lists.
     */
    #[Test]
    public function detectorTwoIgnoresProseAboutLevelsOutsideThePairGrammar(): void
    {
        $source = <<<'PHP'
            <?php
            final class Unrelated
            {
                public function describe(): string
                {
                    return 'Checks cyclomatic complexity at method and class levels';
                }
            }
            PHP;

        self::assertFalse((self::wordsALevel())($source));
    }

    /**
     * @return array<string, string> shape => source of a seam ruling on a pair without asking the seam
     */
    private static function silentDecidersReadingLevels(): array
    {
        return [
            'a call to levelsOf()' => <<<'PHP'
                <?php
                final class RogueThreshold
                {
                    public function refuse(string $channel, string $level): void
                    {
                        if (!\in_array($level, $this->universe->levelsOf($channel), true)) {
                            throw new InvalidArgumentException('nope');
                        }
                    }
                }
                PHP,
            'a declaration lookup plus its levels' => <<<'PHP'
                <?php
                final class RogueExclusion
                {
                    public function refuse(FindingChannel $channel, SymbolLevel $level): void
                    {
                        $declaration = $this->declarations->declarationFor($channel);

                        if (!\in_array($level, $declaration->levels, true)) {
                            throw new InvalidArgumentException('nope');
                        }
                    }
                }
                PHP,
            'a reader call straight off the rule class' => <<<'PHP'
                <?php
                final class RogueStaleness
                {
                    public function refuse(string $ruleClass): void
                    {
                        foreach (ChannelDeclarationReader::read($ruleClass) as $declaration) {
                            throw new InvalidArgumentException('nope');
                        }
                    }
                }
                PHP,
        ];
    }

    private static function silentDeciderWordingALevel(): string
    {
        return <<<'PHP'
            <?php
            final class RogueSeam
            {
                public function refuse(string $selector): void
                {
                    if (ChannelLevelSelector::carriesLevelSeparator($selector)) {
                        throw new InvalidArgumentException('This option does not accept a level here.');
                    }
                }
            }
            PHP;
    }

    /**
     * The three text forms that reach which levels a channel declares.
     *
     * @return callable(string): bool
     */
    private static function readsDeclaredLevels(): callable
    {
        return static fn(string $source): bool => str_contains($source, '->levelsOf(')
            || str_contains($source, 'ChannelDeclarationReader::read(')
            || (str_contains($source, 'declarationFor(') && str_contains($source, '->levels'));
    }

    /**
     * Prose naming a level, in a file that reads the grammar of the pair a
     * level belongs to.
     *
     * String literals are taken from the token stream rather than from the raw
     * text, so a docblock explaining the seam is not mistaken for a sentence
     * the seam speaks — and the same reading makes a `{@see ChannelLevelSelector}`
     * reference not count as touching the grammar.
     *
     * @return callable(string): bool
     */
    private static function wordsALevel(): callable
    {
        return static function (string $source): bool {
            $prose = false;
            $grammar = false;

            foreach (token_get_all($source) as $token) {
                if (!\is_array($token)) {
                    continue;
                }

                if ($token[0] === \T_CONSTANT_ENCAPSED_STRING || $token[0] === \T_ENCAPSED_AND_WHITESPACE) {
                    // A space is what separates a sentence from an identifier,
                    // a config key or a service id spelled with a level word.
                    $prose = $prose
                        || (str_contains($token[1], ' ') && preg_match('/\blevels?\b/i', $token[1]) === 1);

                    continue;
                }

                if (\in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    $grammar = $grammar
                        || preg_match('/\b(ChannelLevelSelector|ChannelLevelAddressing|LEVEL_SEPARATOR)\b/', $token[1]) === 1;
                }
            }

            return $prose && $grammar;
        };
    }

    /**
     * @param callable(string): bool $detector
     *
     * @return list<string>
     */
    private static function scan(callable $detector): array
    {
        $root = \dirname(__DIR__, 4);
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src' . substr($entry->getPathname(), \strlen($root . '/src'));
            $contents = file_get_contents($entry->getPathname());

            if ($contents === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $relative));
            }

            if ($detector($contents)) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }
}
