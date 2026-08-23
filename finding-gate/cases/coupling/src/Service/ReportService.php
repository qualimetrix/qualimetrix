<?php

namespace Corpus\Coupling\Service;

use Corpus\Coupling\Contract\RepositoryInterface;
use Corpus\Coupling\Kernel\Clock;
use Corpus\Coupling\Kernel\Logger;

class ReportService
{
    public function __construct(
        private RepositoryInterface $repository,
        private Clock $clock,
        private Logger $logger,
    ) {}

    public function build(string $scope): string
    {
        $this->logger->value();

        return $this->repository->handle($scope) . $this->clock->value();
    }
}
