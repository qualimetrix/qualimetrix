<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\AuthoredShape;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/**
 * The root configuration file and every file reachable through `imports`, as
 * the layers the composer merges: the C3 linearization of the import graph
 * ({@see ImportLinearization}), lowest precedence first, the root last. Any
 * file read through `imports` may import in turn.
 *
 * One depth-first walk reads each file once and names it by the first chain
 * that reaches it; an entry that leads back to a file still being walked is
 * refused where it is written, naming the cycle. The walk's record is passed
 * down the walk, so one instance serves any number of expansions.
 *
 * Walk keys: `layers` file key => its layer without `imports`; `groups` file key =>
 * its entries as groups of keys, once the file is finished; `positions` importer
 * key => imported key => `imports[N]`.
 *
 * @phpstan-type Walk array{
 *     layers: array<string, AuthoredLayer>,
 *     groups: array<string, list<list<string>>>,
 *     origins: array<string, ConfigurationOrigin>,
 *     positions: array<string, array<string, string>>,
 * }
 */
final readonly class ImportChain
{
    private ImportEntries $entries;

    public function __construct(
        private ConfigLoaderInterface $loader,
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
        $loaded = $this->loader->read($rootPhysicalPath, $rootOrigin);
        $entries = self::entries($loaded->authored, $rootOrigin);
        if ($entries === []) {
            return [new AuthoredLayer($rootOrigin, self::withoutImports($loaded->authored), file: $loaded->file)];
        }

        /** @var Walk $walk */
        $walk = ['layers' => [], 'groups' => [], 'origins' => [], 'positions' => []];
        $root = ImportTarget::ofFile($rootPhysicalPath, $rootOrigin);
        self::admit($walk, $root, $loaded);
        $this->visit($walk, $root, $entries, [$root->key => $rootOrigin]);

        $order = ImportLinearization::of(
            $root->key,
            $walk['groups'],
            static fn(string $key): ConfigurationOrigin => $walk['origins'][$key],
            static fn(string $importer, string $imported): string => $walk['positions'][$importer][$imported],
            ConfigurationRoot::Imports->value,
        );

        return [...array_map(static fn(string $key): AuthoredLayer => $walk['layers'][$key], \array_slice($order, 0, -1)), $walk['layers'][$root->key]];
    }

    /**
     * @param Walk $walk
     * @param list<ResolvedValueInterface> $entries
     * @param array<string, ConfigurationOrigin> $stack the files being walked, root first
     *
     * @throws ConfigurationRefusal
     */
    private function visit(array &$walk, ImportTarget $file, array $entries, array $stack): void
    {
        $groups = [];
        foreach ($this->entries->resolve($entries, $file) as $entry) {
            $keys = [];
            foreach ($entry->targets as $target) {
                if (isset($stack[$target->key])) {
                    self::refuseCycle($entry, $target, $file, $stack);
                }
                $keys[] = $target->key;
                $walk['positions'][$file->key][$target->key] = \sprintf('imports[%d]', $entry->position);
                if (!isset($walk['layers'][$target->key])) {
                    $this->descend($walk, $target, $stack);
                }
            }
            if ($keys !== []) {
                $groups[] = $keys;
            }
        }
        $walk['groups'][$file->key] = $groups;
    }

    /**
     * @param Walk $walk
     * @param array<string, ConfigurationOrigin> $stack
     *
     * @throws ConfigurationRefusal
     */
    private function descend(array &$walk, ImportTarget $target, array $stack): void
    {
        $loaded = $this->loader->read($target->physicalPath, $target->origin);
        self::admit($walk, $target, $loaded);
        $this->visit($walk, $target, self::entries($loaded->authored, $target->origin), $stack + [$target->key => $target->origin]);
    }

    /** @param Walk $walk */
    private static function admit(array &$walk, ImportTarget $file, LoadedDocument $loaded): void
    {
        $walk['origins'][$file->key] = $file->origin;
        $walk['layers'][$file->key] = new AuthoredLayer($file->origin, self::withoutImports($loaded->authored), file: $loaded->file);
    }

    /**
     * @param array<string, ConfigurationOrigin> $stack
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseCycle(ResolvedImportEntry $entry, ImportTarget $target, ImportTarget $from, array $stack): never
    {
        $cycle = [];
        foreach ($stack as $key => $origin) {
            if ($key === $target->key || $cycle !== []) {
                $cycle[] = self::label($origin);
            }
        }
        $cycle[] = self::label($target->origin);

        $entry->value->refuse(\sprintf(
            'Entry imports[%d] (%s) of %s closes an import cycle: %s.',
            $entry->position,
            ImportListSchema::shown(ImportListSchema::written($entry->value)),
            $from->origin->describe(),
            implode(' imports ', $cycle),
        ));
    }

    private static function label(ConfigurationOrigin $origin): string
    {
        return '"' . ($origin->locator() ?? $origin->describe()) . '"';
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
     * The entries a file wrote, judged by the declaration's own grammar on
     * a one-key slice of it, so nothing else in the file is read here.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ResolvedValueInterface>
     */
    private static function entries(AuthoredNode $document, ConfigurationOrigin $origin): array
    {
        if (!self::writesImports($document)) {
            return [];
        }

        $key = ConfigurationRoot::Imports->value;
        $list = DocumentComposer::compose(new DocumentSchema([ConfigurationRoot::Imports]), [
            new AuthoredLayer($origin, AuthoredNode::mapping([$key => $document->children[$key]], $document->line, $document->locator, $document->authoredExpression)),
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
}
