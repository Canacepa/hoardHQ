<?php

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\CollectionItem;
use App\Entity\Collections;
use App\Entity\Items;
use App\Service\EntrySorter;
use PHPUnit\Framework\TestCase;

final class EntrySorterTest extends TestCase
{
    private function entry(int $id, string $name, ?string $year = null, ?string $status = null, int $watched = 0): CollectionItem
    {
        $attributes = ['year' => $year];
        if ($watched) { // a show with one season of 10 episodes, $watched of them ticked
            $attributes['season_list'] = [['number' => 1, 'name' => 'Season 1', 'episodes' => 10]];
        }
        $entry = (new CollectionItem())->setItem((new Items())->setName($name)->setAttributes($attributes))->setStatus($status);
        (new \ReflectionProperty($entry, 'id'))->setValue($entry, $id);
        for ($i = 1; $i <= $watched; ++$i) {
            $entry->toggleWatched("s1e$i");
        }

        return $entry;
    }

    /** @return int[] the ids in the order the sort puts them */
    private function order(string $sort, array $entries): array
    {
        return array_map(fn (CollectionItem $e) => $e->getId(), (new EntrySorter())->sort($entries, $sort));
    }

    private function collectionIn(?string $category): Collections
    {
        $c = new Collections();

        return $category ? $c->setCategory((new Categories())->setName($category)->setActive(true)) : $c;
    }

    public function testStatusSortIsOnlyOfferedWhereStatusExists(): void
    {
        $sorter = new EntrySorter();

        $this->assertArrayHasKey('status', $sorter->optionsFor($this->collectionIn('Movies')));
        $this->assertArrayHasKey('status', $sorter->optionsFor($this->collectionIn('TV Shows')));
        foreach (['Books', 'Video Games', null] as $category) {
            $options = $sorter->optionsFor($this->collectionIn($category));
            $this->assertArrayNotHasKey('status', $options, (string) $category);
            $this->assertCount(count(EntrySorter::SORTS) - 1, $options);
        }
    }

    public function testAnUnknownOrUnavailableSortFallsBackToTheDefault(): void
    {
        $sorter = new EntrySorter();
        $movies = $this->collectionIn('Movies');
        $books = $this->collectionIn('Books');

        foreach (array_keys(EntrySorter::SORTS) as $key) {
            $this->assertSame($key, $sorter->sortFrom($key, $movies));
        }
        $this->assertSame('added', $sorter->sortFrom('status', $books)); // asked for by hand, but a Books collection has no status
        foreach ([null, '', 'bogus', 'NAME', '../x', 'name;drop'] as $bad) {
            $this->assertSame('added', $sorter->sortFrom($bad, $movies), (string) $bad);
        }
        $this->assertSame('added', array_key_first(EntrySorter::SORTS)); // the default is the first entry: how the list always was
    }

    public function testRecentlyAddedIsTheDefaultAndOldestIsItsReverse(): void
    {
        $list = [$this->entry(4, 'd'), $this->entry(9, 'a'), $this->entry(2, 'z'), $this->entry(7, 'm')];

        $this->assertSame([9, 7, 4, 2], $this->order('added', $list));
        $this->assertSame([9, 7, 4, 2], $this->order('anything else', $list));
        $this->assertSame([2, 4, 7, 9], $this->order('oldest', $list));
    }

    public function testNameIsCaseInsensitiveAndNaturalAndTiesFallBackToRecentlyAdded(): void
    {
        $list = [$this->entry(1, 'Show — Season 10'), $this->entry(2, 'Show — Season 2'), $this->entry(3, 'apple'), $this->entry(4, 'Apple'), $this->entry(5, 'Banana')];

        $this->assertSame([4, 3, 5, 2, 1], $this->order('name', $list));       // Apple (added later) before apple, Banana, then Season 2 before Season 10
        $this->assertSame([1, 2, 5, 4, 3], $this->order('name_desc', $list)); // the same names reversed; the equal pair still has the later-added one first
    }

    public function testYearsSortBothWaysAndItemsWithoutOneAlwaysGoLast(): void
    {
        $list = [$this->entry(1, 'b', '1999'), $this->entry(2, 'a', null), $this->entry(3, 'c', '2008'), $this->entry(4, 'd', '1999'), $this->entry(5, 'e', 'n/a')];

        $this->assertSame([3, 1, 4, 2, 5], $this->order('year', $list));       // 2008, then 1999 (b before d by name), then the two without a year by name
        $this->assertSame([1, 4, 3, 2, 5], $this->order('year_asc', $list));   // 1999, 1999, 2008, then the same two last: "no year" is last in both directions
    }

    public function testStatusPutsWhatYouStillWantToWatchFirst(): void
    {
        $list = [
            $this->entry(1, 'done', status: CollectionItem::WATCHED),
            $this->entry(2, 'plain'),
            $this->entry(3, 'wanted b', status: CollectionItem::WANT),
            $this->entry(4, 'halfway', watched: 4),
            $this->entry(5, 'wanted a', status: CollectionItem::WANT),
        ];

        $this->assertSame([5, 3, 4, 2, 1], $this->order('status', $list)); // want (a, b), in progress, unmarked, watched
    }

    public function testSortingDoesNotChangeTheListItWasGiven(): void
    {
        $list = [$this->entry(1, 'b'), $this->entry(2, 'a')];

        (new EntrySorter())->sort($list, 'name');

        $this->assertSame([1, 2], array_map(fn (CollectionItem $e) => $e->getId(), $list));
    }

    public function testTheYearOfAnItemIsReadFromItsAttributes(): void
    {
        $item = static fn (?array $attributes) => (new Items())->setAttributes($attributes);

        $this->assertSame(2008, $item(['year' => '2008'])->getYear());
        $this->assertSame(1977, $item(['year' => 1977])->getYear());
        $this->assertNull($item(['year' => null])->getYear());
        $this->assertNull($item(['year' => 'n/a'])->getYear());
        $this->assertNull($item([])->getYear());
        $this->assertNull($item(null)->getYear());
    }
}
