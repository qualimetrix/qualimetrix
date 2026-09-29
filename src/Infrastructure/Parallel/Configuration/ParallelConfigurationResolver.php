<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\IntegerJudgement;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

final class ParallelConfigurationResolver implements ParallelConfigurationResolverInterface, DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(ConfigSchema::PARALLEL, NodeSchema::map(['workers' => NodeSchema::scalar(ScalarForm::Integer)->judgedInEachLayer(new IntegerJudgement(ParallelConfiguration::workerCountRefusal(...)))]));
    }

    public function resolve(ConfigurationDocument $document): ParallelConfiguration
    {
        $value = $document->resolved()->get(ConfigSchema::PARALLEL, 'workers');
        if ($value === null) {
            return new ParallelConfiguration();
        }

        $workers = $value->plain();
        if (!\is_int($workers)) {
            $value->refuse('parallel.workers must be a non-negative integer.');
        }
        $refusal = ParallelConfiguration::workerCountRefusal($workers);
        if ($refusal !== null) {
            $value->refuse($refusal);
        }

        return new ParallelConfiguration($workers);
    }
}
