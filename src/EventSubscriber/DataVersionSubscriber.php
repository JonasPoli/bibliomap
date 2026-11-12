<?php

namespace App\EventSubscriber;

use App\Service\Cache\DataVersionTracker;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * No fim de cada requisição e de cada comando, sobe a versão dos dados se o processo gravou
 * em tabela que alimenta as análises (ver DataVersionTracker).
 */
final class DataVersionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DataVersionTracker $tracker)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            ConsoleEvents::ERROR => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->tracker->flush();
    }
}
