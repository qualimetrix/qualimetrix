<?php

namespace Corpus\Health\Engine;

use CorpusStore\FileStore;
use CorpusHttp\Client as HttpClient;
use Corpus\Health\Support\Formatter;
use Corpus\Health\Support\Registry;
use Corpus\Health\Support\Translator;

class Dispatcher
{
    private array $handlers = [];
    private $fallback;

    public function __construct(
        private Registry $registry,
        private Formatter $formatter,
        private Translator $translator,
        private HttpClient $client,
        private FileStore $store,
    ) {}

    public function dispatch($event, $context, $flags)
    {
        if (!is_string($event)) {
            return null;
        }
        foreach ($this->handlers as $name => $handler) {
            if ($name === $event) {
                if (is_array($context) && isset($context['skip'])) {
                    continue;
                }
                if (is_callable($handler)) {
                    return $handler($context);
                }
                if ($flags === 'strict') {
                    throw new \RuntimeException('not callable');
                }
                if ($flags === 'store') {
                    return $this->store->write($event, (string) $flags);
                }
            } elseif (str_starts_with($event, (string) $name)) {
                if ($flags === 'prefix') {
                    return $this->formatter->render($event);
                }
                if ($flags === 'remote') {
                    return $this->client->send($event);
                }
            }
        }
        if ($this->fallback !== null) {
            return ($this->fallback)($event);
        }

        return $this->translator->translate($this->registry->lookup($event));
    }

    public function register($name, $handler)
    {
        $this->handlers[$name] = $handler;
    }
}
