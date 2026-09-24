<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Refusal\MachineReadableFormats;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * `website/docs/llms.txt` is the map an agent reads instead of exploring the
 * CLI, so an element it drops is invisible rather than merely undocumented.
 * This keeps five closed sets — commands, presets, `qmx.yaml` root keys,
 * output formats and inline `@qmx-*` directives — named in the index by
 * checking every element against the live surface, never the other way
 * around: the index is never treated as a source of truth here.
 *
 * Presets, root keys, output formats and directives are each written in
 * `llms.txt` as an individually backtick-quoted token (`` `strict` ``,
 * `` `excludeHealth` ``, `` `@qmx-ignore` ``). Matching therefore requires the
 * exact backtick-delimited spelling, not a bare substring or a generic word
 * boundary: a boundary alone still lets `` `text` `` match inside
 * `` `health` `` 's neighbouring prose ("(text table)") or `` `ci` `` match
 * inside the trailing `` `--preset=strict,ci` `` example, silently passing a
 * real removal of the standalone token. The one exception is the
 * fenced "Commands:" block, which writes bare shell invocations
 * (`vendor/bin/qmx check <paths>`) rather than backtick spans; there a
 * command name is matched only right after `qmx ` and before whitespace, so
 * one command name cannot match as a substring of another shell token. Each
 * set is also compared only against the specific line (or fenced block) of
 * `llms.txt` that enumerates it, not the whole file, so a generic word —
 * `coupling` and `architecture` are both a root key and a rule-group name —
 * cannot hide a real removal behind an unrelated mention elsewhere in the
 * document.
 *
 * Root keys come from {@see ConfigSchema::allowedRootKeys()} specifically
 * (never `ConfigSchema::ENTRIES` alone): that method is the one place the
 * schema-derived keys and the free-standing `ConfigSchema::DOCUMENT_ROOTS`
 * keys are already unioned and de-duplicated, and re-deriving that union
 * here would let this test drift from the schema's own definition of
 * "allowed root key".
 *
 * Output formats come from {@see MachineReadableFormats::knownFormats()}
 * rather than {@see \Qualimetrix\Reporting\Formatter\FormatterRegistryInterface::getAvailableNames()}:
 * the latter deliberately hides the deprecated-but-selectable `text-verbose`
 * formatter from listings, while `llms.txt` documents it. `knownFormats()` is
 * itself asserted equal to "available names plus `text-verbose`" by
 * `ConsoleComposition\MachineReadableFormatsRegistryTest`, so it is the
 * closed, complete set of registered formats.
 *
 * Inline directives have no array-returning registry to call — their names
 * live only inside the two extractors' regular expressions. So this test
 * derives the set functionally from a hand-listed set of the four candidate
 * tags: it feeds each one through the real {@see SuppressionExtractor} or
 * {@see ThresholdOverrideExtractor} and keeps only the names those extractors
 * actually recognised. A tag whose pattern broke would silently disappear
 * from the derived set rather than fail loudly — the same narrowed promise
 * described below for presets, formats and directives generally — and a
 * fifth directive added to either extractor without also being added to this
 * test's candidate list would be invisible to it.
 *
 * **What this test cannot promise.** For commands, the filesystem sweep
 * below can come back empty (a moved directory, a broken glob) with nothing
 * else to notice — so {@see self::itRefusesWhenCommandSweepDisagreesWithTheBinaryMap()}
 * cross-checks its cardinality against an independent second source: the
 * number of `'name' => XxxCommand::class` entries in `bin/qmx`'s command-loader
 * map. `bin/qmx` ends in `exit()` and cannot be included, so that count comes
 * from a regex over its text — deliberately used only as a *count*, never as
 * the *enumeration*, which is exactly the thing
 * `ConsoleComposition\CommandRegistrationTest`'s docblock forbids ("an
 * enumeration taken from the map could only ever confirm the map against
 * itself"). A count is not an enumeration: here the map confirms a source
 * other than itself.
 *
 * Presets, root keys, formats and directives have no second, independent
 * source anywhere in this tree — extraction and cardinality both come from
 * the one registry call (or, for directives, the one pair of extractor
 * calls). So this test promises less for them: it refuses an empty or
 * single-element set (a bare sanity floor) and it refuses any element of
 * that set missing from `llms.txt`. It does **not** promise to catch a
 * registry that silently lost one member while keeping the rest — that
 * omission is `ConfigSchemaEntryClosureTest`'s job and the registries' own
 * tests, not this guard's. Claiming a witness this test does not have would
 * be the same defect this repository keeps finding in its own controls, one
 * level up.
 */
final class LlmsIndexRegisteredSurfaceTest extends TestCase
{
    private const string COMMAND_DIRECTORY = __DIR__ . '/../../src/Infrastructure/Console/Command';

    private const string BINARY_PATH = __DIR__ . '/../../bin/qmx';

    private const string LLMS_TXT_PATH = __DIR__ . '/../../website/docs/llms.txt';

