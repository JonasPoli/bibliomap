<?php

namespace App\Service\KeywordTreatment;

/**
 * O tratamento não pôde começar (outro em andamento ou importação em curso).
 * Nada foi gravado: nenhum processamento foi criado e nenhuma palavra-chave mudou.
 */
class KeywordTreatmentBlockedException extends \RuntimeException
{
    public const MSG_ANOTHER_RUNNING = 'Outro tratamento de palavras-chave está em andamento.';
    public const MSG_IMPORT_RUNNING = 'Importação em andamento: tratamento adiado.';

    public static function anotherRunning(): self
    {
        return new self(self::MSG_ANOTHER_RUNNING);
    }

    public static function importRunning(): self
    {
        return new self(self::MSG_IMPORT_RUNNING);
    }
}
