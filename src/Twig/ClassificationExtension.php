<?php

namespace App\Twig;

use Doctrine\DBAL\Connection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ClassificationExtension extends AbstractExtension
{
    public function __construct(private readonly Connection $conn) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_classification_results', [$this, 'hasClassificationResults']),
            new TwigFunction('project_documents_count', [$this, 'projectDocumentsCount']),
        ];
    }

    public function projectDocumentsCount(int $projectId): int
    {
        $sql = 'SELECT COUNT(id) FROM document WHERE project_id = ?';
        return (int) $this->conn->fetchOne($sql, [$projectId]);
    }


    public function hasClassificationResults(int $projectId): bool
    {
        $sql = 'SELECT 1 FROM document_classification WHERE project_id = ? LIMIT 1';
        return (bool) $this->conn->fetchOne($sql, [$projectId]);
    }
}
