<?php

namespace App\Entity;

use App\Repository\CollectionItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An item in one collection, with that collection's own notes and condition. */
#[ORM\Entity(repositoryClass: CollectionItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_collection_item', columns: ['collection_id', 'item_id'])]
class CollectionItem
{
    public const WANT = 'want';
    public const WATCHED = 'watched';
    public const STATUSES = [self::WANT, self::WATCHED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Collections $collection = null;

    #[ORM\ManyToOne(cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Items $item = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // "condition" is a reserved word in MySQL, hence the column name.
    #[ORM\Column(name: 'item_condition', length: 50, nullable: true)]
    private ?string $condition = null;

    /** Watch state for movies and TV shows: one of STATUSES, or null when not set. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    /**
     * Episodes watched, for TV shows with season data: season number => episode numbers.
     *
     * @var array<int, list<int>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $watchedEpisodes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): ?Collections
    {
        return $this->collection;
    }

    public function setCollection(?Collections $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function getItem(): ?Items
    {
        return $this->item;
    }

    public function setItem(?Items $item): static
    {
        $this->item = $item;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): ?string
    {
        if ($this->status === self::WATCHED) {
            return 'Watched';
        }
        if ($this->getWatchedTotal() > 0) {
            return sprintf('%d / %d episodes', $this->getWatchedTotal(), $this->getEpisodeTotal());
        }

        return $this->status === self::WANT ? 'Wanna watch' : null;
    }

    /**
     * Sets the status as a whole. For a TV show with season data that means every season and episode: Watched ticks
     * them all, Wanna watch and "no status" clear them. Everything else just takes the status.
     */
    public function applyStatus(?string $status): void
    {
        if ($this->hasEpisodeTracking()) {
            $this->watchedEpisodes = null;
            if ($status === self::WATCHED) {
                foreach ($this->item->getSeasonList() as $season) {
                    $this->watchedEpisodes[$season['number']] = range(1, $season['episodes']);
                }
            }
        }
        $this->status = $status;
    }

    /** True for TV shows imported with season data: those are tracked per season and episode instead of a plain status. */
    public function hasEpisodeTracking(): bool
    {
        return (bool) $this->item?->getSeasonList();
    }

    public function isEpisodeWatched(int $season, int $episode): bool
    {
        return \in_array($episode, $this->watchedEpisodes[$season] ?? [], true);
    }

    public function getWatchedIn(int $season): int
    {
        return \count($this->watchedEpisodes[$season] ?? []);
    }

    public function getWatchedTotal(): int
    {
        $total = 0;
        foreach ($this->item?->getSeasonList() ?? [] as $season) {
            $total += $this->getWatchedIn($season['number']);
        }

        return $total;
    }

    public function getEpisodeTotal(): int
    {
        return array_sum(array_column($this->item?->getSeasonList() ?? [], 'episodes'));
    }

    /**
     * Toggles one episode ("s2e5"), a whole season ("s2") or the whole show ("all"). A season or the show is
     * cleared when it is already complete, otherwise filled. The status follows: Watched once every episode is
     * ticked, otherwise unset. Returns false (and changes nothing) for a target that does not exist.
     */
    public function toggleWatched(string $target): bool
    {
        $counts = [];
        foreach ($this->item?->getSeasonList() ?? [] as $season) {
            $counts[$season['number']] = $season['episodes'];
        }

        if ($target === 'all') {
            $seasons = array_keys($counts);
        } elseif (preg_match('/^s(\d+)(?:e(\d+))?$/', $target, $m) && isset($counts[(int) $m[1]])) {
            $seasons = [(int) $m[1]];
            if (isset($m[2])) {
                $episode = (int) $m[2];
                if ($episode < 1 || $episode > $counts[$seasons[0]]) {
                    return false;
                }
                $watched = $this->watchedEpisodes[$seasons[0]] ?? [];
                $watched = \in_array($episode, $watched, true) ? array_diff($watched, [$episode]) : [...$watched, $episode];
                sort($watched);
                $this->watchedEpisodes[$seasons[0]] = array_values($watched);

                return $this->syncStatus();
            }
        } else {
            return false;
        }

        $complete = array_all($seasons, fn (int $s) => $this->getWatchedIn($s) === $counts[$s]);
        foreach ($seasons as $s) {
            $this->watchedEpisodes[$s] = $complete ? [] : range(1, $counts[$s]);
        }

        return $this->syncStatus();
    }

    private function syncStatus(): true
    {
        $this->watchedEpisodes = array_filter($this->watchedEpisodes ?? []) ?: null;
        $this->status = $this->getEpisodeTotal() > 0 && $this->getWatchedTotal() === $this->getEpisodeTotal() ? self::WATCHED : null;

        return true;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function setCondition(?string $condition): static
    {
        $this->condition = $condition;

        return $this;
    }
}
