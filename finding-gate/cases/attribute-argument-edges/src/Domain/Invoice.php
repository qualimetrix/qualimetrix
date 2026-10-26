<?php

namespace Corpus\AttributeArgumentEdges\Domain;

use Corpus\AttributeArgumentEdges\Persistence\InvoiceRepository;

final class Invoice
{
    public function repositoryClass(): string
    {
        return InvoiceRepository::class;
    }
}
