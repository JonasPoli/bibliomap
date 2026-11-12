<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contador de versão dos dados que alimentam as análises (uma linha só, id = 1).
 * É gravado por SQL direto no DataVersionTracker; a entidade existe para o schema ficar declarado.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_data_version')]
class AnalyticsDataVersion
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = 1;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private string $version = '1';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
