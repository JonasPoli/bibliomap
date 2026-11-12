<?php

namespace App\EventSubscriber;

use App\Service\Authority\AuthorityResolver;
use App\Service\Cache\DataVersionTracker;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * No fim de cada requisição e de cada comando: (1) se o tesauro mudou ("autoridade suja", ver
 * DataVersionTracker), recalcula incrementalmente as colunas effective_* (AuthorityResolver::rebuildAll, o mesmo
 * que `app:authority:rebuild` sem --project); (2) sobe a versão dos dados se o processo gravou em tabela que
 * alimenta as análises.
 *
 * O recálculo NÃO roda com transação aberta (continua sujo para o próximo evento) nem dentro dele mesmo, e suas
 * gravações não marcam nada sujo (sem laço). Falha no recálculo não derruba a requisição: o `--check` do cron
 * e `app:authority:rebuild` apontam e corrigem a divergência.
 */
final class DataVersionSubscriber implements EventSubscriberInterface
{
    private bool $rebuilding = false;

    public function __construct(
        private readonly DataVersionTracker $tracker,
        private readonly AuthorityResolver $resolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::ERROR => 'flush',
        ];
    }

    public function onTerminate(): void
    {
        $this->rebuildIfDirty();
        $this->tracker->flush();
    }

    public function flush(): void
    {
        $this->tracker->flush();
    }

    private function rebuildIfDirty(): void
    {
        if ($this->rebuilding || !$this->tracker->isAuthorityDirty() || $this->tracker->isInTransaction()) {
            return;
        }
        $this->rebuilding = true;
        $this->tracker->clearAuthorityDirty();
        try {
            @set_time_limit(0);
            $this->resolver->rebuildAll();
        } catch (\Throwable) {
            // Colunas ainda não migradas ou conexão perdida: não derruba a requisição
        } finally {
            $this->rebuilding = false;
        }
    }
}
