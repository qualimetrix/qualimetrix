<?php

namespace Corpus\AttributeArgumentEdges\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public string $repositoryClass,
        public ?object $listener = null,
    ) {}
}
