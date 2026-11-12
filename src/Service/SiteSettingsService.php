<?php

namespace App\Service;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class SiteSettingsService
{
    /** Tipos aceitos para a imagem OG: tipo detectado no conteúdo => extensão gravada. */
    public const OG_IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public const OG_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

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
            if (!$imageFile->isValid()) {
                throw new \InvalidArgumentException('O envio da imagem falhou: ' . $imageFile->getErrorMessage());
            }
            // A pasta fica dentro de public/: só entra imagem de verdade e a extensão vem da lista,
            // nunca do arquivo (um .php ou .svg enviado aqui seria servido pelo site)
            $extension        = self::validateOgImage($imageFile->getPathname(), (int) $imageFile->getSize());
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
     * Confere a imagem OG pelo conteúdo (não pelo nome nem pelo tipo informado pelo navegador)
     * e devolve a extensão com que ela será gravada.
     *
     * @throws \InvalidArgumentException com a mensagem a mostrar ao administrador
     */
    public static function validateOgImage(string $path, int $size): string
    {
        if ($size <= 0 || !is_file($path)) {
            throw new \InvalidArgumentException('O arquivo da imagem não foi recebido.');
        }
        if ($size > self::OG_IMAGE_MAX_BYTES) {
            throw new \InvalidArgumentException('A imagem é grande demais: o máximo é 5 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $info = @getimagesize($path);

        // As duas leituras precisam concordar: o tipo do conteúdo e o cabeçalho da imagem
        if (!isset(self::OG_IMAGE_TYPES[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new \InvalidArgumentException('Envie uma imagem JPG, PNG ou WebP. Outros tipos de arquivo não são aceitos.');
        }

        return self::OG_IMAGE_TYPES[$mime];
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
