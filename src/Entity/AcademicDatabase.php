<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'academic_database')]
class AcademicDatabase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $acronym = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fileFormats = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $signatureColumns = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $importInstructions = null;

    /** @var Collection<int, QualisJournal> */
    #[ORM\ManyToMany(targetEntity: QualisJournal::class, mappedBy: 'academicDatabases')]
    private Collection $journals;

    public function __construct()
    {
        $this->journals = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAcronym(): ?string
    {
        return $this->acronym;
    }

    public function setAcronym(string $acronym): self
    {
        $this->acronym = $acronym;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;
        return $this;
    }

    public function getFileFormats(): ?array
    {
        return $this->fileFormats;
    }

    public function setFileFormats(?array $fileFormats): self
    {
        $this->fileFormats = $fileFormats;
        return $this;
    }

    public function getSignatureColumns(): ?array
    {
        return $this->signatureColumns;
    }

    public function setSignatureColumns(?array $signatureColumns): self
    {
        $this->signatureColumns = $signatureColumns;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getImportInstructions(): ?string
    {
        return $this->importInstructions;
    }

    public function setImportInstructions(?string $importInstructions): self
    {
        $this->importInstructions = $importInstructions;
        return $this;
    }

    /**
     * @return Collection<int, QualisJournal>
     */
    public function getJournals(): Collection
    {
        return $this->journals;
    }

    public function addJournal(QualisJournal $journal): self
    {
        if (!$this->journals->contains($journal)) {
            $this->journals->add($journal);
            $journal->addAcademicDatabase($this);
        }
        return $this;
    }

    public function removeJournal(QualisJournal $journal): self
    {
        if ($this->journals->removeElement($journal)) {
            $journal->removeAcademicDatabase($this);
        }
        return $this;
    }
}
