<?php

namespace App\Entity;

use App\Repository\InstitutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstitutionRepository::class)]
#[ORM\Table(name: 'instituicoes_ensino')]
#[ORM\HasLifecycleCallbacks]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $officialName = '';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $shortName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sigla = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $institutionType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $natureza = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?State $state = null;

    #[ORM\ManyToOne(targetEntity: City::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?City $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $razaoSocial = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(nullable: true)]
    private ?int $codigoMantenedora = null;

    #[ORM\Column(nullable: true)]
    private ?int $codigoIes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $enderecoSede = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $organizacaoAcademica = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $tipoCredenciamento = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoria = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoriaAdministrativa = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataCriacao = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $ci = null;

    #[ORM\Column(nullable: true)]
    private ?int $anoCi = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $ciEad = null;

    #[ORM\Column(nullable: true)]
    private ?int $anoCiEad = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $igc = null;

    #[ORM\Column(nullable: true)]
    private ?int $anoIgc = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $reitor = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $representanteLegal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sinalizacoesVigentes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $situacaoIes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vantagepoint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $officialWebsite = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $institutionalEmail = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'ano_fundacao', type: 'integer', nullable: true)]
    private ?int $foundationYear = null;

    #[ORM\Column(name: 'ano_extincao', type: 'integer', nullable: true)]
    private ?int $extinctionYear = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /** @var Collection<int, InstitutionVariation> */
    #[ORM\OneToMany(targetEntity: InstitutionVariation::class, mappedBy: 'institution', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOfficialName(): string { return $this->officialName; }
    public function setOfficialName(string $v): static { $this->officialName = $v; return $this; }

    public function getShortName(): ?string { return $this->shortName; }
    public function setShortName(?string $v): static { $this->shortName = $v; return $this; }

    public function getSigla(): ?string { return $this->sigla; }
    public function setSigla(?string $v): static { $this->sigla = $v; return $this; }

    public function getInstitutionType(): ?string { return $this->institutionType; }
    public function setInstitutionType(?string $v): static { $this->institutionType = $v; return $this; }

    public function getNatureza(): ?string { return $this->natureza; }
    public function setNatureza(?string $v): static { $this->natureza = $v; return $this; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function getCity(): ?City { return $this->city; }
    public function setCity(?City $city): static { $this->city = $city; return $this; }

    public function getRazaoSocial(): ?string { return $this->razaoSocial; }
    public function setRazaoSocial(?string $v): static { $this->razaoSocial = $v; return $this; }

    public function getCnpj(): ?string { return $this->cnpj; }
    public function setCnpj(?string $v): static { $this->cnpj = $v; return $this; }

    public function getCodigoMantenedora(): ?int { return $this->codigoMantenedora; }
    public function setCodigoMantenedora(?int $v): static { $this->codigoMantenedora = $v; return $this; }

    public function getCodigoIes(): ?int { return $this->codigoIes; }
    public function setCodigoIes(?int $v): static { $this->codigoIes = $v; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): static { $this->longitude = $v; return $this; }

    public function getTelefone(): ?string { return $this->telefone; }
    public function setTelefone(?string $v): static { $this->telefone = $v; return $this; }

    public function getEnderecoSede(): ?string { return $this->enderecoSede; }
    public function setEnderecoSede(?string $v): static { $this->enderecoSede = $v; return $this; }

    public function getOrganizacaoAcademica(): ?string { return $this->organizacaoAcademica; }
    public function setOrganizacaoAcademica(?string $v): static { $this->organizacaoAcademica = $v; return $this; }

    public function getTipoCredenciamento(): ?string { return $this->tipoCredenciamento; }
    public function setTipoCredenciamento(?string $v): static { $this->tipoCredenciamento = $v; return $this; }

    public function getCategoria(): ?string { return $this->categoria; }
    public function setCategoria(?string $v): static { $this->categoria = $v; return $this; }

    public function getCategoriaAdministrativa(): ?string { return $this->categoriaAdministrativa; }
    public function setCategoriaAdministrativa(?string $v): static { $this->categoriaAdministrativa = $v; return $this; }

    public function getDataCriacao(): ?\DateTimeImmutable { return $this->dataCriacao; }
    public function setDataCriacao(?\DateTimeImmutable $v): static { $this->dataCriacao = $v; return $this; }

    public function getCi(): ?string { return $this->ci; }
    public function setCi(?string $v): static { $this->ci = $v; return $this; }

    public function getAnoCi(): ?int { return $this->anoCi; }
    public function setAnoCi(?int $v): static { $this->anoCi = $v; return $this; }

    public function getCiEad(): ?string { return $this->ciEad; }
    public function setCiEad(?string $v): static { $this->ciEad = $v; return $this; }

    public function getAnoCiEad(): ?int { return $this->anoCiEad; }
    public function setAnoCiEad(?int $v): static { $this->anoCiEad = $v; return $this; }

    public function getIgc(): ?string { return $this->igc; }
    public function setIgc(?string $v): static { $this->igc = $v; return $this; }

    public function getAnoIgc(): ?int { return $this->anoIgc; }
    public function setAnoIgc(?int $v): static { $this->anoIgc = $v; return $this; }

    public function getReitor(): ?string { return $this->reitor; }
    public function setReitor(?string $v): static { $this->reitor = $v; return $this; }

    public function getRepresentanteLegal(): ?string { return $this->representanteLegal; }
    public function setRepresentanteLegal(?string $v): static { $this->representanteLegal = $v; return $this; }

    public function getSinalizacoesVigentes(): ?string { return $this->sinalizacoesVigentes; }
    public function setSinalizacoesVigentes(?string $v): static { $this->sinalizacoesVigentes = $v; return $this; }

    public function getSituacaoIes(): ?string { return $this->situacaoIes; }
    public function setSituacaoIes(?string $v): static { $this->situacaoIes = $v; return $this; }

    public function getVantagepoint(): ?string { return $this->vantagepoint; }
    public function setVantagepoint(?string $v): static { $this->vantagepoint = $v; return $this; }

    public function getOfficialWebsite(): ?string { return $this->officialWebsite; }
    public function setOfficialWebsite(?string $v): static { $this->officialWebsite = $v; return $this; }

    public function getInstitutionalEmail(): ?string { return $this->institutionalEmail; }
    public function setInstitutionalEmail(?string $v): static { $this->institutionalEmail = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getFoundationYear(): ?int { return $this->foundationYear; }
    public function setFoundationYear(?int $v): static { $this->foundationYear = $v; return $this; }

    public function getExtinctionYear(): ?int { return $this->extinctionYear; }
    public function setExtinctionYear(?int $v): static { $this->extinctionYear = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $user): static { $this->createdBy = $user; return $this; }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $user): static { $this->updatedBy = $user; return $this; }

    /** @return Collection<int, InstitutionVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(InstitutionVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setInstitution($this);
        }
        return $this;
    }

    public function removeVariation(InstitutionVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getInstitution() === $this) {
                $variation->setInstitution(null);
            }
        }
        return $this;
    }
}
