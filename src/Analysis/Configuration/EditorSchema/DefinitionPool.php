<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

use LogicException;

/**
 * Subtrees named by a hash of their content, so a name survives sections being
 * added or reordered. A subtree used more than once is written once under
 * `definitions`; one used once stays in place.
 *
 * A description belongs to the place a subtree is used, not to the subtree:
 * beside a reference it goes through `allOf`, because draft-07 ignores every
 * sibling of `$ref`.
 */
final class DefinitionPool
{
    private const int NAME_LENGTH = 12;

    /** @var array<string, array<string, mixed>> */
    private array $bodies = [];

    /** @var array<string, string> name => fingerprint */
    private array $fingerprints = [];

    /** @param array<string, mixed> $body */
    public function node(array $body, ?string $description): PooledNode
    {
        $fingerprint = self::fingerprint($body);
        $name = substr(hash('sha256', $fingerprint), 0, self::NAME_LENGTH);

        if (isset($this->fingerprints[$name]) && $this->fingerprints[$name] !== $fingerprint) {
            throw new LogicException(\sprintf('Two different subtrees share the definition name "%s".', $name));
        }

        $this->bodies[$name] = $body;
        $this->fingerprints[$name] = $fingerprint;

        return new PooledNode($name, $description);
    }

    /**
     * The schema with every placeholder resolved and the shared subtrees
     * appended under `definitions`, sorted by name.
     *
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>
     */
    public function resolve(array $root): array
    {
        $uses = self::uses($root, []);
        foreach ($this->bodies as $body) {
            $uses = self::uses($body, $uses);
        }

        $shared = array_filter($uses, static fn(int $count): bool => $count > 1);
        ksort($shared, \SORT_STRING);

        $resolved = $this->placed($root, $shared);
        \assert(\is_array($resolved));

        foreach (array_keys($shared) as $name) {
            $resolved['definitions'][$name] = $this->placed($this->bodies[$name], $shared);
        }

        return $resolved;
    }

    /**
     * @param array<string, int> $uses
     *
     * @return array<string, int>
     */
    private static function uses(mixed $value, array $uses): array
    {
        if ($value instanceof PooledNode) {
            $uses[$value->name] = ($uses[$value->name] ?? 0) + 1;
        } elseif (\is_array($value)) {
            foreach ($value as $child) {
                $uses = self::uses($child, $uses);
            }
        }

        return $uses;
    }

    /** @param array<string, int> $shared */
    private function placed(mixed $value, array $shared): mixed
    {
        if (\is_array($value)) {
            return array_map(fn(mixed $child): mixed => $this->placed($child, $shared), $value);
        }

        if (!$value instanceof PooledNode) {
            return $value;
        }

        $schema = isset($shared[$value->name])
            ? ['$ref' => '#/definitions/' . $value->name]
            : $this->placed($this->bodies[$value->name], $shared);
        \assert(\is_array($schema));

        return match (true) {
            $value->description === null => $schema,
            isset($schema['$ref']), isset($schema['description']) => ['description' => $value->description, 'allOf' => [$schema]],
            default => ['description' => $value->description] + $schema,
        };
    }

    /** @param array<string, mixed> $body */
    private static function fingerprint(array $body): string
    {
        return json_encode(self::comparable($body), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function comparable(mixed $value): mixed
    {
        if ($value instanceof PooledNode) {
            return ['pooled' => $value->name, 'description' => $value->description];
        }

        return \is_array($value) ? array_map(self::comparable(...), $value) : $value;
    }
}
