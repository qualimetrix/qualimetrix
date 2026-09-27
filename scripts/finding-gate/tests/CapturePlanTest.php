<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CapturePlan;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredSurfaces;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\SyntheticTree;

final class CapturePlanTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = SyntheticTree::create(SyntheticTree::clean());
        $path = $this->root . '/finding-gate/cases/alpha/case.json';
        $case = json_decode(Fs::read($path), true, 512, \JSON_THROW_ON_ERROR);
        $case['layerAssignmentSubjects'] = ['App\\A', 'App\\B'];
        $case['renameChannelsMap'] = 'channels.tsv';
        Fs::write($path, json_encode($case, \JSON_THROW_ON_ERROR));
        Fs::write(\dirname($path) . '/channels.tsv', "from\tto\n");
        Fs::write(\dirname($path) . '/baseline-src/src/A.php', "<?php\n");
        for ($index = 0; $index < 100; ++$index) {
            Fs::write(\dirname($path) . '/src/Parallel' . $index . '.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        SyntheticTree::remove($this->root);
    }

    #[Test]
    public function itMapsEveryInvocationAndFileToItsExactCommand(): void
    {
        $plan = $this->plan();
        foreach (['format:json', 'show-suppressed', 'check:baseline-source', 'check:baseline', 'check:output', 'check:parallel'] as $surface) {
            self::assertSame('check', $plan->commandClassOf('case:alpha|' . $surface));
        }
        self::assertSame('rules', $plan->commandClassOf('tree|rules'));
        self::assertSame('graph:export', $plan->commandClassOf('tree|graph:export'));
        self::assertSame('directives', $plan->commandClassOf('case:alpha|directives'));
        foreach (['App\\A', 'App\\B'] as $subject) {
            self::assertSame('debug:layer-assignment', $plan->commandClassOf('case:alpha|debug:layer-assignment:' . $subject));
        }
        self::assertSame('case:alpha|baseline-file', $plan->invocationOf('case:alpha|exit:baseline:generate'));
        self::assertSame('case:alpha|check:output', $plan->invocationOf('case:alpha|check:output:file'));
        foreach (['baseline:update', 'baseline:cleanup', 'baseline:rename-channels'] as $command) {
            self::assertSame($command, $plan->commandClassOf('case:alpha|' . $command));
            self::assertSame('case:alpha|' . $command, $plan->invocationOf('case:alpha|' . $command . ':file'));
        }
        self::assertContains('case:alpha|stderr:format:json', $plan->artifactsOf('case:alpha|format:json'));
        self::assertNotEmpty($plan->invocations());
        self::assertSame('check:output:file', $plan->descriptorOf('case:alpha|check:output')['outputFileKind']);
    }

    #[Test]
    public function itPlansVariantAuthorityBeforeGenerationOnlyWhenTheVariantExists(): void
    {
        $views = array_column($this->plan()->invocations(), 'surface');
        self::assertContains('check:baseline-source', $views);
        self::assertLessThan(
            array_search('baseline-file', $views, true),
            array_search('check:baseline-source', $views, true),
        );
        self::assertSame(
            'case:alpha|check:baseline-source',
            $this->plan()->invocationOf('case:alpha|stderr:check:baseline-source'),
        );
        Fs::removeRecursively($this->root . '/finding-gate/cases/alpha/baseline-src');
        self::assertNotContains('check:baseline-source', array_column($this->plan()->invocations(), 'surface'));
    }

    #[Test]
    public function itAssignsEveryFindingJsonPublicationItsExactRankingSource(): void
    {
        $plan = $this->plan();
        foreach (['format:json', 'check:baseline-source', 'check:baseline'] as $source) {
            self::assertSame('case:alpha|' . $source, $plan->rankingSourceOf('case:alpha|' . $source));
        }
        foreach (['check:output:file', 'check:parallel'] as $alias) {
            self::assertSame('case:alpha|format:json', $plan->rankingSourceOf('case:alpha|' . $alias));
        }
        self::assertSame(
            ['format:json', 'check:baseline-source', 'check:baseline'],
            array_column($plan->rankingInvocations(), 'surface'),
        );
    }

    #[Test]
    public function itRefusesBorrowingRankingForProcessMetadata(): void
    {
        $this->expectException(GateError::class);
        $this->plan()->rankingSourceOf('case:alpha|stderr:format:json');
    }

    #[Test]
    public function itRefusesBorrowingRankingForAnotherJsonDocument(): void
    {
        $this->expectException(GateError::class);
        $this->plan()->rankingSourceOf('case:alpha|directives');
    }

    #[Test]
    public function itRequestsAnIntroducedSurfaceOnlyFromTheCandidate(): void
    {
        Fs::write($this->root . '/finding-gate/' . DeclaredSurfaces::INDEX, "change\tsurface\tfile\treason\nintroduced\tformat:json\t-\tnew report\n");
        $plan = $this->plan();
        self::assertTrue($plan->requiredOn('case:alpha|format:json', 'candidate'));
        self::assertFalse($plan->requiredOn('case:alpha|format:json', 'reference'));
        self::assertTrue($plan->requiredOn('case:alpha|format:metrics', 'reference'));
    }

    #[Test]
    public function itRefusesAnUnknownCaptureSide(): void
    {
        $this->expectException(GateError::class);
        $this->plan()->requiredOn('case:alpha|format:json', 'other');
    }

    #[Test]
    public function itRefusesAnInvocationThatOnlyResemblesAKnownSurface(): void
    {
        $this->expectException(GateError::class);
        $this->plan()->commandClassOf('case:alpha|debug:layer-assignment:App\\Unknown');
    }

    #[Test]
    public function itRefusesAnArtifactOutsideTheDeclaredInvocationTable(): void
    {
        $this->expectException(GateError::class);
        $this->plan()->invocationOf('case:alpha|exit:check:output:file');
    }

    #[Test]
    public function itDoesNotPlanParallelExecutionForExplicitNonPhpPaths(): void
    {
        $caseDirectory = $this->root . '/finding-gate/cases/alpha';
        $case = json_decode(Fs::read($caseDirectory . '/case.json'), true, 512, \JSON_THROW_ON_ERROR);
        $case['paths'] = [];
        for ($index = 0; $index < 100; ++$index) {
            $path = 'text-' . $index . '.txt';
            Fs::write($caseDirectory . '/' . $path, 'text');
            $case['paths'][] = $path;
        }
        Fs::removeRecursively($caseDirectory . '/baseline-src');
        Fs::write($caseDirectory . '/case.json', json_encode($case, \JSON_THROW_ON_ERROR));
        self::assertNotContains('check:parallel', array_column($this->plan()->invocations(), 'surface'));
    }

    private function plan(): CapturePlan
    {
        return CapturePlan::forCorpus(Corpus::load($this->root), DeclaredSurfaces::load($this->root . '/finding-gate'));
    }
}
