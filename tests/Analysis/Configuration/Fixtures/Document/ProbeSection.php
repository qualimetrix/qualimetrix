<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

/** A section no product owner declares, registered the way an owner registers one. */
final class ProbeSection implements DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration('probe', NodeSchema::map(['depth' => NodeSchema::scalar(ScalarForm::Integer)]));
    }
}
