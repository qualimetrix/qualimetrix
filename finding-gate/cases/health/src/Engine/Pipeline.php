<?php

namespace Corpus\Health\Engine;

use CorpusStore\FileStore;
use CorpusHttp\Client as HttpClient;
use Corpus\Health\Support\Formatter;
use Corpus\Health\Support\Registry;

class Pipeline
{
    private array $stages = [];
    private string $mode = 'default';
    private $errorHandler;

    public function __construct(
        private Dispatcher $dispatcher,
        private Registry $registry,
        private Formatter $formatter,
        private HttpClient $client,
        private FileStore $store,
    ) {}

    public function run($payload, $options)
    {
        $carry = $payload;
        foreach ($this->stages as $stage) {
            if ($options === 'dry') {
                break;
            }
            if (!is_callable($stage)) {
                continue;
            }
            try {
                $carry = $stage($carry);
            } catch (\Throwable $error) {
                if ($this->errorHandler === null) {
                    throw $error;
                }
                $carry = ($this->errorHandler)($error);
            }
            if ($carry === null && $this->mode === 'strict') {
                return $this->formatter->render('aborted');
            }
            if ($this->mode === 'remote') {
                $carry = $this->client->send((string) $carry);
            } elseif ($this->mode === 'archive') {
                $carry = $this->store->write('stage', (string) $carry);
            }
        }

        return $this->dispatcher->dispatch('done', $carry, $options) ?? $this->registry->lookup('done');
    }

    public function addStage($stage): void
    {
        $this->stages[] = $stage;
    }

    public function setMode($mode): void
    {
        $this->mode = $mode;
    }
}
