<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Reporting\FindingProjection\DeclaredChannelFileScope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every capability that declares project-scoped channels is actually asked.
 *
 * A capability can publish `PROJECT_SCOPED_CHANNELS` and be left out of
 * {@see DeclaredChannelFileScope::create()}, which restores the namespace
 * exclusion bypass the declaration exists to close. The unit test beside the
 * assembly cannot see that: it would have to list the declaring capabilities,
 * and an omission would be edited into that list and into the assembly at
 * once. The roll-call has to be read off the tree, so it lives here.
 */
final class ProjectScopedChannelRollCallTest extends TestCase
{
    private const string CONSTANT = 'PROJECT_SCOPED_CHANNELS';

    /** How many declarers the tree is known to carry, so an empty scan cannot pass. */
    private const int KNOWN_DECLARERS = 2;

    #[Test]
    public function itAsksEveryCapabilityThatDeclaresProjectScopedChannels(): void
    {
        $declarers = self::declarers();

        self::assertGreaterThanOrEqual(
            self::KNOWN_DECLARERS,
            \count($declarers),
            'The scan found fewer declarers than the tree is known to carry, so it read nothing.',
        );

        $scope = DeclaredChannelFileScope::create();
        $unasked = [];

        foreach ($declarers as $type => $channels) {
            foreach ($channels as $channel) {
                if ($scope->isFileScoped(new FindingChannel($channel))) {
                    $unasked[] = \sprintf('%s declares %s and the assembled scope does not carry it', $type, $channel);
                }
            }
        }

        self::assertSame([], $unasked, implode("\n", $unasked));
    }

    /**
     * Every production type declaring the constant, with the channels it names.
     *
     * @return array<class-string, list<string>>
     */
    private static function declarers(): array
    {
        $declarers = [];

        foreach (self::sourceFiles() as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, 'const array ' . self::CONSTANT)) {
                continue;
            }

            $type = self::declaredTypeIn($source);

            if ($type === null || !\defined($type . '::' . self::CONSTANT)) {
                continue;
            }

            /** @var list<string> $channels */
            $channels = \constant($type . '::' . self::CONSTANT);
            $declarers[$type] = $channels;
        }

        return $declarers;
    }

    /** @return class-string|null */
    private static function declaredTypeIn(string $source): ?string
    {
        if (
            preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
            || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:interface|class|enum|trait)\s+(\w+)/m', $source, $name) !== 1
        ) {
            return null;
        }

        /** @var class-string $type */
        $type = trim($namespace[1]) . '\\' . $name[1];

        return $type;
    }

    /** @return list<SplFileInfo> */
    private static function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
