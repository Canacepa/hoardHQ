<?php

namespace App\Entity;

use App\Repository\CategoriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoriesRepository::class)]
class Categories
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, collections>
     */
    #[ORM\ManyToMany(targetEntity: collections::class, inversedBy: 'categories')]
    private Collection $collections;

    /**
     * @var Collection<int, Items>
     */
    #[ORM\ManyToMany(targetEntity: Items::class, mappedBy: 'categories')]
    private Collection $items;

    public function __construct()
    {
        $this->collections = new ArrayCollection();
        $this->items = new ArrayCollection();
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

    /**
     * @return Collection<int, collections>
     */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(collections $collection): static
    {
        if (!$this->collections->contains($collection)) {
            $this->collections->add($collection);
        }

        return $this;
    }

    public function removeCollection(collections $collection): static
    {
        $this->collections->removeElement($collection);

        return $this;
    }

    /**
     * @return Collection<int, Items>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(Items $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->addCategory($this);
        }

        return $this;
    }

    public function removeItem(Items $item): static
    {
        if ($this->items->removeElement($item)) {
            $item->removeCategory($this);
        }

        return $this;
    }
}
