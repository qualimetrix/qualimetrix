<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseDefinition;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;

/**
 * What a case may point at, judged where each path leads rather than how it is spelled.
 */
final class CaseDefinitionTest extends TestCase
{
    private string $root;

    private string $case;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('case-definition-test-');
        $this->case = $this->root . '/cases/probe';
        mkdir($this->case . '/src', 0o777, true);
        mkdir($this->root . '/outside');
        Fs::write($this->case . '/qmx.yaml', "suppress_paths: []\n");
        Fs::write($this->root . '/outside/qmx.yaml', "suppress_paths: []\n");
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itRefusesAConfigThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/linked.yaml');

        $this->assertRefused('outside its own directory', config: 'linked.yaml');
    }

    #[Test]
    public function itRefusesAnAnalysisPathThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside', $this->case . '/linked');

        $this->assertRefused('outside its own directory', paths: ['linked']);
    }

    #[Test]
    public function itRefusesAnInputOptionThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/baseline.json');

        $this->assertRefused('outside its own directory', args: ['--baseline=baseline.json']);
    }

    #[Test]
    public function itRefusesACaseDirectoryThatIsALink(): void
    {
        mkdir($this->root . '/outside/src');
        Fs::write($this->root . '/outside/case.json', (string) json_encode([
            'id' => 'linked',
            'description' => 'A case whose directory is a link.',
            'paths' => ['src'],
            'config' => 'qmx.yaml',
            'channels' => ['a.code@class'],
        ]));
        symlink($this->root . '/outside', $this->root . '/cases/linked');

        try {
            CaseDefinition::load($this->root . '/cases/linked');
        } catch (GateError $error) {
            self::assertStringContainsString('may not be a link', $error->getMessage());

            return;
        }

        self::fail('The linked case directory was accepted.');
    }

    #[Test]
    public function itReadsAConfigWithACommaAsOnePath(): void
    {
        Fs::write($this->case . '/a,b.yaml', "suppress_paths: []\n");

        self::assertSame(['a,b.yaml'], $this->load(config: 'a,b.yaml', args: ['--config=a,b.yaml'])->argumentPaths());
    }

    #[Test]
    public function itRefusesAWorkingDirectoryEvenInsideTheCase(): void
    {
        $this->assertRefused('The gate runs a case in its own directory', args: ['-d', 'src']);
    }

    #[Test]
    public function itRefusesAPathThatDoesNotExist(): void
    {
        $this->assertRefused('does not exist', paths: ['missing']);
    }

    #[Test]
    public function itRefusesAnOutputOptionEvenInsideTheCase(): void
    {
        $this->assertRefused('which writes', args: ['--output=report.json']);
    }

    #[Test]
    public function itAcceptsANameThatOnlyContainsTwoDots(): void
    {
        mkdir($this->case . '/a..b');

        self::assertSame(['a..b'], $this->load(paths: ['a..b'])->paths);
    }

    #[Test]
    public function itReadsANamedPresetAsNoPathAndAPresetFileAsOne(): void
    {
        self::assertSame([], $this->load(args: ['--preset=strict'])->argumentPaths());
        $this->assertRefused('does not exist', args: ['--preset=strict,missing.yaml']);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $args
     */
    private function assertRefused(string $reason, array $paths = ['src'], string $config = 'qmx.yaml', array $args = []): void
    {
        try {
            $this->load($paths, $config, $args);
        } catch (GateError $error) {
            self::assertStringContainsString($reason, $error->getMessage());

            return;
        }

        self::fail('The case was accepted.');
    }

    /**
     * @param list<string> $paths
     * @param list<string> $args
     */
    private function load(array $paths = ['src'], string $config = 'qmx.yaml', array $args = []): CaseDefinition
    {
        Fs::write($this->case . '/case.json', (string) json_encode([
            'id' => 'probe',
            'description' => 'A case written by the test.',
            'paths' => $paths,
            'config' => $config,
            'args' => $args,
            'channels' => ['a.code@class'],
        ]));

        return CaseDefinition::load($this->case);
    }
}
