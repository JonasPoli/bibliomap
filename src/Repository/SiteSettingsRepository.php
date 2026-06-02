<?php

namespace App\Repository;

use App\Entity\SiteSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteSettings>
 */
class SiteSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteSettings::class);
    }

    /**
     * Always returns the singleton row, creating it if it doesn't exist.
     */
    public function getInstance(): SiteSettings
    {
        $settings = $this->find(SiteSettings::SINGLETON_ID);

        if (!$settings) {
            $settings = new SiteSettings();
            $em = $this->getEntityManager();
            $em->persist($settings);
            $em->flush();
        }

        return $settings;
    }
}
