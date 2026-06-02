<?php

namespace App\Service;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class SiteSettingsService
{
    public function __construct(
        private readonly SiteSettingsRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface       $slugger,
        private readonly string                 $uploadDir,
    ) {}

    public function get(): SiteSettings
    {
        return $this->repo->getInstance();
    }

    /**
     * Saves settings and optionally handles the OG image upload.
     */
    public function save(SiteSettings $settings, ?UploadedFile $imageFile): void
    {
        if ($imageFile) {
            $oldImage = $settings->getOgImage();

            $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename     = $this->slugger->slug($originalFilename);
            $extension        = $imageFile->guessExtension() ?? 'jpg';
            $newFilename      = $safeFilename . '-' . uniqid() . '.' . $extension;

            $imageFile->move($this->uploadDir, $newFilename);

            // Remove old file
            if ($oldImage && file_exists($this->uploadDir . '/' . $oldImage)) {
                @unlink($this->uploadDir . '/' . $oldImage);
            }

            $settings->setOgImage($newFilename);
        }

        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * Removes the current OG image from disk and DB.
     */
    public function removeOgImage(SiteSettings $settings): void
    {
        $filename = $settings->getOgImage();
        if ($filename && file_exists($this->uploadDir . '/' . $filename)) {
            @unlink($this->uploadDir . '/' . $filename);
        }
        $settings->setOgImage(null);
        $this->em->flush();
    }
}
