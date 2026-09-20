<?php

declare(strict_types=1);

namespace Fixtures\TemplateCriteriaSample\Module\Order\Domain;

use Fixtures\TemplateCriteriaSample\Shared\AggregateRoot;
use Fixtures\TemplateCriteriaSample\Shared\AsEntity;
use Fixtures\TemplateCriteriaSample\Shared\HasIdentity;

#[AsEntity]
final class Order extends AggregateRoot implements HasIdentity {}
