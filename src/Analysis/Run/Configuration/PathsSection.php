<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

final class PathsSection implements DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(ConfigSchema::PATHS, NodeSchema::list(
            NodeSchema::scalar(ScalarForm::String)->withHint('Quote a name that reads as a number or a keyword ("2024", "true").'),
        )->judgedInEachLayer(static function (ResolvedValueInterface $value): void {
            \assert($value instanceof ResolvedListInterface);
            self::read($value);
        }));
    }

    /**
     * The paths a layer wrote, or the resolved list: the same context-independent
     * constraints apply before and after composition.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<string>
     */
    public static function read(ResolvedListInterface $written): array
    {
        if ($written->items() === []) {
            $written->refuse(\sprintf(
                'Invalid value for "%s": the list is empty, so this run would analyse nothing. Name at least one'
                . ' path, or omit the key to analyse the working directory.',
                ConfigSchema::PATHS,
            ));
        }

        $paths = [];
        foreach ($written->items() as $item) {
            $path = $item->plain();
            if ($path === '') {
                $item->refuse(\sprintf(
                    'Invalid entry in "%s": a path cannot be empty. Name a directory or a file, or omit the key to analyse the working directory.',
                    ConfigSchema::PATHS,
                ));
            }

            $paths[] = (string) $path;
        }

        return $paths;
    }

}
