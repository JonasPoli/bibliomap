<?php

namespace App\Service\DatabaseBackup;

/** O backup não pôde ser feito; a mensagem nunca traz a credencial do banco. */
class DatabaseBackupException extends \RuntimeException
{
}