    #[Test]
    public function itNamesEveryRegisteredCommand(): void
    {
        $content = self::llmsTxt();
        $block = self::commandsBlock($content);

        foreach (self::commandNames() as $name) {
            self::assertTrue(
                self::commandsBlockHasCommand($block, $name),
                \sprintf('llms.txt\'s "Commands:" block does not name the "%s" command.', $name),
            );
        }
    }

    #[Test]
    public function itNamesEveryPresetFormatAndDirective(): void
    {
        $content = self::llmsTxt();

        $presets = self::presetNames();
        self::assertGreaterThan(1, \count($presets), 'PresetResolver::getAvailableNames() returned an empty or single-element set.');
        $presetsLine = self::afterLabel($content, 'Presets:');
        foreach ($presets as $name) {
            self::assertTrue(
                self::containsBacktickToken($presetsLine, $name),
                \sprintf('llms.txt\'s "Presets:" line does not name the "%s" preset.', $name),
            );
        }

        $formats = self::formatNames();
        self::assertGreaterThan(1, \count($formats), 'MachineReadableFormats::knownFormats() returned an empty or single-element set.');
        $formatsLine = self::afterLabel($content, 'Output formats:');
        foreach ($formats as $name) {
            self::assertTrue(
                self::containsBacktickToken($formatsLine, $name),
                \sprintf('llms.txt\'s "Output formats:" line does not name the "%s" format.', $name),
            );
        }

        $directives = self::directiveNames();
        self::assertGreaterThan(1, \count($directives), 'The @qmx-* directive extractors recognised an empty or single-element set.');
        $suppressionLine = self::afterLabel($content, 'Suppression:');
        foreach ($directives as $name) {
            self::assertTrue(
                self::containsBacktickToken($suppressionLine, $name),
                \sprintf('llms.txt\'s "Suppression:" line does not name the "%s" directive.', $name),
            );
        }
    }

    #[Test]
    public function itNamesEveryConfigurationRootKey(): void
    {
        $content = self::llmsTxt();

        $keys = self::rootKeyNames();
        self::assertGreaterThan(1, \count($keys), 'ConfigSchema::allowedRootKeys() returned an empty or single-element set.');

        $rootKeysLine = self::afterLabel($content, 'Root keys:');
        foreach ($keys as $key) {
            self::assertTrue(
                self::containsBacktickToken($rootKeysLine, $key),
                \sprintf(
                    'llms.txt\'s "Root keys:" line does not name the "%s" qmx.yaml root key '
                    . '(spelled exactly as ConfigSchema::allowedRootKeys() returns it).',
                    $key,
                ),
            );
        }
    }

    /**
     * The command sweep below is a filesystem walk: it can come back empty
     * without raising an error of its own (a moved directory, a glob that
     * stopped matching). `bin/qmx`'s command-loader map is an independent
     * second source for the *count* — not the enumeration, per
     * `ConsoleComposition\CommandRegistrationTest`'s docblock — so a
     * disagreement here means the sweep (or the map) broke, not merely that
     * `llms.txt` fell behind.
     */
    #[Test]
    public function itRefusesWhenCommandSweepDisagreesWithTheBinaryMap(): void
    {
        $sweptCount = \count(self::commandNames());
        $mappedCount = self::commandMapCountInBinary();

        self::assertGreaterThan(0, $mappedCount, 'Found no "\'name\' => XxxCommand::class" entries in bin/qmx.');
        self::assertSame(
            $mappedCount,
            $sweptCount,
            \sprintf(
                'The #[AsCommand] filesystem sweep found %d command(s) but bin/qmx\'s command-loader map has %d '
                . 'entries. One of the two sources is broken.',
                $sweptCount,
                $mappedCount,
            ),
        );
    }

    /** @return list<string> */
    private static function commandNames(): array
    {
        $names = [];
        $directory = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::COMMAND_DIRECTORY, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($directory as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = self::classOf($file);
            if ($class === null || !is_subclass_of($class, Command::class)) {
                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);
            if ($attributes === []) {
                continue;
            }

            $names[] = $attributes[0]->newInstance()->name;
        }

        return $names;
    }

    /** @return ?class-string */
    private static function classOf(SplFileInfo $file): ?string
    {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        $class = $namespace[1] . '\\' . $file->getBasename('.php');

        return class_exists($class) ? $class : null;
    }

    private static function commandMapCountInBinary(): int
    {
        $binary = (string) file_get_contents(self::BINARY_PATH);
        $count = preg_match_all('/\'[\w:-]+\'\s*=>\s*[A-Za-z_][A-Za-z0-9_]*Command::class/', $binary);

        return $count === false ? 0 : $count;
    }

    /** @return list<string> */
    private static function presetNames(): array
    {
        return PresetResolver::getAvailableNames();
    }

    /** @return list<string> */
    private static function formatNames(): array
    {
        return MachineReadableFormats::knownFormats();
    }

    /** @return list<string> */
    private static function rootKeyNames(): array
    {
        return ConfigSchema::allowedRootKeys();
    }

