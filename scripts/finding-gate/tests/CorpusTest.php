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
}
