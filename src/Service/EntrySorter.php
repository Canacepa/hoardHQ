<?php

namespace App\Service;

use App\Entity\CollectionItem;
use App\Entity\Collections;

/** Sorts the items of one collection. Which sorts are on offer depends on the collection: only movie and TV collections have a status. */
final class EntrySorter
{
    /** The ways a collection's items can be sorted: query value => label. The first is the default (how the list always was). */
    public const SORTS = [
        'added' => 'Recently added',
        'oldest' => 'Oldest added',
        'name' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
        'year' => 'Newest release',
        'year_asc' => 'Oldest release',
        'status' => 'Wanna watch first',
    ];

    /** @return array<string, string> the sorts that make sense for this collection */
    public function optionsFor(Collections $collection): array
    {
        if (!$collection->hasStatus()) {
            return array_diff_key(self::SORTS, ['status' => true]);
        }

        return \in_array($collection->getImportType(), ['game', 'boardgame'], true) ? ['status' => 'Wanted first'] + self::SORTS : self::SORTS;
    }

    /** The sort to use for a value from the query string: itself when this collection offers it, otherwise the default. */
    public function sortFrom(?string $value, Collections $collection): string
    {
        return $value !== null && isset($this->optionsFor($collection)[$value]) ? $value : array_key_first(self::SORTS);
    }

    /**
     * @param CollectionItem[] $entries
     * @param string $sort one of the keys of SORTS (anything else sorts like the default)
     * @return CollectionItem[]
     */
    public function sort(array $entries, string $sort): array
    {
        // natural, case-insensitive: "Season 2" comes before "Season 10", "apple" next to "Apple"
        $byName = static fn (CollectionItem $a, CollectionItem $b): int => strnatcasecmp($a->getItem()->getName(), $b->getItem()->getName());
        // items with no year always go last, whichever way the years run
        $byYear = static function (CollectionItem $a, CollectionItem $b, int $direction): int {
            $ya = $a->getItem()->getYear();
            $yb = $b->getItem()->getYear();

            return $ya === null || $yb === null ? ($ya === null) <=> ($yb === null) : $direction * ($ya <=> $yb);
        };
        // every sort ends on "added last first" so that equal items always come out in the same order
        $byAdded = static fn (CollectionItem $a, CollectionItem $b): int => $b->getId() <=> $a->getId();

        usort($entries, match ($sort) {
            'oldest' => static fn (CollectionItem $a, CollectionItem $b) => $a->getId() <=> $b->getId(),
            'name' => static fn (CollectionItem $a, CollectionItem $b) => $byName($a, $b) ?: $byAdded($a, $b),
            'name_desc' => static fn (CollectionItem $a, CollectionItem $b) => $byName($b, $a) ?: $byAdded($a, $b),
            'year' => static fn (CollectionItem $a, CollectionItem $b) => $byYear($a, $b, -1) ?: $byName($a, $b) ?: $byAdded($a, $b),
            'year_asc' => static fn (CollectionItem $a, CollectionItem $b) => $byYear($a, $b, 1) ?: $byName($a, $b) ?: $byAdded($a, $b),
            'status' => static fn (CollectionItem $a, CollectionItem $b) => self::statusRank($a) <=> self::statusRank($b) ?: $byName($a, $b) ?: $byAdded($a, $b),
            default => $byAdded,
        });

        return $entries;
    }

    /** What you still want to watch first, then what you are in the middle of, then unmarked, then what is done. */
    private static function statusRank(CollectionItem $entry): int
    {
        return match (true) {
            $entry->getStatus() === CollectionItem::WATCHED, $entry->getStatus() === CollectionItem::OWNED => 3,
            $entry->getWatchedTotal() > 0 => 1,
            $entry->getStatus() === CollectionItem::WANT => 0,
            default => 2,
        };
    }
}
