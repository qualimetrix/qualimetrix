<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

final class ParallelConfigurationResolver implements ParallelConfigurationResolverInterface, DocumentSectionSchemaInterface
{
    public function key(): string
    {
        return ConfigSchema::PARALLEL;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::map(['workers' => NodeSchema::scalar(ScalarForm::Integer)->judgedInEachLayer(
            static function (ResolvedValueInterface $value): void {
                self::acceptedWorkers($value);
            },
        )]);
    }

    public function resolve(ConfigurationDocument $document): ParallelConfiguration
    {
        return new ParallelConfiguration(self::acceptedWorkers($document->resolved()->get($this->key(), 'workers')));
    }

    private static function acceptedWorkers(?ResolvedValueInterface $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $workers = $value->plain();
        if ($workers !== null && (!\is_int($workers) || $workers < 0)) {
            $value->refuse('parallel.workers must be a non-negative integer.');
        }

        return $workers;
    }
}
