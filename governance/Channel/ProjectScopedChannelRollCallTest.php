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
 *
 * **A scan that reads half the tree is the same defect wearing the scan's
 * clothes.** Every way of failing to read a file used to be a `continue`: a
 * declaration spelled without its type, a namespace the pattern did not
 * parse, a constant reflection could not see. The count floor below catches
 * "the scan read nothing" and cannot catch "the scan read two of three" —
 * exactly the invisible denominator this control exists to replace. So every
 * file that *names* the constant is accounted for: it declares it readably, or
 * it is one of the named non-declaring mentions, or the run says so by name.
 */
final class ProjectScopedChannelRollCallTest extends TestCase
{
    private const string CONSTANT = 'PROJECT_SCOPED_CHANNELS';

    /**
     * How many declarers the tree is known to carry.
     *
     * This is the floor for "the scan died altogether", and nothing else: it
     * is equal to the number of declarers today, so it carries no slack and a
     * fourth declarer arriving unread is caught by
     * {@see itReadsEveryFileThatNamesTheConstant}, not by this number.
     */
    private const int KNOWN_DECLARERS = 2;

    /**
     * Files that name the constant without declaring one, each with the reason.
     *
     * Hand-written, and checked by {@see itCarriesNoStaleNonDeclaringMentionRow}
     * against the only thing that makes a row worth carrying: the scan must
     * have refused this file and been answered by this row. Every way a row can
     * stop doing that is the same refusal — the file is gone, it stopped naming
     * the constant, it has since started declaring one, it sits outside the
     * scanned root, or the scan simply never reaches it. The last one is why
     * "the file still names the constant" is not the test: `src/Core/README.md`
     * names it too, exists, declares nothing and sits under `src/`, and a row
     * for it would satisfy every one of those conditions while excusing
     * nothing, because the scan reads `.php` files only. A list that can grow
     * by rows with no subject is longer than its subject, and its length stops
     * meaning anything.
     *
     * A file that arrives here unlisted and unread is a refusal too — which is
     * what keeps this list from becoming the silent `continue` it replaced.
     *
     * @var array<string, string>
     */
    private const array NON_DECLARING_MENTIONS = [
        'src/Analysis/Finding/Contract/Filter/ChannelFileScope.php'
            => 'a {@see} in the docblock of the type the declarations are assembled into',
        'src/Analysis/Policy/Architecture/LayerViolation/DeclaredLayerReachability.php'
            => 'a {@see} in a docblock explaining what one capability declares about one channel',
        'src/Reporting/FindingProjection/DeclaredChannelFileScope.php'
            => 'the assembly under test: it spreads every declaration into the scope',
    ];

