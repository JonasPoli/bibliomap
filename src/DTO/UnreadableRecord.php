<?php

namespace App\DTO;

/**
 * Registro que o leitor achou no arquivo mas não conseguiu interpretar. Entra na conta como erro e fica listado
 * no dataset com a posição e o motivo, em vez de sumir sem rastro.
 */
final class UnreadableRecord
{
    public function __construct(
        public readonly int $position,
        public readonly string $message,
        public readonly ?string $externalId = null,
        public readonly ?string $title = null,
    ) {}
}
