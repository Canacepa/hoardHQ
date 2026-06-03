<?php

namespace App\Entity;

use App\Repository\CollectionsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\UX\Turbo\Attribute\Broadcast;

#[ORM\Entity(repositoryClass: CollectionsRepository::class)]
#[Broadcast]
class Collections
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'collections')]
    #[ORM\JoinColumn(nullable: false)]
    private ?user $owner = null;

    /**
     * @var Collection<int, Categories>
     */
    #[ORM\ManyToMany(targetEntity: Categories::class, mappedBy: 'collections')]
    private Collection $categories;

    /**
     * @var Collection<int, user>
     */
    #[ORM\ManyToMany(targetEntity: user::class, inversedBy: 'contributor')]
    #[ORM\JoinTable(name: 'collections_contributors')]
    private Collection $contributors;

    /**
     * @var Collection<int, user>
     */
    #[ORM\ManyToMany(targetEntity: user::class, inversedBy: 'collectionviewer')]
    #[ORM\JoinTable(name: 'collections_viewers')]
    private Collection $viewers;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->contributors = new ArrayCollection();
        $this->viewers = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getOwner(): ?user
    {
        return $this->owner;
    }

    public function setOwner(?user $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, Categories>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Categories $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->addCollection($this);
        }

        return $this;
    }

    public function removeCategory(Categories $category): static
    {
        if ($this->categories->removeElement($category)) {
            $category->removeCollection($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, user>
     */
    public function getContributors(): Collection
    {
        return $this->contributors;
    }

    public function addContributor(user $contributor): static
    {
        if (!$this->contributors->contains($contributor)) {
            $this->contributors->add($contributor);
        }

        return $this;
    }

    public function removeContributor(user $contributor): static
    {
        $this->contributors->removeElement($contributor);

        return $this;
    }

    /**
     * @return Collection<int, user>
     */
    public function getViewers(): Collection
    {
        return $this->viewers;
    }

    public function addViewer(user $viewer): static
    {
        if (!$this->viewers->contains($viewer)) {
            $this->viewers->add($viewer);
        }

        return $this;
    }

    public function removeViewer(user $viewer): static
    {
        $this->viewers->removeElement($viewer);

        return $this;
    }
}
