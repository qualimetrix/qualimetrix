<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

final readonly class CacheSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'cache';
    public const string DEFAULT_DIRECTORY = '.qmx-cache';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(self::KEY, NodeSchema::map([
            'dir' => NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(static function (ResolvedValueInterface $directory): void {
                self::acceptedDirectory($directory);
            }),
            'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
        ]));
    }

    public static function acceptedDirectory(ResolvedValueInterface $directory): string
    {
        $candidate = $directory->plain();
        if (!\is_string($candidate)) {
            $directory->refuse(\sprintf(
                'Invalid value for "%s": expected a directory path, got %s.',
                ConfigSchema::CACHE_DIR,
                get_debug_type($candidate),
            ));
        }
        if ($candidate === '') {
            $directory->refuse(\sprintf(
                'Invalid value for "%s": a directory path cannot be empty. Omit the key to use the default (%s).',
                ConfigSchema::CACHE_DIR,
                self::DEFAULT_DIRECTORY,
            ));
        }

        return $candidate;
    }

    public static function directory(?ResolvedValueInterface $configured): string
    {
        return $configured === null ? self::DEFAULT_DIRECTORY : self::acceptedDirectory($configured);
    }

    public static function enabled(?ResolvedValueInterface $configured): bool
    {
        if ($configured === null) {
            return true;
        }

        $enabled = $configured->plain();
        if (!\is_bool($enabled)) {
            $configured->refuse('Cache enabled must be a boolean.');
        }

        return $enabled;
    }
}
