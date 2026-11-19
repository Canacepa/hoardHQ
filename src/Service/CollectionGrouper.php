<?php

namespace App\Service;

use App\Entity\Categories;
use App\Entity\Collections;

/**
 * Splits collections into per-category groups (sorted by category name) plus the uncategorised ones, keeping the given order
 * inside each, and sorts a list of collections (so in the grouped display the sort applies inside each group).
 */
final class CollectionGrouper
{
    /** The ways a list of collections can be sorted: query value => label. The first is the default. */
    public const SORTS = [
        'updated' => 'Recently updated',
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'name' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
        'items' => 'Most items',
    ];

    /** The displays of a collections list: grouped by category, a grid of cards, or one row per collection. */
    public const VIEWS = ['grouped', 'grid', 'list'];

    /** The display to use for a value from the query string: itself when it is one of VIEWS, otherwise $default (each page has its own). */
    public function viewFrom(?string $value, string $default): string
    {
        return \in_array($value, self::VIEWS, true) ? $value : $default;
    }

    /** The sort to use for a value from the query string: itself when it is one of SORTS, otherwise the default. */
    public function sortFrom(?string $value): string
    {
        return $value !== null && isset(self::SORTS[$value]) ? $value : array_key_first(self::SORTS);
    }

    /**
     * @param Collections[] $collections
     * @param string $sort one of the keys of SORTS (anything else sorts like the default)
     * @return Collections[]
     */
    public function sort(array $collections, string $sort): array
    {
        // Every sort ends on the id so that equal collections always come out in the same order.
        usort($collections, match ($sort) {
            'newest' => static fn (Collections $a, Collections $b) => $b->getId() <=> $a->getId(),
            'oldest' => static fn (Collections $a, Collections $b) => $a->getId() <=> $b->getId(),
            // natural, case-insensitive: "Vol 2" comes before "Vol 10", "apple" next to "Apple"
            'name' => static fn (Collections $a, Collections $b) => strnatcasecmp($a->getName(), $b->getName()) ?: $a->getId() <=> $b->getId(),
            'name_desc' => static fn (Collections $a, Collections $b) => strnatcasecmp($b->getName(), $a->getName()) ?: $b->getId() <=> $a->getId(),
            'items' => static fn (Collections $a, Collections $b) => [$b->getEntries()->count(), $b->getUpdatedAt(), $b->getId()] <=> [$a->getEntries()->count(), $a->getUpdatedAt(), $a->getId()],
            default => static fn (Collections $a, Collections $b) => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()],
        });

        return $collections;
    }

    /**
     * Distinct categories used by the collections, sorted by name.
     *
     * @param Collections[] $collections
     * @return list<Categories>
     */
    public function categories(array $collections): array
    {
        $categories = [];
        foreach ($collections as $collection) {
            if ($category = $collection->getVisibleCategory()) {
                $categories[$category->getId()] = $category;
            }
        }
        usort($categories, fn ($a, $b) => strcmp($a->getName(), $b->getName()));

        return $categories;
    }

    /**
     * Keeps the collections whose category slug is in $categorySlugs (all of them when it is empty).
     *
     * @param Collections[] $collections
     * @param string[] $categorySlugs
     * @return Collections[]
     */
    public function filter(array $collections, array $categorySlugs): array
    {
        if (!$categorySlugs) {
            return $collections;
        }

        return array_values(array_filter($collections, fn (Collections $c) => in_array($c->getVisibleCategory()?->getSlug(), $categorySlugs, true)));
    }

    /**
     * @param Collections[] $collections
     * @return array{groups: list<array{category: Categories, collections: Collections[]}>, uncategorised: Collections[]}
     */
    public function group(array $collections): array
    {
        $groups = [];
        $uncategorised = [];
        foreach ($collections as $collection) {
            $category = $collection->getVisibleCategory();
            if (!$category) {
                $uncategorised[] = $collection;
                continue;
            }
            $groups[$category->getId()] ??= ['category' => $category, 'collections' => []];
            $groups[$category->getId()]['collections'][] = $collection;
        }
        usort($groups, fn ($a, $b) => strcmp($a['category']->getName(), $b['category']->getName()));

        return ['groups' => $groups, 'uncategorised' => $uncategorised];
    }
}
