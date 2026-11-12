<?php

namespace App\Service\Import;

use App\Entity\Dataset;
use Doctrine\DBAL\Connection;

/**
 * Descobre importações interrompidas (processo morto, servidor reiniciado) olhando as travas do MySQL,
 * o mesmo critério da mudança importacao-em-andamento-pela-trava: importação "em andamento" é a que
 * tem a trava do dataset ocupada; status `importing` com a trava livre é importação interrompida.
 */
class ImportQueueInspector
{
    /** Dataset pendente só é dado como parado depois deste tempo (o processo pode estar prestes a pegá-lo). */
    public const PENDING_GRACE_SECONDS = 120;

    public function __construct(private readonly Connection $conn) {}

    /**
     * IDs (em ordem) dos datasets do projeto que ficaram parados: `importing` com a trava do dataset livre,
     * ou `pending` há mais de 2 minutos. Vazio se alguma importação está rodando agora (trava geral ocupada).
     *
     * @return int[]
     */
    public function interruptedDatasetIds(int $projectId): array
    {
        if (!$this->isFree('bibliomap_import')) {
            return []; // há importação rodando: os pendentes estão na fila dela
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, status, created_at FROM dataset WHERE project_id = ? AND status IN (?, ?) ORDER BY id',
            [$projectId, Dataset::STATUS_PENDING, Dataset::STATUS_IMPORTING]
        );

        $limit = time() - self::PENDING_GRACE_SECONDS;
        $ids = [];
        foreach ($rows as $row) {
            if (!$this->isFree('bibliomap_import_dataset_' . $row['id'])) {
                continue;
            }
            if ($row['status'] === Dataset::STATUS_PENDING && strtotime((string) $row['created_at']) > $limit) {
                continue;
            }
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function isFree(string $lockName): bool
    {
        return (int) $this->conn->fetchOne('SELECT IS_FREE_LOCK(?)', [$lockName]) === 1;
    }
}
