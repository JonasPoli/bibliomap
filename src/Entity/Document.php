<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(fields: ['doi'])]
#[ORM\Index(fields: ['year'])]
#[ORM\Index(fields: ['hash'])]
#[ORM\Index(fields: ['project', 'year'])]
#[ORM\UniqueConstraint(name: 'uniq_project_hash', columns: ['project_id', 'hash'])]
#[ORM\HasLifecycleCallbacks]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BibliometricProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BibliometricProject $project = null;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Dataset $dataset = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $normalizedTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $abstractText = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $documentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $pmid = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $isbn = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sourceTitle = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $volume = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $issue = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $pageStart = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $pageEnd = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(nullable: true)]
    private ?int $citedBy = null;

    #[ORM\Column(nullable: true)]
    private ?int $localCitations = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $openAccessStatus = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $publicationStage = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 50)]
    private string $source = '';

    /** Hash for deduplication: md5(normalized_title + year) */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $countries = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $institutions = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $references = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, DocumentAuthor> */
    #[ORM\OneToMany(targetEntity: DocumentAuthor::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $documentAuthors;

    /** @var Collection<int, DocumentKeyword> */
    #[ORM\OneToMany(targetEntity: DocumentKeyword::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documentKeywords;

    /** @var Collection<int, DocumentInstitution> */
    #[ORM\OneToMany(targetEntity: DocumentInstitution::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documentInstitutions;

    /** @var Collection<int, Country> */
    #[ORM\ManyToMany(targetEntity: Country::class)]
    #[ORM\JoinTable(name: 'documento_paises')]
    private Collection $countriesMapped;

    /** @var Collection<int, State> */
    #[ORM\ManyToMany(targetEntity: State::class)]
    #[ORM\JoinTable(name: 'documento_estados')]
    private Collection $statesMapped;

    /** @var Collection<int, City> */
    #[ORM\ManyToMany(targetEntity: City::class)]
    #[ORM\JoinTable(name: 'documento_cidades')]
    private Collection $citiesMapped;

    public function __construct()
    {
        $this->documentAuthors = new ArrayCollection();
        $this->documentKeywords = new ArrayCollection();
        $this->documentInstitutions = new ArrayCollection();
        $this->countriesMapped = new ArrayCollection();
        $this->statesMapped = new ArrayCollection();
        $this->citiesMapped = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?BibliometricProject { return $this->project; }
    public function setProject(?BibliometricProject $p): static { $this->project = $p; return $this; }

    public function getDataset(): ?Dataset { return $this->dataset; }
    public function setDataset(?Dataset $d): static { $this->dataset = $d; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = $v; return $this; }

    public function getNormalizedTitle(): ?string { return $this->normalizedTitle; }
    public function setNormalizedTitle(?string $v): static { $this->normalizedTitle = $v; return $this; }

    public function getAbstractText(): ?string { return $this->abstractText; }
    public function setAbstractText(?string $v): static { $this->abstractText = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }

    public function getDocumentType(): ?string { return $this->documentType; }
    public function setDocumentType(?string $v): static { $this->documentType = $v; return $this; }

    public function getDoi(): ?string { return $this->doi; }
    public function setDoi(?string $v): static { $this->doi = $v; return $this; }

    public function getPmid(): ?string { return $this->pmid; }
    public function setPmid(?string $v): static { $this->pmid = $v; return $this; }

    public function getIsbn(): ?string { return $this->isbn; }
    public function setIsbn(?string $v): static { $this->isbn = $v; return $this; }

    public function getIssn(): ?string { return $this->issn; }
    public function setIssn(?string $v): static { $this->issn = $v; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $this->url = $v; return $this; }

    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $v): static { $this->language = $v; return $this; }

    public function getSourceTitle(): ?string { return $this->sourceTitle; }
    public function setSourceTitle(?string $v): static { $this->sourceTitle = $v; return $this; }

    public function getVolume(): ?string { return $this->volume; }
    public function setVolume(?string $v): static { $this->volume = $v; return $this; }

    public function getIssue(): ?string { return $this->issue; }
    public function setIssue(?string $v): static { $this->issue = $v; return $this; }

    public function getPageStart(): ?string { return $this->pageStart; }
    public function setPageStart(?string $v): static { $this->pageStart = $v; return $this; }

    public function getPageEnd(): ?string { return $this->pageEnd; }
    public function setPageEnd(?string $v): static { $this->pageEnd = $v; return $this; }

    public function getPublisher(): ?string { return $this->publisher; }
    public function setPublisher(?string $v): static { $this->publisher = $v; return $this; }

    public function getCitedBy(): ?int { return $this->citedBy; }
    public function setCitedBy(?int $v): static { $this->citedBy = $v; return $this; }

    public function getLocalCitations(): ?int { return $this->localCitations; }
    public function setLocalCitations(?int $v): static { $this->localCitations = $v; return $this; }

    public function getOpenAccessStatus(): ?string { return $this->openAccessStatus; }
    public function setOpenAccessStatus(?string $v): static { $this->openAccessStatus = $v; return $this; }

    public function getPublicationStage(): ?string { return $this->publicationStage; }
    public function setPublicationStage(?string $v): static { $this->publicationStage = $v; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $v): static { $this->externalId = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function getHash(): ?string { return $this->hash; }
    public function setHash(?string $v): static { $this->hash = $v; return $this; }

    public function getCountries(): ?array { return $this->countries; }
    public function setCountries(?array $v): static { $this->countries = $v; return $this; }

    public function getInstitutions(): ?array { return $this->institutions; }
    public function setInstitutions(?array $v): static { $this->institutions = $v; return $this; }

    public function getReferences(): ?array { return $this->references; }
    public function setReferences(?array $v): static { $this->references = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, DocumentAuthor> */
    public function getDocumentAuthors(): Collection { return $this->documentAuthors; }

    public function addDocumentAuthor(DocumentAuthor $da): static
    {
        if (!$this->documentAuthors->contains($da)) {
            $this->documentAuthors->add($da);
            $da->setDocument($this);
        }
        return $this;
    }

    /** @return Collection<int, DocumentKeyword> */
    public function getDocumentKeywords(): Collection { return $this->documentKeywords; }

    public function addDocumentKeyword(DocumentKeyword $dk): static
    {
        if (!$this->documentKeywords->contains($dk)) {
            $this->documentKeywords->add($dk);
            $dk->setDocument($this);
        }
        return $this;
    }

    public function getAuthorsString(): string
    {
        $names = [];
        foreach ($this->documentAuthors as $da) {
            if ($da->getAuthor()) {
                $names[] = $da->getAuthor()->getName();
            }
        }
        return implode('; ', $names);
    }

    /** @return Collection<int, DocumentInstitution> */
    public function getDocumentInstitutions(): Collection { return $this->documentInstitutions; }

    public function addDocumentInstitution(DocumentInstitution $di): static
    {
        if (!$this->documentInstitutions->contains($di)) {
            $this->documentInstitutions->add($di);
            $di->setDocument($this);
        }
        return $this;
    }

    /** @return Collection<int, Country> */
    public function getCountriesMapped(): Collection { return $this->countriesMapped; }

    public function addCountryMapped(Country $country): static
    {
        if (!$this->countriesMapped->contains($country)) {
            $this->countriesMapped->add($country);
        }
        return $this;
    }

    public function removeCountryMapped(Country $country): static
    {
        $this->countriesMapped->removeElement($country);
        return $this;
    }

    /** @return Collection<int, State> */
    public function getStatesMapped(): Collection { return $this->statesMapped; }

    public function addStateMapped(State $state): static
    {
        if (!$this->statesMapped->contains($state)) {
            $this->statesMapped->add($state);
        }
        return $this;
    }

    public function removeStateMapped(State $state): static
    {
        $this->statesMapped->removeElement($state);
        return $this;
    }

    /** @return Collection<int, City> */
    public function getCitiesMapped(): Collection { return $this->citiesMapped; }

    public function addCityMapped(City $city): static
    {
        if (!$this->citiesMapped->contains($city)) {
            $this->citiesMapped->add($city);
        }
        return $this;
    }

    public function removeCityMapped(City $city): static
    {
        $this->citiesMapped->removeElement($city);
        return $this;
    }
}
