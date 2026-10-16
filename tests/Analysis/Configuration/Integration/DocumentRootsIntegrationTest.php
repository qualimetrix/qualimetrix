<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * The roots Configuration declares, judged by the document engine through the
 * real stages: one spelling rule at every depth, the form of every written
 * value in the layer that wrote it, and a refusal that names that layer.
 */
#[CoversClass(ConfigurationRoot::class)]
#[CoversClass(ConfigurationPipeline::class)]
final class DocumentRootsIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-roots-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function provideUnacceptedSpellings(): iterable
    {
        yield 'Title-case root' => ["Fail_On: error\n", 'Fail_On', 'fail_on', ['Fail_On']];
        yield 'upper-case root' => ["FAIL_ON: error\n", 'FAIL_ON', 'fail_on', ['FAIL_ON']];
        yield 'Title-case key of a section' => ["cache:\n  Enabled: false\n", 'Enabled', 'enabled', ['cache', 'Enabled']];
        yield 'upper-case key of a section' => ["parallel:\n  WORKERS: 2\n", 'WORKERS', 'workers', ['parallel', 'WORKERS']];
        yield 'Title-case key inside a list item' => ["exclude:\n  - Subtree: build\n", 'Subtree', 'subtree', ['exclude', '0', 'Subtree']];
    }

    /**
     * One spelling rule at every depth: a key in none of the three accepted
     * spellings is refused as written, with the canonical one offered, rather
     * than folded at the root into a name nobody wrote.
     *
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideUnacceptedSpellings')]
    public function itRefusesAnUnacceptedSpellingAtEveryDepthInTheAuthorsWords(string $yaml, string $written, string $canonical, array $path): void
    {
        $refusal = $this->refusal($yaml);

        self::assertStringStartsWith(\sprintf('Key "%s" in configuration file', Provenance::display($path)), $refusal->summary());
        self::assertStringContainsString(\sprintf('write "%s"', $canonical), $refusal->summary());
        self::assertSame($path, $refusal->position()?->segments);
        self::assertSame($written, $refusal->position()->written);
        self::assertSame([$canonical], $refusal->position()->accepted);
    }

    /**
     * A root's type refusal names the key as its author wrote it, and its
     * published path is a key the document holds, not the schema's spelling.
     */
    #[Test]
    #[DataProvider('provideTypeRefusalSpellings')]
    public function itRefusesARootsTypeInTheSpellingItsAuthorWrote(string $key): void
    {
        $refusal = $this->refusal(\sprintf("%s: 5\n", $key));

        self::assertSame(
            \sprintf('"%s" in configuration file "%s" must be boolean, got int.', $key, 'qmx.yaml'),
            $refusal->summary(),
        );
        self::assertSame([$key], $refusal->position()?->segments);
        self::assertSame($key, $refusal->position()->written);
        self::assertCount(1, $refusal->sources());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
    }

    /** @return iterable<string, array{string}> */
    public static function provideTypeRefusalSpellings(): iterable
    {
        yield 'camelCase' => ['includeAutoloadDev'];
        yield 'snake_case' => ['include_autoload_dev'];
        yield 'kebab-case' => ['include-autoload-dev'];
    }

    /**
     * Registered sections are judged in authored order, including their form
     * and spelling, and every refusal identifies its source.
     */
    #[Test]
    public function itJudgesRegisteredSectionsInAuthoredOrder(): void
    {
        $refusal = $this->refusal("rules: 5
Fail_On: error
");

        self::assertSame('"rules" in configuration file "qmx.yaml" must be a map, got int.', $refusal->summary());
        self::assertSame(['rules'], $refusal->position()?->segments);
        self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
        self::assertStringContainsString('write "fail_on"', $this->refusal("rules: {}\nFail_On: error\n")->summary());

        $folded = $this->refusal("rules: 5
fail_on: error
");
        self::assertSame('"rules" in configuration file "qmx.yaml" must be a map, got int.', $folded->summary());
    }

    /** An integer is a byte count to PHP, and `-1` is the documented "no limit". */
    #[Test]
    public function itAcceptsAnIntegerMemoryLimit(): void
    {
        $document = $this->resolve("memory_limit: -1\n");

        self::assertSame(-1, $document->resolved()->get(ConfigSchema::MEMORY_LIMIT)?->plain());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideNonStringListElements(): iterable
    {
        yield 'only_rules, an integer' => ["only_rules: [5]\n", '"only_rules[0]" in configuration file "%s" must be non-empty string, got int.'];
        yield 'only_rules, a boolean' => ["only_rules: [true]\n", '"only_rules[0]" in configuration file "%s" must be non-empty string, got bool.'];
        yield 'only_rules, a map' => ["only_rules: [{a: b}]\n", '"only_rules[0]" in configuration file "%s" must be non-empty string, got a map.'];
        yield 'only_rules, a null' => ["only_rules: [complexity.ccn, ~]\n", 'Item 1 of "only_rules" in configuration file "%s" is null (`~`)'];
        yield 'disabled_rules, an integer' => ["disabled_rules: [5]\n", '"disabled_rules[0]" in configuration file "%s" must be non-empty string, got int.'];
        yield 'paths, an unquoted year' => ["paths: [2024]\n", '"paths[0]" in configuration file "%s" must be string, got int.'];
        yield 'paths, a null' => ["paths: [~]\n", 'Item 0 of "paths" in configuration file "%s" is null (`~`)'];
        yield 'exclude, a null' => ["exclude: [~]\n", 'Item 0 of "exclude" in configuration file "%s" is null (`~`)'];
        yield 'suppress_paths, a null' => ["suppress_paths: [~]\n", 'Item 0 of "suppress_paths" in configuration file "%s" is null (`~`)'];
    }

    /**
     * A list element that is not a string is refused with its index, in the
     * layer that wrote it: filtered away, `only_rules: [5]` would run every
     * rule, and a null element is a form mistake, not a bare-string one.
     */
    #[Test]
    #[DataProvider('provideNonStringListElements')]
    public function itRefusesAListElementOfTheWrongFormWithItsIndex(string $yaml, string $expected): void
    {
        $refusal = $this->refusal($yaml);

        self::assertStringContainsString(\sprintf($expected, 'qmx.yaml'), $refusal->summary());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideLikelyIntents(): iterable
    {
        yield 'paths, an unquoted year' => ["paths: [2024]\n", 'got int. Quote a name that reads as a number or a keyword ("2024", "true").'];
        yield 'paths, an unquoted keyword' => ["paths: [true]\n", 'got bool. Quote a name that reads as a number or a keyword'];
        yield 'exclude, a bare string' => ["exclude: [src]\n", 'got string. A selector names its kind: {exact: value}, {subtree: value}, or {regex: value}.'];
        yield 'suppress_paths, a bare string' => ["suppress_paths: [src]\n", 'got string. A selector names its kind'];
        yield 'suppress_namespaces, a list' => ["suppress_namespaces: [[App]]\n", 'got a list. A selector names its kind'];
    }

    /**
     * The form alone does not say what the author meant, so the root's
     * declaration adds it to the refusal.
     */
    #[Test]
    #[DataProvider('provideLikelyIntents')]
    public function itTellsTheLikelyIntentBehindAMalformedRootValue(string $yaml, string $expected): void
    {
        self::assertStringContainsString($expected, $this->refusal($yaml)->summary());
    }

    #[Test]
    public function itKeepsTheIntentHintToTheFormItExplains(): void
    {
        self::assertStringNotContainsString('Quote', $this->refusal("paths: [~]\n")->summary());
        self::assertStringNotContainsString('Quote', $this->refusal("paths: src\n")->summary());
        self::assertStringNotContainsString('selector', $this->refusal("only_rules: [5]\n")->summary());
    }

    /**
     * Form is judged in every layer: a malformed value a later layer overrides
     * is still refused, and the refusal names the layer that wrote it.
     */
    #[Test]
    public function itRefusesAMalformedValueTheCommandLineOverridesAndNamesTheFile(): void
    {
        $refusal = $this->refusal("include_generated: \"yes\"\n", ['include_generated' => true], ['include_generated' => '--include-generated']);

        self::assertCount(1, $refusal->sources());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
        self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
    }

    /** The command line has no document positions: its values are named by the option that wrote them. */
    #[Test]
    public function itNamesTheOptionOfAMalformedCommandLineValue(): void
    {
        $refusal = $this->refusal('', ['fail_on' => 5], ['fail_on' => '--fail-on']);

        self::assertSame('Option --fail-on must be string, got int.', $refusal->summary());
        self::assertNull($refusal->position());
        self::assertCount(1, $refusal->sources());
        self::assertSame('--fail-on', $refusal->sources()[0]->locator());
    }

    /** Each preset is a layer of its own, so a refusal names the preset that wrote the value. */
    #[Test]
    public function itNamesThePresetThatWroteAMalformedValue(): void
    {
        file_put_contents($this->directory . '/lenient.yaml', "fail_on: warning\n");
        file_put_contents($this->directory . '/broken.yaml', "cache:\n  enabled: \"no\"\n");

        try {
            $this->pipeline()->resolve(new ConfigurationResolutionRequest(
                AbsolutePath::fromString($this->directory),
                presetNames: ['./lenient.yaml', './broken.yaml'],
            ));
            self::fail('A malformed preset value must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::Preset, $refusal->sources()[0]->source());
            self::assertSame('./broken.yaml', $refusal->sources()[0]->locator());
        }
    }

    /**
     * `only_rules: []` lifting a lower layer's filter is lawful and still
     * worth saying: the diagnostic names both layers.
     */
    #[Test]
    public function itTellsWhenAnEmptyOnlyRulesLiftsALowerLayersFilter(): void
    {
        file_put_contents($this->directory . '/focused.yaml', "only_rules: [complexity.ccn]\n");
        file_put_contents($this->directory . '/qmx.yaml', "only_rules: []\n");

        $document = $this->pipeline()->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            presetNames: ['./focused.yaml'],
        ));

        self::assertSame([], $document->resolved()->get(ConfigSchema::ONLY_RULES)?->plain());
        self::assertCount(1, $document->diagnostics());
        self::assertSame(
            ['./focused.yaml', 'qmx.yaml'],
            array_map(static fn($source): ?string => $source->origin->locator(), $document->diagnostics()[0]->sources),
        );
    }

    /**
     * @param array<string, mixed> $cliValues
     * @param array<string, string> $cliOptionNames
     */
    private function refusal(string $yaml, array $cliValues = [], array $cliOptionNames = []): ConfigurationRefusal
    {
        try {
            $this->resolve($yaml, $cliValues, $cliOptionNames);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('The configuration must be refused.');
    }

    /**
     * @param array<string, mixed> $cliValues
     * @param array<string, string> $cliOptionNames
     */
    private function resolve(string $yaml, array $cliValues = [], array $cliOptionNames = []): ConfigurationDocument
    {
        if ($yaml !== '') {
            file_put_contents($this->directory . '/qmx.yaml', $yaml);
        }

        return $this->pipeline()->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            cliValues: $cliValues,
            cliOptionNames: $cliOptionNames,
        ));
    }

    private function pipeline(): ConfigurationPipeline
    {
        $loader = new YamlConfigLoader();
        $pipeline = new ConfigurationPipeline([
            ...LayeredDocument::standaloneSections(),
        ]);
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new PresetStage($loader, new PresetResolver()));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        return $pipeline;
    }
}
