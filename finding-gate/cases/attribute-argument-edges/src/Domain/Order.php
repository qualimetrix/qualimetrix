<?php

namespace Corpus\AttributeArgumentEdges\Domain;

use Corpus\AttributeArgumentEdges\Mapping\Entity;
use Corpus\AttributeArgumentEdges\Persistence\AuditListener;
use Corpus\AttributeArgumentEdges\Persistence\OrderRepository;

#[Entity(repositoryClass: OrderRepository::class, listener: new AuditListener())]
final class Order
{
}
