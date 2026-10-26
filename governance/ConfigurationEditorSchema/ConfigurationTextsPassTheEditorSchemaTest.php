<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConfigurationEditorSchema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Subprocess\ChildProcess;
use Qualimetrix\Tests\Analysis\Configuration\Integration\EditorSchema\EngineReading;
use Qualimetrix\Tests\Analysis\Configuration\Support\EditorSchemaValidator;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Every configuration text in this repository that the engine accepts passes
 * the editor schema: the tracked configs and presets, `qmx.yaml.example` and
 * the `yaml` blocks of the website whose every top-level key is a document
 * root — what users copy. The differential test judges the combinations its
 * author listed; these are the combinations people actually write.
 *
 * The texts are judged against the committed `qmx.schema.json`, the file users
 * receive, not a projection made in this process.
 *
 * Only a text the engine accepts is judged: the schema promises nothing about
 * one the engine refuses. Website blocks the engine refuses are outside that
 * promise; most of them are outdated examples, not deliberate ones.
 */
final class ConfigurationTextsPassTheEditorSchemaTest extends TestCase
{
    /** Texts the engine must accept, so a moved or broken one cannot leave the population unnoticed. */
    private const array REQUIRED = [
        'qmx.yaml',
        'qmx.yaml.example',
        'src/Analysis/Configuration/Preset/ci.yaml',
        'src/Analysis/Configuration/Preset/legacy.yaml',
        'src/Analysis/Configuration/Preset/strict.yaml',
    ];

    /** Measured when the control was added; a selection that silently narrows falls below them. */
    private const int MINIMUM_ACCEPTED_FILES = 57;

    private const int MINIMUM_ACCEPTED_BLOCKS = 234;

    private const string BUILT_IN_PRESETS = 'src/Analysis/Configuration/Preset/';

    /** A workflow, not a configuration, despite its name. */
    private const string WORKFLOWS = '.github/workflows/';

    /** @var array{files: array<string, string>, blocks: array<string, string>}|null */
    private static ?array $accepted = null;

    #[Test]
    public function itPassesEveryTextTheEngineAccepts(): void
    {
        $schema = json_decode(self::read('qmx.schema.json'), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $schema);

        $offenders = [];
        foreach (self::accepted() as $texts) {
            foreach ($texts as $source => $text) {
                $errors = EditorSchemaValidator::yamlErrors($schema, $text);
                if ($errors !== []) {
                    $offenders[$source] = $errors;
                }
            }
        }

        self::assertSame([], $offenders, 'The editor schema refuses configuration the engine accepts.');
    }

    #[Test]
    public function itJudgesAtLeastTheMeasuredPopulation(): void
    {
        $accepted = self::accepted();

        foreach (self::REQUIRED as $file) {
            self::assertArrayHasKey($file, $accepted['files'], $file . ' is missing from the population or refused by the engine.');
        }

        self::assertGreaterThanOrEqual(self::MINIMUM_ACCEPTED_FILES, \count($accepted['files']));
        self::assertGreaterThanOrEqual(self::MINIMUM_ACCEPTED_BLOCKS, \count($accepted['blocks']));
    }

    /** @return array{files: array<string, string>, blocks: array<string, string>} */
    private static function accepted(): array
    {
        if (self::$accepted !== null) {
            return self::$accepted;
        }

        $tracked = self::tracked();
        $accepted = ['files' => [], 'blocks' => []];
        foreach (['files' => self::files($tracked), 'blocks' => self::blocks($tracked)] as $kind => $texts) {
            foreach ($texts as $source => $text) {
                if (EngineReading::refusal($text) === null) {
                    $accepted[$kind][$source] = $text;
                }
            }
        }

        return self::$accepted = $accepted;
    }

    /**
     * @param list<string> $tracked
     *
     * @return array<string, string>
     */
    private static function files(array $tracked): array
    {
        $files = [];
        foreach ($tracked as $path) {
            $name = basename($path);
            $configuration = preg_match('/^(?:qmx|preset)\.ya?ml$/', $name) === 1 && !str_starts_with($path, self::WORKFLOWS);
            if ($configuration || $path === 'qmx.yaml.example' || (str_starts_with($path, self::BUILT_IN_PRESETS) && str_ends_with($path, '.yaml'))) {
                $files[$path] = self::read($path);
            }
        }

        return $files;
    }

    /**
     * The fenced `yaml` blocks of the website that read as a configuration document.
     *
     * @param list<string> $tracked
     *
     * @return array<string, string> keyed by page and the line of the opening fence
     */
    private static function blocks(array $tracked): array
    {
        $roots = [];
        foreach (EngineReading::rootKeys() as $root) {
            foreach (ConfigKeySpelling::acceptedSpellings($root) as $spelling) {
                $roots[$spelling] = true;
            }
        }

        $blocks = [];
        foreach ($tracked as $path) {
            if (!str_starts_with($path, 'website/docs/') || !str_ends_with($path, '.md')) {
                continue;
            }

            foreach (self::fencedYaml(self::read($path)) as $line => $text) {
                if (self::isDocument($text, $roots)) {
                    $blocks[$path . ':' . $line] = $text;
                }
            }
        }

        return $blocks;
    }

    /**
     * Each block's text with the fence's indentation removed, so a block inside an admonition reads as written.
     *
     * @return array<int, string> keyed by the line of the opening fence
     */
    private static function fencedYaml(string $page): array
    {
        $blocks = [];
        $indent = null;
        $opening = 0;
        $lines = [];
        foreach (explode("\n", $page) as $index => $line) {
            if ($indent === null) {
                if (preg_match('/^(\s*)```ya?ml\s*$/', $line, $match) === 1) {
                    [$indent, $opening, $lines] = [$match[1], $index + 1, []];
                }

                continue;
            }

            if (preg_match('/^\s*```\s*$/', $line) === 1) {
                $blocks[$opening] = implode("\n", $lines);
                $indent = null;

                continue;
            }

            $lines[] = str_starts_with($line, $indent) ? substr($line, \strlen($indent)) : ltrim($line);
        }

        return $blocks;
    }

    /** @param array<string, true> $roots */
    private static function isDocument(string $text, array $roots): bool
    {
        try {
            $parsed = Yaml::parse($text);
        } catch (ParseException) {
            return false;
        }

        if (!\is_array($parsed) || $parsed === [] || array_is_list($parsed)) {
            return false;
        }

        foreach (array_keys($parsed) as $key) {
            if (!isset($roots[(string) $key])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function tracked(): array
    {
        try {
            $result = ChildProcess::run(['git', 'ls-files', '-z'], self::root());
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        return array_values(array_filter(explode("\0", $result['stdout']), static fn(string $path): bool => $path !== ''));
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::root() . '/' . $path);
        self::assertIsString($text, $path);

        return $text;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
