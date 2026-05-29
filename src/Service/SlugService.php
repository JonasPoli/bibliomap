<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class SlugService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function generate(string $text, string $entityClass = null, string $field = 'slug'): string
    {
        $slug = $this->slugify($text);

        if ($entityClass) {
            $base = $slug;
            $i = 1;
            while ($this->em->getRepository($entityClass)->findOneBy([$field => $slug])) {
                $slug = $base . '-' . $i++;
            }
        }

        return $slug;
    }

    private function slugify(string $text): string
    {
        // Transliterate accents
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9\s-]/', '', strtolower($text));
        $text = preg_replace('/[\s-]+/', '-', trim($text));
        return substr($text, 0, 200);
    }
}
