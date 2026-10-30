<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/**
 * The root configuration file and the files its `imports` list names, as the
 * layers the composer merges: the entries in written order (a glob's matches
 * in byte order), then the root.
 *
 * Imports are flat: only the root file is read for `imports`, and an imported
 * file writing the key is refused rather than followed.
 */
final class ImportChain
{
    private readonly ImportEntries $entries;

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
    ) {
        $this->entries = new ImportEntries();
    }

    /**
     * @throws ConfigurationRefusal
     *
     * @return non-empty-list<AuthoredLayer> lowest precedence first; the root's own layer last; none keeps `imports`
     */
    public function expand(string $rootPhysicalPath, ConfigurationOrigin $rootOrigin): array
    {
        $loadedRoot = $this->loader->read($rootPhysicalPath, $rootOrigin);
        $root = $loadedRoot->authored;
        $entries = self::entries($root, $rootOrigin);
        if ($entries === []) {
            return [new AuthoredLayer($rootOrigin, self::withoutImports($root), file: $loadedRoot->file)];
        }

        $directory = self::realDirectory($rootPhysicalPath, $rootOrigin);
        $from = new ImportingFile(
            $rootOrigin,
            $directory,
            is_link($rootPhysicalPath) ? $directory : \dirname($rootOrigin->locator() ?? '.'),
            ImportTarget::ofFile($rootPhysicalPath, $rootOrigin)->key,
        );
        $layers = [];
        foreach ($this->entries->resolve($entries, $from) as $entry) {
            foreach ($entry->targets as $target) {
                $loaded = $this->loader->read($target->physicalPath, $target->origin);
                $document = $loaded->authored;
                if (self::writesImports($document)) {
                    throw ConfigurationRefusal::at(
                        $target->origin,
                        RefusedPosition::open([ConfigurationRoot::Imports->value], ConfigurationRoot::Imports->value),
                        \sprintf(
                            '"imports" in %s is refused: only the root configuration file may import files. List the files it builds on in the "imports" of %s instead.',
                            $target->origin->describe(),
                            $rootOrigin->describe(),
                        ),
                    );
                }

                $layers[] = new AuthoredLayer($target->origin, $document, file: $loaded->file);
            }
        }
        $layers[] = new AuthoredLayer($rootOrigin, self::withoutImports($root), file: $loadedRoot->file);

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
     * The directory entries resolve against: the root file's own, through a
     * symlink to it, so a configuration linked into a project reads the files
     * beside its target. An entry is named from the root's name, which leads
     * to the same file because `..` is kept as written — unless the root is
     * itself a symlink, whose name leads to the link's directory: its imports
     * are then named by this directory.
     *
     * @throws ConfigurationRefusal
     */
    private static function realDirectory(string $rootPhysicalPath, ConfigurationOrigin $rootOrigin): string
    {
        $real = realpath($rootPhysicalPath);

        return $real === false
            ? throw ConfigurationRefusal::aboutDocument($rootOrigin, \sprintf('%s cannot be resolved to a file path.', ucfirst($rootOrigin->describe())))
            : \dirname($real);
    }
}
