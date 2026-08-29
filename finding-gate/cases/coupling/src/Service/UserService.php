<?php

namespace Corpus\Coupling\Service;

use Corpus\Coupling\Contract\CacheInterface;
use Corpus\Coupling\Contract\MailerInterface;
use Corpus\Coupling\Contract\RepositoryInterface;
use Corpus\Coupling\Kernel\Clock;
use Corpus\Coupling\Kernel\Logger;
use Corpus\Coupling\Kernel\Uuid;

class UserService
{
    public function __construct(
        private RepositoryInterface $repository,
        private MailerInterface $mailer,
        private CacheInterface $cache,
        private Clock $clock,
        private Logger $logger,
        private Uuid $uuid,
    ) {}

    public function register(string $email): string
    {
        $this->logger->value();
        $this->cache->handle($email);
        $this->mailer->handle($email);

        return $this->repository->handle($email) . $this->clock->value() . $this->uuid->value();
    }
}
