<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Version;

/**
 * The `project` object of the HTML report: which project it is about, when and
 * by which qmx version it was generated, and where the documentation lives.
 *
 * `docs` and `llmsTxt` reach the footer the way `qmxVersion` already does:
 * through this data structure, because the footer is written by JavaScript,
 * which cannot read a PHP constant directly.
 *
 * @internal
 */
final class HtmlProjectMetadata
{
    /**
     * @param ?string $projectName the `project-name` format option, when given
     * @param string $projectRoot the analysed project's root
     *
     * @return array<string, mixed>
     */
    public static function of(bool $scopedReporting, ?string $projectName, string $projectRoot): array
    {
        return [
            'name' => $projectName ?? self::analysedProjectName($projectRoot),
            'generatedAt' => gmdate('c'),
            'qmxVersion' => Version::get(),
            'scopedReporting' => $scopedReporting,
            'docs' => ProductIdentity::docsUrl(),
            'llmsTxt' => ProductIdentity::llmsTxtUrl(),
        ];
    }

    /**
     * The analysed project's name: its `composer.json` `name`, else its root
     * directory's name.
     *
     * Not the Composer runtime's root package — that is whichever project
     * loaded qmx, which under a phar, a global install or a qmx checkout is
     * qmx itself, whatever is being analysed.
     */
    private static function analysedProjectName(string $projectRoot): string
    {
        if ($projectRoot === '') {
            return 'unknown';
        }

        $manifest = rtrim($projectRoot, '/') . '/composer.json';
        $contents = is_file($manifest) ? file_get_contents($manifest) : false;
        $decoded = \is_string($contents) ? json_decode($contents, true) : null;
        $name = \is_array($decoded) ? ($decoded['name'] ?? null) : null;

        if (\is_string($name) && $name !== '') {
            return $name;
        }

        $directory = basename(rtrim($projectRoot, '/'));

        return $directory !== '' ? $directory : 'unknown';
    }
}
