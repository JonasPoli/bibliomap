<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'theoretical_lens')]
class TheoreticalLens
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $category = null;

    #[ORM\Column(length: 255, name: 'research_field')]
    private ?string $researchField = 'CTS';

    #[ORM\Column(type: Types::JSON)]
    private array $terms = [];

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icon = 'bi-mortarboard';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $color = '#4f8ef7';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $citationFormats = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $category = trim($category);
        $this->category = $category !== '' ? $category : 'Geral';
        return $this;
    }

    public function getResearchField(): ?string
    {
        return $this->researchField;
    }

    public function setResearchField(string $researchField): static
    {
        $researchField = trim($researchField);
        $this->researchField = $researchField !== '' ? $researchField : 'CTS';
        return $this;
    }

    public function getTerms(): array
    {
        return $this->terms;
    }

    public function setTerms(array $terms): static
    {
        $this->terms = array_values(array_unique(array_filter(array_map('trim', $terms))));
        return $this;
    }

    public function getCitationFormats(): array
    {
        return $this->citationFormats ?: [];
    }

    public function setCitationFormats(array $citationFormats): static
    {
        $this->citationFormats = array_values(array_unique(array_filter(array_map('trim', $citationFormats))));
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon ?: 'bi-mortarboard';
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon ?: 'bi-mortarboard';
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color ?: '#4f8ef7';
    }

    public function setColor(?string $color): static
    {
        $this->color = $color ?: '#4f8ef7';
        return $this;
    }

    /**
     * Generates a list of default citation formats for a given lens name.
     */
    public static function generateDefaultCitationFormats(string $name): array
    {
        $formats = [];
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        // 1. Original and basic cases
        $formats[] = $name;
        $formats[] = mb_strtolower($name);
        $formats[] = mb_strtoupper($name);

        // 2. Hyphen replacements
        $noHyphen = str_replace('-', ' ', $name);
        $formats[] = $noHyphen;
        $formats[] = mb_strtolower($noHyphen);

        // 3. Clean alphanumeric
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $formats[] = $clean;
        $formats[] = mb_strtolower($clean);

        // 4. Plurals
        $formats[] = $name . 's';
        $formats[] = mb_strtolower($name) . 's';
        $formats[] = $noHyphen . 's';
        $formats[] = mb_strtolower($noHyphen) . 's';

        // 5. Plural 'y' -> 'ies'
        if (preg_match('/y$/i', $name)) {
            $ies = preg_replace('/y$/i', 'ies', $name);
            $formats[] = $ies;
            $formats[] = mb_strtolower($ies);
            
            $iesNoHyphen = preg_replace('/y$/i', 'ies', $noHyphen);
            $formats[] = $iesNoHyphen;
            $formats[] = mb_strtolower($iesNoHyphen);
        }

        // 6. Prefixes
        $formats[] = 'the ' . mb_strtolower($name);
        $formats[] = 'the ' . mb_strtolower($noHyphen);

        // 7. Acronyms
        $words = preg_split('/[\s\-]+/', $name);
        if (count($words) >= 2) {
            $acronym = '';
            foreach ($words as $word) {
                $first = mb_substr($word, 0, 1);
                if (preg_match('/\p{L}/u', $first)) {
                    $acronym .= mb_strtoupper($first);
                }
            }
            if (mb_strlen($acronym) >= 2) {
                $formats[] = $acronym;
                $formats[] = $acronym . ' theory';
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $formats))));
    }
}
