<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader implements ConfigLoaderInterface
{
    /** File extensions this loader reads, lowercase; compare a path's extension lowercased. */
    public const array SUPPORTED_EXTENSIONS = ['yaml', 'yml'];

    public function read(string $physicalPath, ConfigurationOrigin $origin): LoadedDocument
    {
        return new LoadedDocument(AuthoredNode::fromPlain($this->parse($physicalPath, $origin)));
    }

    /**
     * The document as its author wrote it: refused only as a whole (missing,
     * unreadable, unparseable, not a mapping) or for a retired root spelling,
     * whose own sentence would otherwise be lost to the engine's unknown-key
     * refusal.
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string|int, mixed>
     */
    private function parse(string $physicalPath, ConfigurationOrigin $origin): array
    {
        $subject = ucfirst($origin->describe());

        if (!file_exists($physicalPath)) {
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s was not found or is not accessible.', $subject));
        }

        if (!is_readable($physicalPath)) {
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s is not readable.', $subject));
        }

        try {
            $content = Yaml::parseFile($physicalPath);
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