    /**
     * Derives the recognised `@qmx-*` directive names by actually running
     * each candidate tag through the real extractor, rather than reading the
     * tag literals back out of the extractors' own regular expressions
     * (which would only confirm the pattern against itself). A tag whose
     * pattern silently broke would drop out of this list rather than fail
     * here — see the class docblock's "what this test cannot promise".
     *
     * @return list<string>
     */
    private static function directiveNames(): array
    {
        $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Probe.php')));
        $names = [];

        $suppressionExtractor = new SuppressionExtractor();
        foreach ([
            '@qmx-ignore' => ['@qmx-ignore complexity', SuppressionType::Symbol],
            '@qmx-ignore-next-line' => ['@qmx-ignore-next-line complexity', SuppressionType::NextLine],
            '@qmx-ignore-file' => ['@qmx-ignore-file complexity', SuppressionType::File],
        ] as $directive => [$tagLine, $expectedType]) {
            if (self::suppressionRecognises($suppressionExtractor, $tagLine, $expectedType, $subject)) {
                $names[] = $directive;
            }
        }

        $thresholdExtractor = new ThresholdOverrideExtractor();
        if (self::thresholdRecognises($thresholdExtractor, '@qmx-threshold complexity.ccn 15', $subject)) {
            $names[] = '@qmx-threshold';
        }

        return $names;
    }

    private static function suppressionRecognises(
        SuppressionExtractor $extractor,
        string $tagLine,
        SuppressionType $expectedType,
        MetricSubject $subject,
    ): bool {
        $node = self::classNodeWithDoc($tagLine);

        // `@qmx-threshold` is the other reader's family; answering that it carried
        // every such tag keeps this probe about the suppression tags alone.
        foreach ($extractor->extract($node, $subject, ControlScope::Callable, static fn(): bool => true) as $suppression) {
            if ($suppression->type === $expectedType) {
                return true;
            }
        }

        return false;
    }

    private static function thresholdRecognises(ThresholdOverrideExtractor $extractor, string $tagLine, MetricSubject $subject): bool
    {
        $node = self::classNodeWithDoc($tagLine);

        return $extractor->extract($node, $subject, ControlScope::Callable) !== [];
    }

    private static function classNodeWithDoc(string $tagLine): Class_
    {
        $doc = new Doc(\sprintf("/**\n * %s\n */", $tagLine));
        $node = new Class_('Probe');
        $node->setDocComment($doc);

        return $node;
    }

    private static function llmsTxt(): string
    {
        $content = file_get_contents(self::LLMS_TXT_PATH);
        self::assertNotFalse($content, 'Could not read website/docs/llms.txt.');

        return $content;
    }

    private static function commandsBlock(string $content): string
    {
        if (preg_match('/Commands:\s*```\n(.*?)```/s', $content, $match) !== 1) {
            self::fail('llms.txt has no fenced "Commands:" code block to check command names against.');
        }

        return $match[1];
    }

    /**
     * Returns the text from a label to the end of its physical line,
     * regardless of whether the label starts that line (e.g. "Root keys:"
     * sits mid-line, after "Configuration:"). Scoping to one line keeps a
     * generic word used in two lists — `coupling` and `architecture` are
     * both a root key and a rule-group name — from letting a real removal in
     * one line hide behind an unrelated mention in another.
     */
    private static function afterLabel(string $content, string $label): string
    {
        $position = strpos($content, $label);
        if ($position === false) {
            self::fail(\sprintf('llms.txt has no "%s" label.', $label));
        }

        $lineEnd = strpos($content, "\n", $position);
        $lineEnd = $lineEnd === false ? \strlen($content) : $lineEnd;

        return substr($content, $position, $lineEnd - $position);
    }

    /**
     * True when `llms.txt` spells this token as its own backtick-quoted span
     * (`` `token` ``), matching the file's own convention for presets, root
     * keys, output formats and directives. A generic word-boundary match is
     * not enough here: `` `text` `` would falsely match inside
     * `` `text-verbose` `` 's neighbouring "(text table)" prose, and `` `ci` ``
     * would falsely match inside the trailing `` `--preset=strict,ci` ``
     * example — both would let a real removal of the standalone token pass.
     */
    private static function containsBacktickToken(string $haystack, string $token): bool
    {
        return str_contains($haystack, '`' . $token . '`');
    }

    /**
     * True when the fenced "Commands:" block invokes this command name right
     * after `qmx `, followed by whitespace or the end of that line. The block
     * writes bare shell invocations (`vendor/bin/qmx check <paths>`), not
     * backtick spans, so this anchors on shell position instead: the trailing
     * lookahead refuses a longer token that merely starts with the name
     * (`check` cannot match inside a hypothetical `check-all`), so no
     * backtick delimiter is needed here regardless of how many commands
     * exist or what they are named.
     */
    private static function commandsBlockHasCommand(string $block, string $command): bool
    {
        $pattern = '/(?<=qmx )' . preg_quote($command, '/') . '(?=\s|$)/m';

        return preg_match($pattern, $block) === 1;
    }
}