    #[Test]
    public function itAsksEveryCapabilityThatDeclaresProjectScopedChannels(): void
    {
        $declarers = self::scan()['declarers'];

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
     * The denominator, made loud.
     *
     * A file naming the constant is a declaration the roll-call has to read or
     * a mention someone has accounted for. Anything else means the scan is
     * narrower than the tree and says nothing about it.
     */
    #[Test]
    public function itReadsEveryFileThatNamesTheConstant(): void
    {
        $unread = self::scan()['unread'];

        self::assertSame([], $unread, implode("\n", $unread));
    }

    /**
     * A row earns its place by answering a refusal, and by nothing else.
     *
     * The scan reports which rows it consulted, so the question is asked in the
     * one direction that cannot be satisfied by a coincidence: a row the scan
     * never consulted excuses nothing, whatever else is true of the file it
     * names. The narrower diagnoses below only say *why* a given row went
     * unconsulted, and the last branch is the one a checklist of those
     * conditions cannot reach.
     */
    #[Test]
    public function itCarriesNoStaleNonDeclaringMentionRow(): void
    {
        $root = self::sourceRoot();
        $consulted = self::scan()['consulted'];
        $stale = [];

        foreach (self::NON_DECLARING_MENTIONS as $path => $reason) {
            if (\in_array($path, $consulted, true)) {
                continue;
            }

            $absolute = \dirname(__DIR__, 2) . '/' . $path;

            if (!is_file($absolute)) {
                $stale[] = \sprintf('%s is excused as "%s" and is not there any more', $path, $reason);

                continue;
            }

            $source = (string) file_get_contents($absolute);

            if (!str_contains($source, self::CONSTANT)) {
                $stale[] = \sprintf('%s is excused as "%s" and no longer names the constant', $path, $reason);

                continue;
            }

            if (self::declaresTheConstant($source)) {
                $stale[] = \sprintf('%s is excused as "%s" and now declares the constant', $path, $reason);

                continue;
            }

            if (!str_starts_with($absolute, $root)) {
                $stale[] = \sprintf('%s is excused and is outside the scanned root', $path);

                continue;
            }

            $stale[] = \sprintf(
                '%s is excused as "%s" and the scan never consults the row: it produces no refusal for this path,'
                . ' so the excuse answers nothing. The scan reads `.php` files under src/, and prose naming the'
                . ' constant — or any path it spells differently — never reaches it. Drop the row.',
                $path,
                $reason,
            );
        }

        self::assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Every file under `src/` that names the constant, split into what the
     * roll-call could read and what it could not.
     *
     * `consulted` names the rows of {@see NON_DECLARING_MENTIONS} this scan
     * actually leaned on. It is reported rather than recomputed because the
     * only honest definition of a useful row is the one written here: the row
     * the scan asked for instead of refusing.
     *
     * @return array{declarers: array<class-string, list<string>>, unread: list<string>, consulted: list<string>}
     */
    private static function scan(): array
    {
        $declarers = [];
        $unread = [];
        $consulted = [];

        foreach (self::sourceFiles() as $file) {
            $relative = substr($file->getPathname(), \strlen(\dirname(__DIR__, 2)) + 1);
            $source = (string) file_get_contents($file->getPathname());

            if (!str_contains($source, self::CONSTANT)) {
                continue;
            }

            if (!self::declaresTheConstant($source)) {
                if (isset(self::NON_DECLARING_MENTIONS[$relative])) {
                    $consulted[] = $relative;
                } else {
                    $unread[] = \sprintf(
                        '%s names %s and declares no constant the scan can recognise. Either it declares one in a'
                        . ' spelling this control cannot read — which would drop a capability from the roll-call'
                        . ' silently — or it mentions the name for some other reason, which belongs in'
                        . ' NON_DECLARING_MENTIONS with its reason.',
                        $relative,
                        self::CONSTANT,
                    );
                }

                continue;
            }

            $type = self::declaredTypeIn($source);

            if ($type === null) {
                $unread[] = \sprintf(
                    '%s declares %s and the scan cannot read the type that carries it, so its channels never reach'
                    . ' the roll-call.',
                    $relative,
                    self::CONSTANT,
                );

                continue;
            }

            if (!\defined($type . '::' . self::CONSTANT)) {
                $unread[] = \sprintf(
                    '%s declares %s on %s and the constant is not visible to reflection, so its channels never reach'
                    . ' the roll-call.',
                    $relative,
                    self::CONSTANT,
                    $type,
                );

                continue;
            }

            /** @var list<string> $channels */
            $channels = \constant($type . '::' . self::CONSTANT);
            $declarers[$type] = $channels;
        }

        return ['declarers' => $declarers, 'unread' => $unread, 'consulted' => $consulted];
    }

    /**
     * Whether the source declares the constant, rather than naming it.
     *
     * Deliberately tolerant of everything PHP allows between `const` and the
     * name — visibility, `final`, and the type the current declarations write
     * — because a declaration this pattern misses is a capability dropped from
     * the roll-call. What it must not match is `{@see Foo::PROJECT_...}` or
     * `...Foo::PROJECT_...` in an expression, which is why the name has to be
     * preceded by `const` and followed by an assignment.
     */
    private static function declaresTheConstant(string $source): bool
    {
        return preg_match(
            '/(?:^|[\s(])const\s+(?:[\w\\\\|?]+\s+)?' . self::CONSTANT . '\s*=/m',
            $source,
        ) === 1;
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

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }

    /** @return list<SplFileInfo> */
    private static function sourceFiles(): array
    {
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
