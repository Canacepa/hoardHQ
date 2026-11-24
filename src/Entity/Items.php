<?php

namespace App\Entity;

use App\Repository\ItemsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemsRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_item_source_external', columns: ['source', 'external_id'])]
/** Shared catalog entry: one row per real-world thing. Per-collection data lives on CollectionItem. */
class Items
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attributes = null;

    /**
     * @var Collection<int, categories>
     */
    #[ORM\ManyToMany(targetEntity: categories::class, inversedBy: 'items')]
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

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

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    /** Release (or first air) year from the provider, if it gave one. */
    public function getYear(): ?int
    {
        $year = $this->attributes['year'] ?? null;

        return is_numeric($year) ? (int) $year : null;
    }

    /** Image URL from the provider, if it gave one. */
    public function getPoster(): ?string
    {
        return $this->attributes['poster'] ?? null;
    }

    /**
     * Platforms a game is available on, as the provider names them.
     *
     * @return list<string>
     */
    public function getPlatforms(): array
    {
        return $this->attributes['platforms'] ?? [];
    }

    /**
     * Regular seasons of a TV show with their episode counts; empty for movies and for shows imported without season data.
     *
     * @return list<array{number: int, name: string, episodes: int}>
     */
    public function getSeasonList(): array
    {
        return $this->attributes['season_list'] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function setAttributes(?array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * @return Collection<int, categories>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(categories $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(categories $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }
}
