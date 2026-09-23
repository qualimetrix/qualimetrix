<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What the key generator makes of the install record it is handed.
 *
 * The record is read from `Composer\InstalledVersions`, whose answers come
 * from every registered autoloader's `installed.php` — `reload()` adds a
 * dataset, it does not replace those, so this process cannot be made to
 * believe anything but the truth about its own vendor tree (measured). Each
 * case therefore runs a child process that declares its own
 * `Composer\InstalledVersions` before `vendor/autoload.php`, which then leaves
 * the class alone because it already exists.
 */
#[CoversClass(CacheKeyGenerator::class)]
final class CacheKeyGeneratorInstallRecordTest extends TestCase
{
    private const string PACKAGE = 'nikic/php-parser';

    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
    }

    /**
     * A tagged release names one set of bytes, so the version is the whole
     * answer and the install's reference adds nothing to it. This is the shape
     * this tree itself installs, and it is asserted so that the branch case
     * below cannot be read as a change to it.
     */
    #[Test]
    public function itKeysATaggedInstallOnItsVersionAlone(): void
    {
        $result = $this->keyGeneratorUnder('5.7.0.0', str_repeat('a', 40));

        self::assertSame('php' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION . '-parser5.7.0.0', $result['version']);
        self::assertSame([], $result['warnings']);
    }

    /**
     * A branch version does not name bytes: every commit on the branch carries
     * the string `dev-main` while the parser's node classes move underneath
     * it. Two installs of one branch must not share a key — which is what the
     * code said it did before it did it.
     */
    #[Test]
    public function itKeysABranchInstallOnItsCommitAndNotItsBranchName(): void
    {
        $first = $this->keyGeneratorUnder('dev-main', str_repeat('a', 40));
        $second = $this->keyGeneratorUnder('dev-main', str_repeat('b', 40));

        self::assertStringContainsString(str_repeat('a', 40), $first['version']);
        self::assertNotSame(
            $first['version'],
            $second['version'],
            'two commits of one dev branch shared a cache key',
        );
        self::assertNotSame(
            $first['key'],
            $second['key'],
            'identical bytes under two parsers produced one key',
        );
    }

    /**
     * A branch install with nothing more precise on offer — a path repository,
     * for one. The branch name is kept rather than turned into a refusal to
     * cache: it is the best available answer, not an absent one.
     */
    #[Test]
    public function itFallsBackToTheBranchNameWhenTheInstallHasNoReference(): void
    {
        $result = $this->keyGeneratorUnder('dev-main', null);

        self::assertSame('php' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION . '-parserdev-main', $result['version']);
        self::assertNotSame('', $result['key'], 'a branch install must still be cached');
        self::assertSame([], $result['warnings']);
    }

    /**
     * Giving caching up when the parser cannot be named is the right call and
     * an invisible one: every key comes back empty, every file is parsed
     * afresh and the cache directory stays empty without a word. The word is
     * what is asserted here.
     */
    #[Test]
    public function itSaysWhenItCannotNameTheParserAndStopsCaching(): void
    {
        $result = $this->keyGeneratorUnder(null, null);

        self::assertSame('', $result['version']);
        self::assertSame('', $result['key'], 'an unnameable parser must not produce a key');

        $warnings = $result['warnings'];
        self::assertIsArray($warnings);
        self::assertCount(1, $warnings, 'caching turned itself off without saying so');
        self::assertIsString($warnings[0]);
        self::assertStringContainsString(self::PACKAGE, $warnings[0]);
        self::assertStringContainsString('caching', strtolower($warnings[0]));
    }

    /**
     * Builds a generator in a child process whose Composer runtime answers
     * with the given record; `null` for the version means the package is not
     * installed there at all.
     *
     * The probe leaves `Composer` through a braced namespace rather than a
     * second `namespace X;`, so it names no `Qualimetrix\Tests\…` namespace
     * at all: `scripts/dangling-test-names.py` reads the tree, and a name
     * written here that no file declares is one it has to adjudicate.
     *
     * @return array{version: string, key: string, warnings: list<mixed>}
     */
    private function keyGeneratorUnder(?string $version, ?string $reference): array
    {
        $script = \sprintf(
            <<<'PHP'
                <?php

                namespace Composer {
                    final class InstalledVersions
                    {
                        public static function isInstalled($package, $includeDevRequirements = true)
                        {
                            return $package === %s && %s !== null;
                        }

                        public static function getVersion($package)
                        {
                            return $package === %s ? %s : null;
                        }

                        public static function getReference($package)
                        {
                            return $package === %s ? %s : null;
                        }
                    }
                }

                namespace {
                    require %s;

                    $logger = new class extends \Psr\Log\AbstractLogger {
                        public array $warnings = [];

                        public function log($level, \Stringable|string $message, array $context = []): void
                        {
                            if ((string) $level === 'warning') {
                                $this->warnings[] = (string) $message;
                            }
                        }
                    };

                    $generator = new \Qualimetrix\Infrastructure\Cache\CacheKeyGenerator($logger);

                    echo json_encode([
                        'version' => $generator->getCacheVersion(),
                        'key' => $generator->generateForContent('<?php class A {}'),
                        'warnings' => $logger->warnings,
                    ]);
                }
                PHP,
            var_export(self::PACKAGE, true),
            var_export($version, true),
            var_export(self::PACKAGE, true),
            var_export($version, true),
            var_export(self::PACKAGE, true),
            var_export($reference, true),
            var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true),
        );

        $path = sys_get_temp_dir() . '/qmx-install-record-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $script);
        $this->scripts[] = $path;

        $process = new Process([\PHP_BINARY, $path]);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);

        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf(
                'The child process produced no record: %s%s',
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }

        /** @var array{version: string, key: string, warnings: list<mixed>} $decoded */
        return $decoded;
    }
}
