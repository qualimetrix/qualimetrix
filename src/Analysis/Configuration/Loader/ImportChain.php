<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Core\FileTarget\FileIdentity;

/**
 * The root configuration file and the files its `imports` list names, as the
 * layers the composer merges: the entries in written order, then the root.
 *
 * Imports are flat: only the root file is read for `imports`, and an imported
 * file writing the key is refused rather than followed. Files are told apart
 * by identity (device, inode, type), never by the path written, so `./a.yaml`,
 * `a.yaml` and a symlink to it are one file.
 */
final class ImportChain
{
    private const int REGULAR_FILE = 0100000;

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
    ) {}

    /**
     * @throws ConfigurationRefusal
     *
     * @return non-empty-list<AuthoredLayer> lowest precedence first; the root's own layer last; none keeps `imports`
     */
    public function expand(string $rootPhysicalPath, ConfigurationOrigin $rootOrigin): array
    {
        $root = $this->loader->read($rootPhysicalPath, $rootOrigin)->authored;
        $entries = self::entries($root, $rootOrigin);
        if ($entries === []) {
            return [new AuthoredLayer($rootOrigin, self::withoutImports($root))];
        }

        $placed = [-1 => [self::identify($rootPhysicalPath, $rootOrigin), (string) $rootOrigin->locator()]];
        $displayDirectory = \dirname($rootOrigin->locator() ?? '.');
        $layers = [];
        foreach ($entries as $index => $entry) {
            $written = (string) $entry->plain();
            $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, self::normalize(self::joined($displayDirectory, $written)))
                ->importedThrough($rootOrigin);
            $physical = self::joined(\dirname($rootPhysicalPath), $written);
            $placed = self::placed($placed, $index, self::identify($physical, $origin), $entry, $rootOrigin);

            $document = $this->loader->read($physical, $origin)->authored;
            if (self::writesImports($document)) {
                throw ConfigurationRefusal::at(
                    $origin,
                    RefusedPosition::open([ConfigurationRoot::Imports->value], ConfigurationRoot::Imports->value),
                    \sprintf(
                        '"imports" in %s is refused: only the root configuration file may import files. List the files it builds on in the "imports" of %s instead.',
                        $origin->describe(),
                        $rootOrigin->describe(),
                    ),
                );
            }

            $layers[] = new AuthoredLayer($origin, $document);
        }
        $layers[] = new AuthoredLayer($rootOrigin, self::withoutImports($root));

        return $layers;
    }

    /**
     * Whether the document's root writes `imports`. The key has one accepted
     * spelling, so the literal key is the only one to look for; any other
     * spelling stays in place for the composer to refuse.
     */
    private static function writesImports(AuthoredNode $document): bool
    {
        return $document->shape === AuthoredShape::Mapping && \array_key_exists(ConfigurationRoot::Imports->value, $document->children);
    }

    /**
     * The entries the root wrote, judged by the declaration's own grammar on
     * a one-key slice of the root, so nothing else in the file is read here.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ResolvedValueInterface>
     */
    private static function entries(AuthoredNode $root, ConfigurationOrigin $origin): array
    {
        if (!self::writesImports($root)) {
            return [];
        }

        $key = ConfigurationRoot::Imports->value;
        $list = DocumentComposer::compose(new DocumentSchema([ConfigurationRoot::Imports]), [
            new AuthoredLayer($origin, AuthoredNode::mapping([$key => $root->children[$key]], $root->line, $root->locator, $root->authoredExpression)),
        ])->get($key);

        return $list instanceof ResolvedListInterface ? $list->items() : [];
    }

    private static function withoutImports(AuthoredNode $document): AuthoredNode
    {
        if (!self::writesImports($document)) {
            return $document;
        }

        $children = $document->children;
        unset($children[ConfigurationRoot::Imports->value]);

        return AuthoredNode::mapping(
            array_combine(array_map('strval', array_keys($children)), $children),
            $document->line,
            $document->locator,
            $document->authoredExpression,
        );
    }

    /**
     * The files placed so far, with this entry's; the root sits at -1.
     *
     * @param array<int, array{FileIdentity, string}> $placed index => identity and the entry as written
     *
     * @throws ConfigurationRefusal
     *
     * @return array<int, array{FileIdentity, string}>
     */
    private static function placed(array $placed, int $index, FileIdentity $identity, ResolvedValueInterface $entry, ConfigurationOrigin $rootOrigin): array
    {
        $written = (string) $entry->plain();
        foreach ($placed as $earlier => [$earlierIdentity, $earlierWritten]) {
            if (!$identity->sameAs($earlierIdentity)) {
                continue;
            }

            $entry->refuse($earlier === -1
                ? \sprintf('Entry imports[%d] ("%s") of %s names that file itself; a file cannot import itself.', $index, $written, $rootOrigin->describe())
                : \sprintf(
                    'Entries imports[%d] ("%s") and imports[%d] ("%s") of %s name the same file; list each file once.',
                    $earlier,
                    $earlierWritten,
                    $index,
                    $written,
                    $rootOrigin->describe(),
                ));
        }
        $placed[$index] = [$identity, $written];

        return $placed;
    }

    /**
     * Refused before the file is read: a target that cannot be stat'ed or
     * reports inode 0 has no identity to compare, and a directory or a FIFO
     * would fail or block the read.
     *
     * @throws ConfigurationRefusal
     */
    private static function identify(string $physicalPath, ConfigurationOrigin $origin): FileIdentity
    {
        // @qmx-ignore-next-line code-smell.error-suppression -- A missing target is refused below by name; the interpreter warning must not reach the report.
        $stat = @stat($physicalPath);
        $identity = $stat === false ? null : FileIdentity::fromStat($stat);
        $problem = match (true) {
            $identity === null => 'was not found',
            $identity->type !== self::REGULAR_FILE => 'is not a regular file',
            $identity->inode === 0 => 'reports no file identity (inode 0), so it cannot be told apart from the other files',
            default => null,
        };

        return $problem === null && $identity !== null
            ? $identity
            : throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s %s.', ucfirst($origin->describe()), $problem ?? 'was not found'));
    }

    private static function joined(string $directory, string $entry): string
    {
        return $directory === '.' || str_starts_with($entry, '/') ? $entry : rtrim($directory, '/') . '/' . $entry;
    }

    /**
     * Lexical: `.` and `x/..` collapse without consulting the filesystem, so
     * the name shown can differ from the file opened when a directory on the
     * way is a symlink.
     */
    private static function normalize(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $kept = [];
        foreach (array_diff(explode('/', $path), ['', '.']) as $segment) {
            if ($segment === '..' && $kept !== [] && end($kept) !== '..') {
                array_pop($kept);
            } elseif ($segment !== '..' || !$absolute) {
                $kept[] = $segment;
            }
        }

        return ($absolute ? '/' : '') . implode('/', $kept);
    }
}
