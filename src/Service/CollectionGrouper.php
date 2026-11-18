<?php

namespace App\Service;

use App\Entity\Categories;
use App\Entity\Collections;

/** Splits collections into per-category groups (sorted by category name) plus the uncategorised ones, keeping the given order inside each. */
final class CollectionGrouper
{
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
