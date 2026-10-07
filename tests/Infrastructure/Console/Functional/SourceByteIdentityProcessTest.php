<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(SymbolPath::class)]
final class SourceByteIdentityProcessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-source-bytes-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o755, true);
        file_put_contents($this->directory . '/composer.json', '{"autoload":{"psr-4":{"Bytes\\\\":"src/"}}}');
        file_put_contents($this->directory . '/qmx.yaml', <<<'YAML'
only_rules: [complexity.ccn, architecture.circular-dependency, architecture.layer-violation, duplication.clone]
rules:
  complexity.ccn:
    callable: { warning: 2, error: 10 }
    class: { enabled: false }
  duplication.clone: { min-tokens: 20, min-lines: 3 }
architecture:
  coverage-gap: ignore
  layers:
    - name: public
      patterns: ['Bytes\Public\**']
    - name: private
      patterns: ['Bytes\Private\**']
  allow:
    public: []
    private: []
YAML);
        foreach ([255 => 'First', 254 => 'Second'] as $byte => $file) {
            $source = <<<'PHP'
<?php
namespace Bytes\Public;
final class IDENTIFIER {
    public function METHOD(array $rows): array {
        $out = [];
        foreach ($rows as $key => $row) {
            if ($row['active']) {
                $out[$key] = FUNCTION_NAME($row['name']);
            } elseif ($row['pending']) {
                $out[$key] = null;
            }
        }
        ksort($out);
        return array_filter($out);
    }
    public function dependencies(): array {
        return [new OTHER(), new \Bytes\Private\TARGET()];
    }
}
PHP;
            file_put_contents($this->directory . '/src/' . $file . '.php', strtr($source, [
                'IDENTIFIER' => 'K' . \chr($byte),
                'OTHER' => 'K' . \chr($byte === 255 ? 254 : 255),
                'METHOD' => "m\xE9",
                'FUNCTION_NAME' => "my\xFFfn",
                'TARGET' => "W\xFFX",
            ]));
        }
        file_put_contents($this->directory . '/src/Private.php', "<?php namespace Bytes\\Private; final class W\xFFX {}\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itPublishesByteFindingsAndDistinctSarifFingerprints(): void
    {
        $json = $this->runChild(['check', 'src', '--format=json'], 2);
        $report = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(7, $report['summary']['violationCount']);
        self::assertSame(2, $report['violationsMeta']['byRule']['duplication.clone']);
        self::assertStringContainsString('K%FF', $json);
        self::assertStringContainsString('K%FE', $json);
        self::assertStringNotContainsString('Cycle data:', $json);
        self::assertStringNotContainsString('Dep data:', $json);

        $sarif = json_decode($this->runChild(['check', 'src', '--format=sarif', '--only-rule=complexity.ccn'], 0), true, flags: \JSON_THROW_ON_ERROR);
        $results = $sarif['runs'][0]['results'];
        self::assertCount(2, $results);
        self::assertNotSame($results[0]['partialFingerprints'], $results[1]['partialFingerprints']);
    }

    #[Test]
    public function itPublishesBothByteNodesInBothGraphFormats(): void
    {
        $json = $this->runChild(['graph:export', 'src', '--format=json'], 0);
        self::assertIsArray(json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
        $dot = $this->runChild(['graph:export', 'src', '--format=dot'], 0);
        foreach (['K%FF', 'K%FE'] as $name) {
            self::assertStringContainsString($name, $json);
            self::assertStringContainsString($name, $dot);
        }
    }

    #[Test]
    public function itWritesAndReadsABaselineForByteMethods(): void
    {
        $this->runChild(['baseline:generate', 'baseline.json', 'src', '--only-rule=complexity.ccn'], 0);
        $baseline = file_get_contents($this->directory . '/baseline.json');
        self::assertIsString($baseline);
        self::assertIsArray(json_decode($baseline, true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('K%FF::m%E9', $baseline);
        self::assertStringContainsString('K%FE::m%E9', $baseline);
        $report = json_decode($this->runChild(['check', 'src', '--format=json', '--only-rule=complexity.ccn', '--baseline=baseline.json'], 0), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(0, $report['summary']['violationCount']);
    }

    #[Test]
    public function itPublishesAThresholdRefusalContainingSourceBytes(): void
    {
        file_put_contents($this->directory . '/src/Directive.php', "<?php\nnamespace Bytes\\Public;\n/**\n * @qmx-threshold complexity.ccn warning=\xFF\n */\nfinal class Directive {}\n");
        $output = $this->runChild(['directives', 'src', '--format=json'], 2);
        self::assertIsArray(json_decode($output, true, flags: \JSON_THROW_ON_ERROR));
        self::assertTrue(mb_check_encoding($output, 'UTF-8'));
        self::assertStringContainsString('%FF', $output);
    }

    #[Test]
    public function itWritesABaselineForABytePathOnLinux(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Byte filenames require a Linux filesystem; APFS normalizes or refuses them.');
        }
        rename($this->directory . '/src/First.php', $this->directory . "/src/First\xFF.php");
        $this->runChild(['baseline:generate', 'baseline.json', 'src', '--only-rule=complexity.ccn'], 0);
        $baseline = file_get_contents($this->directory . '/baseline.json');
        self::assertIsString($baseline);
        self::assertStringContainsString('First%FF.php', $baseline);
        $this->runChild(['check', 'src', '--format=json', '--only-rule=complexity.ccn', '--baseline=baseline.json'], 0);
    }

    /** @param list<string> $arguments */
    private function runChild(array $arguments, int $expectedExit): string
    {
        $options = $arguments[0] === 'directives' ? ['--config=qmx.yaml'] : ['--config=qmx.yaml', '--workers=0', '--no-progress', '--no-cache'];
        $result = ChildProcess::run([\PHP_BINARY, '-d', 'xdebug.mode=off', \dirname(__DIR__, 4) . '/bin/qmx', ...$arguments, ...$options], $this->directory);
        self::assertSame($expectedExit, $result['exitCode'], $result['stderr'] . "\n" . $result['stdout']);

        return $result['stdout'];
    }
}
