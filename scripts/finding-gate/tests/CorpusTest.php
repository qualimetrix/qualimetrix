<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Corpus;
use QmxFindingGate\CorpusInvalid;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\SyntheticTree;

final class CorpusTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = SyntheticTree::fixture(SyntheticTree::clean());
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
    }

    #[Test]
    public function itRefusesEveryUnknownRequestedCaseEvenBesideAKnownCase(): void
    {
        foreach ([['nope'], ['alpha', 'nope']] as $only) {
            try {
                Corpus::load($this->root, $only);
                self::fail('An unknown requested case was accepted.');
            } catch (GateError $error) {
                self::assertSame(GateError::class, $error::class);
                self::assertNull($error->getPrevious());
                self::assertStringContainsString(\count($only) === 1 ? 'No case selected' : '--cases names no case', $error->getMessage());
                if (\count($only) > 1) {
                    self::assertStringContainsString('nope', $error->getMessage());
                }
            }
        }
    }

    #[Test]
    public function itRefusesAnEmptyActualCorpusWithItsOriginalLoadFailure(): void
    {
        Fs::removeRecursively($this->root . '/finding-gate/cases/alpha');
        $this->assertCorpusInvalid(fn(): Corpus => Corpus::load($this->root), 'No case selected');
    }

    #[Test]
    public function itRefusesAMissingCorpusWithItsOriginalLoadFailure(): void
    {
        Fs::removeRecursively($this->root . '/finding-gate/cases');
        $this->assertCorpusInvalid(fn(): Corpus => Corpus::load($this->root), 'No corpus at');
    }

    #[Test]
    public function itRefusesMalformedSelectedMetadataWithItsOriginalLoadFailure(): void
    {
        Fs::write($this->root . '/finding-gate/cases/alpha/case.json', '{"id":"alpha"}');
        $this->assertCorpusInvalid(fn(): Corpus => Corpus::load($this->root, ['alpha']), 'description');
    }

    #[Test]
    public function itLoadsANonemptyNumericSelectionWithAStringIdentity(): void
    {
        $directory = $this->root . '/finding-gate/cases/42';
        self::assertTrue(rename($this->root . '/finding-gate/cases/alpha', $directory));
        $definition = json_decode(Fs::read($directory . '/case.json'), true, 512, \JSON_THROW_ON_ERROR);
        $definition['id'] = '42';
        Fs::write($directory . '/case.json', json_encode($definition, \JSON_THROW_ON_ERROR));
        $corpus = $this->loadSelected(['42']);
        self::assertCount(1, $corpus->cases);
        self::assertSame('42', $corpus->cases[0]->id);
        self::assertSame($directory, $corpus->cases[0]->directory);
    }

    #[Test]
    public function itExcludesUnselectedMalformedMetadata(): void
    {
        Fs::write($this->root . '/finding-gate/cases/beta/case.json', '{"id":"beta"}');
        $corpus = $this->loadSelected(['alpha']);
        self::assertCount(1, $corpus->cases);
        self::assertSame('alpha', $corpus->cases[0]->id);
    }

    /** @param list<string> $only */
    private function loadSelected(array $only): Corpus
    {
        try {
            return Corpus::load($this->root, $only);
        } catch (GateError $error) {
            self::fail('A valid selection failed: ' . $error->getMessage());
        }
    }

    /** @param callable(): Corpus $load */
    private function assertCorpusInvalid(callable $load, string $message): void
    {
        try {
            $load();
            self::fail('An invalid corpus returned a public model.');
        } catch (GateError $error) {
            self::assertSame(CorpusInvalid::class, $error::class);
            $previous = $error->getPrevious();
            self::assertInstanceOf(GateError::class, $previous);
            self::assertSame(GateError::class, $previous::class);
            self::assertSame($previous->getMessage(), $error->getMessage());
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    /**
     * The corpus is external and self-contained, and a restricted run says
     * which cases it ran.
     *
     * Both halves are exercised on a written corpus, because loading is where
     * the tracked corpus meets them. Every refusal below loaded before its check
     * existed: `paths` was the only value held to the case directory, and a
     * misspelt `--cases` name beside a real one was dropped while the limit line
     * still named it.
     */
    #[Test]
    public function itKeepsEveryCaseInputInsideItsDirectoryAndRefusesAnUnmatchedCaseName(): void
    {
        $root = Fs::temporaryDirectory('corpus-boundaries-');
        try {
            $directory = $root . '/finding-gate/cases/probe';
            mkdir($directory . '/src', 0o777, true);
            Fs::write($directory . '/qmx.yaml', "suppress_paths: []\n");
            Fs::write($directory . '/preset.yaml', "rules: {}\n");
            Fs::write(\dirname($directory) . '/qmx.yaml', "suppress_paths: []\n");

            $loads = static function (string $config, array $args) use ($root, $directory): bool {
                Fs::write($directory . '/case.json', (string) json_encode([
                    'id' => 'probe',
                    'description' => 'a written case, so what it may point at is checked where a case is loaded',
                    'paths' => ['src'],
                    'config' => $config,
                    'args' => $args,
                    'channels' => ['a.code@class'],
                ]));

                return !self::throws(static fn(): mixed => Corpus::load($root, []));
            };

            self::assertTrue(
                $loads('qmx.yaml', ['--preset=strict', '--preset=preset.yaml', '-c', 'qmx.yaml', '--rule-opt=a.b:c=1']),
                'a case whose config, presets and path arguments stay in its directory loads',
            );
            self::assertTrue(!$loads('../qmx.yaml', []), 'a config reached through ".." outside the directory is refused');
            self::assertTrue($loads('../probe/qmx.yaml', []), 'while one whose ".." leads back inside loads');
            self::assertTrue(!$loads('missing.yaml', []), 'and a config that does not exist is refused');

            foreach ([
                'an absolute config as a separated -c' => ['-c', '/elsewhere/qmx.yaml'],
                'an attached -c' => ['-c/elsewhere/qmx.yaml'],
                'a -c inside a short-option cluster' => ['-qc/elsewhere/qmx.yaml'],
                'an attached --config' => ['--config=/elsewhere/qmx.yaml'],
                'a separated --config' => ['--config', '../../qmx.yaml'],
                'a preset path' => ['--preset=../../../preset.yaml'],
                'a preset hidden in a comma list' => ['--preset=strict,/elsewhere/preset.yaml'],
                'a baseline' => ['--baseline=/elsewhere/baseline.json'],
            ] as $what => $args) {
                self::assertTrue(!$loads('qmx.yaml', $args), \sprintf('%s outside the case directory is refused', $what));
            }

            foreach ([
                'a separated -o' => ['-o', 'report.json'],
                'a -o inside a short-option cluster' => ['-qoreport.json'],
                'an attached --output' => ['--output=report.json'],
                'a log file' => ['--log-file=qmx.log'],
                'a cache directory' => ['--cache-dir=cache'],
                'a profile without a file' => ['--profile'],
                'a separated working directory' => ['-d', 'src'],
                'an attached --working-dir' => ['--working-dir=src'],
            ] as $what => $args) {
                self::assertTrue(!$loads('qmx.yaml', $args), \sprintf('%s is refused wherever it points', $what));
            }

            self::assertTrue(
                !$loads('qmx.yaml', ['--format', 'json']),
                'a bare token that is no path option\'s value is refused: whether it names a path cannot be known',
            );
            self::assertTrue(!$loads('qmx.yaml', ['--', 'src']), 'and so is ending option parsing, after which every token is one');

            $loads('qmx.yaml', []);
            $unmatched = null;

            try {
                Corpus::load($root, ['probe', 'nope']);
            } catch (GateError $error) {
                $unmatched = $error->getMessage();
            }

            self::assertTrue(
                $unmatched !== null && str_contains($unmatched, 'nope'),
                'a --cases name that selects no case is refused by name even beside one that does',
            );
            self::assertTrue(
                !self::throws(static fn(): mixed => Corpus::load($root, ['probe'])),
                'while a --cases list whose every name selects a case loads',
            );
        } finally {
            Fs::removeRecursively($root);
        }
    }

    private static function throws(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (GateError) {
            return true;
        }
    }
}
