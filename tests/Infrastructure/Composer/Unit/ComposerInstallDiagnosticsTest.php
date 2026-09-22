<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Composer\GeneratedClassmap;
use RuntimeException;
use Stringable;

/**
 * What the run says when the analysed project's install cannot be read.
 *
 * The comparison that matters is **valid against damaged**, not damaged
 * against absent: an absent `composer.json` is already named correctly
 * downstream ("this run found no composer install to follow them through"),
 * so a damaged one looking different from an absent one proves nothing. It
 * used to look identical to a project whose class simply lives elsewhere —
 * the depth published for DIT dropped by one and the class turned into
 * external coupling, while the only message the user saw named the wrong
 * cause.
 */
#[CoversClass(ComposerAutoloadMap::class)]
#[CoversClass(GeneratedClassmap::class)]
final class ComposerInstallDiagnosticsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-composer-diag-' . bin2hex(random_bytes(6));

        if (!mkdir($this->root . '/src', 0o777, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Cannot create the fixture project');
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /**
     * The control half of the pair: the same fixture, intact. Without it a
     * warning could be arriving on every run and the damaged case would still
     * look like it had proved something.
     */
    #[Test]
    public function itSaysNothingAboutAManifestItCouldRead(): void
    {
        $this->writeValidProject();

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertNotNull($map->fileFor('App\\Thing'));
        self::assertSame([], $logger->warnings());
    }

    /**
     * The other half: one byte removed, nothing else changed.
     */
    #[Test]
    public function itNamesTheManifestItCouldNotParse(): void
    {
        $this->writeValidProject();
        $manifest = $this->root . '/composer.json';
        file_put_contents($manifest, substr((string) file_get_contents($manifest), 0, -2));

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertNull($map->fileFor('App\\Thing'), 'a damaged manifest must not place classes');

        $warnings = $logger->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString($manifest, $warnings[0]);
        self::assertStringContainsString('invalid JSON', $warnings[0]);
    }

    /**
     * Valid JSON that is not an object parses without error, so the reason has
     * to come from the shape rather than from `json_last_error()`.
     */
    #[Test]
    public function itNamesAManifestWhoseTopLevelValueIsNotAnObject(): void
    {
        file_put_contents($this->root . '/composer.json', '"just a string"');

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);
        $map->fileFor('App\\Thing');

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('the top-level value is not a JSON object', $logger->warnings()[0]);
    }

    /**
     * An absent manifest keeps its silence: the run already names that case,
     * and a second message for it would be noise on a legitimate state.
     */
    #[Test]
    public function itStaysSilentAboutAnInstallThatIsSimplyNotThere(): void
    {
        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root . '/nowhere', [$this->root . '/nowhere']);

        self::assertFalse($map->isConfigured());
        self::assertSame([], $logger->warnings());
    }

    #[Test]
    public function itNamesAManifestItCouldNotOpen(): void
    {
        $this->writeValidProject();
        $manifest = $this->root . '/composer.json';
        chmod($manifest, 0o000);

        if (is_readable($manifest)) {
            // Running with privileges that ignore the mode bits: the state
            // this case is about cannot be produced here.
            self::markTestSkipped('The fixture stayed readable after chmod 000.');
        }

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);
        $map->fileFor('App\\Thing');

        chmod($manifest, 0o644);

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('could not be opened', $logger->warnings()[0]);
        self::assertStringContainsString($manifest, $logger->warnings()[0]);
    }

    /**
     * The same reader serves `installed.json`, so the damage is reported for
     * the packages half of the install too — a single manifest case would not
     * have shown that.
     */
    #[Test]
    public function itNamesTheInstalledPackagesFileItCouldNotParse(): void
    {
        $this->writeValidProject();
        $this->write('vendor/composer/installed.json', '{"packages": [');

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);
        $map->fileFor('App\\Thing');

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('vendor/composer/installed.json', $logger->warnings()[0]);
    }

    #[Test]
    public function itNamesAGeneratedClassmapItCouldNotParse(): void
    {
        $this->writeValidProject();
        $this->write('vendor/composer/autoload_classmap.php', "<?php\n\nreturn array(\n    'Broken' =>\n");

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);
        $map->fileFor('App\\Thing');

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('Cannot parse', $logger->warnings()[0]);
        self::assertStringContainsString('autoload_classmap.php', $logger->warnings()[0]);
    }

    /**
     * The size cap is a deliberate refusal, and a deliberate refusal the user
     * cannot see is indistinguishable from the project having no classmap.
     */
    #[Test]
    public function itNamesAGeneratedClassmapTooLargeToRead(): void
    {
        $this->writeValidProject();
        $this->write(
            'vendor/composer/autoload_classmap.php',
            "<?php\n\n" . str_repeat("// pad\n", 1_300_000) . "\nreturn array();\n",
        );

        $logger = new CollectingLogger();
        $map = new ComposerAutoloadMap(logger: $logger);
        $map->pointAt($this->root, [$this->root . '/src']);
        $map->fileFor('App\\Thing');

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('exceeds the', $logger->warnings()[0]);
        self::assertStringContainsString('autoload_classmap.php', $logger->warnings()[0]);
    }

    private function writeValidProject(): void
    {
        $this->write('composer.json', (string) json_encode(
            ['autoload' => ['psr-4' => ['App\\' => 'src/']]],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        ));
        $this->write('src/Thing.php', "<?php\n\nnamespace App;\n\nclass Thing {}\n");
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @chmod($path . '/' . $entry, 0o644);
            self::removeTree($path . '/' . $entry);
        }

        @rmdir($path);
    }
}

/**
 * Keeps what the map said, so a test can assert a diagnostic exists rather
 * than assume it does.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
