<?php

namespace App\Entity;

use App\Repository\CollectionsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionsRepository::class)]
#[ORM\HasLifecycleCallbacks]
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

    #[ORM\ManyToOne(inversedBy: 'collectionsOwned')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(inversedBy: 'collections')]
    private ?Categories $category = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'collectionsContributor')]
    #[ORM\JoinTable(name: 'collections_contributors')]
    private Collection $contributors;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'collectionsViewer')]
    #[ORM\JoinTable(name: 'collections_viewers')]
    private Collection $viewers;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'collectionsFollowed')]
    #[ORM\JoinTable(name: 'collections_followers')]
    private Collection $followers;

    /**
     * @var Collection<int, CollectionItem>
     */
    #[ORM\OneToMany(targetEntity: CollectionItem::class, mappedBy: 'collection', orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $entries;

    /** The file name of a cover picture the user uploaded (see CollectionCover), if any. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnail = null;

    /** An item of this collection whose poster is the cover; cleared by the database when the item leaves the collection. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CollectionItem $coverEntry = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $public = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->contributors = new ArrayCollection();
        $this->viewers = new ArrayCollection();
        $this->followers = new ArrayCollection();
        $this->entries = new ArrayCollection();
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getCategory(): ?Categories
    {
        return $this->category;
    }

    /** The category to display: null when there is none or it is inactive (inactive categories are never shown). */
    public function getVisibleCategory(): ?Categories
    {
        return $this->category?->isActive() ? $this->category : null;
    }

    /**
     * What this collection can hold, as the kind of item its API provides: "movie" or "tv" (TMDB), "game" (IGDB), "boardgame" (BGG), "book" and "comic" (Google Books). Null when the category has
     * no item API behind it (yet), which means nothing can be added.
     */
    public function getImportType(): ?string
    {
        return match ($this->getVisibleCategory()?->getName()) {
            'Movies' => 'movie',
            'TV Shows' => 'tv',
            'Video Games' => 'game',
            'Board Games' => 'boardgame',
            'Books' => 'book',
            'Comics & Manga' => 'comic',
            default => null,
        };
    }

    /**
     * The statuses an entry can have, value => label: watch state for movies and TV, ownership for games and board games.
     *
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return match ($this->getImportType()) {
            'movie', 'tv' => ['want' => 'Wanna watch', 'watched' => 'Watched'],
            'game', 'boardgame' => ['want' => 'Wanted', 'owned' => 'Owned'],
            'book', 'comic' => ['want' => 'Wanted', 'owned' => 'Owned', 'ebook' => 'Ebook'],
            default => [],
        };
    }

    /** Books and manga also carry a separate Read flag. */
    public function hasReadFlag(): bool
    {
        return \in_array($this->getImportType(), ['book', 'comic'], true);
    }

    public function hasStatus(): bool
    {
        return $this->getStatusOptions() !== [];
    }

    /** Whether items can be added at all. */
    public function isImportable(): bool
    {
        return $this->getImportType() !== null;
    }

    public function setCategory(?Categories $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getContributors(): Collection
    {
        return $this->contributors;
    }

    public function addContributor(User $contributor): static
    {
        if (!$this->contributors->contains($contributor)) {
            $this->contributors->add($contributor);
        }

        return $this;
    }

    public function removeContributor(User $contributor): static
    {
        $this->contributors->removeElement($contributor);

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getViewers(): Collection
    {
        return $this->viewers;
    }

    public function addViewer(User $viewer): static
    {
        if (!$this->viewers->contains($viewer)) {
            $this->viewers->add($viewer);
        }

        return $this;
    }

    public function removeViewer(User $viewer): static
    {
        $this->viewers->removeElement($viewer);

        return $this;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?string $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getCoverEntry(): ?CollectionItem
    {
        return $this->coverEntry;
    }

    public function setCoverEntry(?CollectionItem $coverEntry): static
    {
        $this->coverEntry = $coverEntry;

        return $this;
    }

    /** The cover picture to show: the uploaded one, or the poster of the chosen item; null means the default icon. */
    public function getCoverUrl(): ?string
    {
        return $this->thumbnail ? '/uploads/covers/' . $this->thumbnail : $this->coverEntry?->getItem()?->getPoster();
    }

    /**
     * @return Collection<int, User>
     */
    public function getFollowers(): Collection
    {
        return $this->followers;
    }

    public function addFollower(User $follower): static
    {
        if (!$this->followers->contains($follower)) {
            $this->followers->add($follower);
        }

        return $this;
    }

    public function removeFollower(User $follower): static
    {
        $this->followers->removeElement($follower);

        return $this;
    }

    /**
     * @return Collection<int, CollectionItem>
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
