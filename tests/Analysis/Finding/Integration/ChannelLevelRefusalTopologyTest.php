<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * An impossible `channel:level` pair is refused in exactly one place.
 *
 * Two seams have to refuse it — configuration and CLI, which end the run
 * before analysis starts, and the inline directives, which report
 * `annotation.unresolved-directive` — and they used to have no shared point at
 * all: {@see \Qualimetrix\Infrastructure\Console\ChannelExclusionKeyValidator}
 * throws but only on the option whose key is a channel, and
 * {@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability}
 * reports but only on a target that already parsed. Two seams answering
 * separately is two answers to one mistake.
 *
 * The check is a topology one rather than a behavioural one, because "the same
 * answer" cannot be asserted from two green examples: a second implementation
 * agreeing today is what drift starts as. What is asserted instead is that
 * only {@see ChannelLevelAddressing} asks the universe which levels a channel
 * declares — {@see ChannelIdentityInterface::levelsOf()} is the one question a
 * second refusal would have to ask, and a caller of it anywhere else is a
 * second refusal being written.
 *
 * The detector is held against its own subject: the one legitimate caller must
 * be found, or an empty offender list means the scan stopped recognising
 * anything.
 */
#[CoversClass(ChannelLevelAddressing::class)]
final class ChannelLevelRefusalTopologyTest extends TestCase
{
    /** The declaring interface and its adapter answer the question; they do not ask it. */
    private const array DECLARING_FILES = [
        'src/Analysis/Finding/Contract/ChannelIdentityInterface.php',
        'src/Infrastructure/Rule/ChannelUniverse.php',
    ];

    private const string SOLE_CALLER = 'src/Analysis/Finding/Contract/Rule/ChannelLevelAddressing.php';

    #[Test]
    public function onlyOneProductionClassAsksWhichLevelsAChannelDeclares(): void
    {
        $callers = self::callers();

        self::assertContains(
            self::SOLE_CALLER,
            $callers,
            'The one legitimate caller was not found, so an empty offender list would prove nothing.',
        );
        self::assertSame(
            [self::SOLE_CALLER],
            $callers,
            'A second place asking which levels a channel declares is a second refusal for an impossible'
            . ' channel:level pair. Both the configuration seam and the inline-directive seam must ask'
            . ' ChannelLevelAddressing, so that one mistake cannot get two answers.',
        );
    }

    /**
     * @return list<string>
     */
    private static function callers(): array
    {
        $root = \dirname(__DIR__, 4);
        $callers = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $relative = 'src' . substr($entry->getPathname(), \strlen($root . '/src'));

            if (\in_array($relative, self::DECLARING_FILES, true)) {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());

            if ($contents === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $relative));
            }

            if (str_contains($contents, '->levelsOf(')) {
                $callers[] = $relative;
            }
        }

        sort($callers);

        return $callers;
    }
}
