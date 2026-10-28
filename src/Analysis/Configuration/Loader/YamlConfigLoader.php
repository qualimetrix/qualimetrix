<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    /** File extensions this loader reads, lowercase; compare a path's extension lowercased. */
    public const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    public function read(string $physicalPath, ConfigurationOrigin $origin): LoadedDocument
    {
        [$bytes, $file] = self::readIdentified($physicalPath, $origin);

        return new LoadedDocument(AuthoredNode::fromPlain($this->parse($bytes, $physicalPath, $origin)), $file);
    }

    /**
     * The bytes and the identity of the one file they came from, through one
     * handle: a path stat'ed apart from the read can name a file replaced in
     * between.
     * A file read through another stream wrapper than the plain filesystem,
     * such as the phar holding a built-in preset, reports a synthetic
     * identity that a real file could equal, so it gets none.
     *
     * @throws ConfigurationRefusal
     *
     * @return array{string, ?FileIdentity}
     */
    private static function readIdentified(string $physicalPath, ConfigurationOrigin $origin): array
    {
        self::assertReadableFile($physicalPath, $origin);
        [$handle] = NativeCall::attempt(static fn(): mixed => fopen($physicalPath, 'rb'));
        if (!\is_resource($handle)) {
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s is not readable.', ucfirst($origin->describe())));
        }

        try {
            [$bytes] = NativeCall::attempt(static fn(): string|false => stream_get_contents($handle));

            return \is_string($bytes)
                ? [$bytes, self::identityOf($handle)]
                : throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s is not readable.', ucfirst($origin->describe())));
        } finally {
            fclose($handle);
        }
    }

    /** @throws ConfigurationRefusal */
    private static function assertReadableFile(string $physicalPath, ConfigurationOrigin $origin): void
    {
        $problem = match (true) {
            !file_exists($physicalPath) => 'was not found or is not accessible',
            !is_readable($physicalPath) => 'is not readable',
            !is_file($physicalPath) => 'is not a regular file',
            default => null,
        };
        if ($problem !== null) {
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s %s.', ucfirst($origin->describe()), $problem));
        }
    }

    /** @param resource $handle */
    private static function identityOf($handle): ?FileIdentity
    {
        if (stream_get_meta_data($handle)['wrapper_type'] !== 'plainfile') {
            return null;
        }
        [$stat] = NativeCall::attempt(static fn(): array|false => fstat($handle));
        $identity = $stat === false ? null : FileIdentity::fromStat($stat);

        return $identity?->inode === 0 ? null : $identity;
    }

    /**
     * The document as its author wrote it: refused only as a whole
     * (unparseable, not a mapping) or for a retired root spelling,
     * whose own sentence would otherwise be lost to the engine's unknown-key
     * refusal.
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string|int, mixed>
     */
    private function parse(string $bytes, string $physicalPath, ConfigurationOrigin $origin): array
    {
        $subject = ucfirst($origin->describe());

        try {
            $content = Yaml::parse($bytes);
        } catch (ParseException $e) {
            $e->setParsedFile($origin->locator() ?? $physicalPath);
            throw ConfigurationRefusal::aboutDocument(
                $origin,
                \sprintf('Failed to parse %s: %s', $origin->describe(), $e->getMessage()),
                $e,
            );
        }

        if (!\is_array($content)) {
            if ($content === null) {
                // Empty file is valid, return empty config
                return [];
            }
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s is not valid YAML format.', $subject));
        }

        $keyMap = $this->buildRootKeyMap($content);
        RetiredSuppressionOptions::refuseRootKey(
            array_values(array_diff(array_keys($keyMap), ConfigSchema::allowedRootKeys())),
            $origin,
            $keyMap,
        );

        return $content;
    }

    /**
     * Builds a map of normalizedKey → originalKey for root-level keys.
     *
     * Used to show the user's original key names in error messages,
     * even though validation runs on normalized (camelCase) keys.
     *
     * @param array<string|int, mixed> $config Raw YAML config
     *
     * @return array<string, string> normalizedKey → originalKey
     */
    private function buildRootKeyMap(array $config): array
    {
        $map = [];

        foreach (array_keys($config) as $originalKey) {
            $map[ConfigKeySpelling::normalize((string) $originalKey)] = (string) $originalKey;
        }

        return $map;
    }
}
