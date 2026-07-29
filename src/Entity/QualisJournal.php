<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'qualis_journal')]
#[ORM\Index(fields: ['normalizedIssn'])]
class QualisJournal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, AcademicDatabase> */
    #[ORM\ManyToMany(targetEntity: AcademicDatabase::class, inversedBy: 'journals')]
    #[ORM\JoinTable(name: 'qualis_journal_academic_database')]
    private Collection $academicDatabases;

    /** @var Collection<int, JournalVariation> */
    #[ORM\OneToMany(mappedBy: 'journal', targetEntity: JournalVariation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->academicDatabases = new ArrayCollection();
        $this->variations = new ArrayCollection();
    }

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(length: 50, name: 'normalized_issn', unique: true)]
    private ?string $normalizedIssn = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualis = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssn(): ?string
    {
        return $this->issn;
    }

    public function setIssn(?string $issn): self
    {
        $this->issn = $issn;
        return $this;
    }

    public function getNormalizedIssn(): ?string
    {
        return $this->normalizedIssn;
    }

    public function setNormalizedIssn(string $normalizedIssn): self
    {
        $this->normalizedIssn = $normalizedIssn;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getQualis(): ?string
    {
        return $this->qualis;
    }

    public function setQualis(?string $qualis): self
    {
        $this->qualis = $qualis;
        return $this;
    }

    /**
     * @return Collection<int, AcademicDatabase>
     */
    public function getAcademicDatabases(): Collection
    {
        return $this->academicDatabases;
    }

    public function addAcademicDatabase(AcademicDatabase $academicDatabase): self
    {
        if (!$this->academicDatabases->contains($academicDatabase)) {
            $this->academicDatabases->add($academicDatabase);
        }
        return $this;
    }

    public function removeAcademicDatabase(AcademicDatabase $academicDatabase): self
    {
        $this->academicDatabases->removeElement($academicDatabase);
        return $this;
    }

    /**
     * @return Collection<int, JournalVariation>
     */
    public function getVariations(): Collection
    {
        return $this->variations;
    }

    public function addVariation(JournalVariation $variation): self
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setJournal($this);
        }
        return $this;
    }

    public function removeVariation(JournalVariation $variation): self
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getJournal() === $this) {
                $variation->setJournal(null);
            }
        }
        return $this;
    }
}
