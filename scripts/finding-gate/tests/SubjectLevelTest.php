<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseDefinition;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\SubjectLevel;

/**
 * The unit of a case's claim: a channel and the level it fired at, for every
 * subject shape the comparison meets, refused where a case is loaded.
 */
final class SubjectLevelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itLevelsEverySubjectShapeAndRefusesOneItCannotLevel(): void
    {
        $shapes = [
            'declaration:callable:Corpus\\A::b@src/A.php' => 'callable',
            'declaration:class:Corpus\\A@src/A.php' => 'class',
            'declaration:class:Corpus\\A@src/A.php#2' => 'class',
            'declaration:func:Corpus\\A::helper@src/A.php' => 'callable',
            'class:Corpus\\A' => 'class',
            'file:src/A.php' => 'file',
            'ns:Corpus\\A' => 'namespace',
            'project:' => 'project',
        ];

        foreach ($shapes as $subject => $level) {
            self::assertSame($level, SubjectLevel::of($subject), 'the level of "' . $subject . '"');
        }

        self::assertTrue(
            self::throws(static fn(): mixed => SubjectLevel::of('member:Corpus\\A::$b')),
            'a subject shape the gate cannot level stops the run instead of claiming a level for it',
        );
        self::assertSame(
            'a.rule#a.code@class',
            SubjectLevel::claim('a.rule#a.code', 'class'),
            'a claim entry is the channel and the level, separated by a character no name may contain',
        );
        self::assertSame(
            'a.rule#a.code',
            SubjectLevel::channelOf('a.rule#a.code@class'),
            'and the channel is readable back out of it, which is what coverage counts',
        );
        self::assertTrue(
            self::throws(static function (): void {
                SubjectLevel::assertClaim('a.rule#a.code', 'case.json');
            }),
            'a bare channel name is refused as a claim: the old shape claims less than it looks like it claims',
        );
        self::assertTrue(
            self::throws(static function (): void {
                SubjectLevel::assertClaim('a.rule#a.code@klass', 'case.json');
            }),
            'and so is a level outside the product\'s own vocabulary',
        );
    }

    /**
     * What `case.json` may claim, checked where a case is loaded.
     *
     * A repeated pair is refused rather than tolerated: the observed set is
     * deduplicated by pair, so a claim listing one twice can never be satisfied,
     * and a claim nothing can satisfy is the shape a half-done migration leaves
     * behind. Exercised on a written case rather than on the loader's argument,
     * because loading is where the tracked corpus meets it.
     */
    #[Test]
    public function itRefusesAClaimNoObservedSetCouldSatisfyWhereTheCaseIsLoaded(): void
    {
        $root = Fs::temporaryDirectory('claim-shape-');
        try {
            $directory = $root . '/probe';
            mkdir($directory . '/src', 0o777, true);
            Fs::write($directory . '/qmx.yaml', "suppress_paths: []\n");

            $write = static function (array $channels) use ($directory): void {
                Fs::write($directory . '/case.json', (string) json_encode([
                    'id' => 'probe',
                    'description' => 'a written case, so the claim shape is checked where a case is loaded',
                    'paths' => ['src'],
                    'config' => 'qmx.yaml',
                    'channels' => $channels,
                ]));
            };

            $write(['a.code@class', 'a.code@callable']);
            self::assertTrue(
                !self::throws(static fn(): mixed => CaseDefinition::load($directory)),
                'a case claiming one channel at two levels loads: that is the pair the level segment leaves behind',
            );

            $write(['a.code@class', 'a.code@class']);
            self::assertTrue(
                self::throws(static fn(): mixed => CaseDefinition::load($directory)),
                'a case claiming one pair twice is refused: the observed set could never satisfy it',
            );

            $write(['a.code']);
            self::assertTrue(
                self::throws(static fn(): mixed => CaseDefinition::load($directory)),
                'and a case claiming a channel with no level is refused when it loads',
            );

            $write(['a.rule#a.code@class']);
            self::assertTrue(
                self::throws(static fn(): mixed => CaseDefinition::load($directory)),
                'a claim still written as a "rule#code" pair is refused: no channel carries that name',
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
