<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Observation;
use Qualimetrix\PromiseEffect\ProcessProbe;
use Qualimetrix\PromiseEffect\Stand;

/**
 * The unframed half of the refusal-framing control is a stand-owned process,
 * read through the probe that reads every product run. What it has to prove is
 * that the frame, and nothing else, moves the outcome.
 */
final class RefusalFramingWitnessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/promise-effect/InProcess.php';
        require_once $scripts . '/promise-effect/ProcessProbe.php';
        require_once $scripts . '/promise-effect/Stand.php';
    }

    #[Test]
    public function itReadsTheWitnessAsAnUnframedRefusalAndTheControlHoldsOnIt(): void
    {
        $witness = self::probe()->observeCommand(Stand::refusalWitness(Stand::UNFRAMED_WITNESS_MESSAGE));

        self::assertSame(3, $witness->exit);
        self::assertSame(Observation::REFUSED_UNFRAMED, $witness->outcome());
        self::assertSame(
            [],
            Stand::framingProblems(Observation::REFUSED_FRAMED, 'framed', $witness->outcome(), $witness->text()),
        );
    }

    #[Test]
    public function itReadsTheSameEnvelopeWithTheFrameAsAFramedRefusal(): void
    {
        // Same process, same exit, same envelope — only the frame added. A
        // probe that told the two apart by anything but the frame would pass
        // the case above and fail this one.
        $framed = self::probe()->observeCommand(
            Stand::refusalWitness('Configuration error: ' . Stand::UNFRAMED_WITNESS_MESSAGE),
        );

        self::assertSame(3, $framed->exit);
        self::assertSame(Observation::REFUSED_FRAMED, $framed->outcome());
        self::assertNotSame(
            [],
            Stand::framingProblems(Observation::REFUSED_FRAMED, 'framed', $framed->outcome(), $framed->text()),
        );
    }

    #[Test]
    public function itDoesNotCountTheWitnessAsAProductRun(): void
    {
        $probe = self::probe();
        $probe->observeCommand(Stand::refusalWitness(Stand::UNFRAMED_WITNESS_MESSAGE));

        self::assertSame(0, $probe->runs());
    }

    private static function probe(): ProcessProbe
    {
        // Never created: observeCommand() touches no scratch directory, and
        // the path is only a prefix the probe tokenizes.
        return new ProcessProbe(
            \dirname(__DIR__, 3),
            sys_get_temp_dir() . '/promise-effect-witness-' . bin2hex(random_bytes(6)),
        );
    }
}
