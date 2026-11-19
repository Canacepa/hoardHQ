<?php

namespace App\Tests\Service;

use App\Entity\CollectionItem;
use App\Entity\Collections;
use App\Service\CollectionGrouper;
use PHPUnit\Framework\TestCase;

final class CollectionGrouperTest extends TestCase
{
    private function collection(int $id, string $name, string $updated = '2026-01-01', int $items = 0): Collections
    {
        $c = new Collections();
        $c->setName($name);
        (new \ReflectionProperty($c, 'id'))->setValue($c, $id);
        (new \ReflectionProperty($c, 'updatedAt'))->setValue($c, new \DateTimeImmutable($updated));
        for ($i = 0; $i < $items; ++$i) {
            $c->getEntries()->add(new CollectionItem());
        }

        return $c;
    }

    /** @return int[] the ids in the order the sort puts them */
    private function order(string $sort, array $collections): array
    {
        return array_map(fn (Collections $c) => $c->getId(), (new CollectionGrouper())->sort($collections, $sort));
    }

    public function testAnUnknownSortFallsBackToTheDefault(): void
    {
        $g = new CollectionGrouper();

        foreach (array_keys(CollectionGrouper::SORTS) as $key) {
            $this->assertSame($key, $g->sortFrom($key));
        }
        foreach ([null, '', 'bogus', 'NAME', 'name ', '../x', 'updated;drop'] as $bad) {
            $this->assertSame('updated', $g->sortFrom($bad), (string) $bad);
        }
        $this->assertSame('updated', array_key_first(CollectionGrouper::SORTS)); // the default is the first entry: the page's old behaviour
    }

    public function testTheDisplayComesFromTheQueryOrTheDefaultOfThePage(): void
    {
        $g = new CollectionGrouper();

        foreach (['grouped', 'grid', 'list'] as $view) {
            $this->assertSame($view, $g->viewFrom($view, 'grouped'), $view);
            $this->assertSame($view, $g->viewFrom($view, 'grid'), $view);
        }
        // each page has its own default, and anything unknown gets it
        $this->assertSame('grouped', $g->viewFrom('bogus', 'grouped'));
        $this->assertSame('grid', $g->viewFrom('', 'grid'));
        $this->assertSame('grid', $g->viewFrom(null, 'grid'));
        $this->assertSame('grid', $g->viewFrom('LIST', 'grid')); // case matters
        $this->assertSame('grid', $g->viewFrom('list ', 'grid'));
        $this->assertSame(['grouped', 'grid', 'list'], CollectionGrouper::VIEWS);
    }

    public function testRecentlyUpdatedIsTheDefaultAndBreaksTiesByNewestId(): void
    {
        $list = [$this->collection(1, 'a', '2026-01-01'), $this->collection(2, 'b', '2026-03-01'), $this->collection(3, 'c', '2026-03-01'), $this->collection(4, 'd', '2026-02-01')];

        $this->assertSame([3, 2, 4, 1], $this->order('updated', $list));
        $this->assertSame([3, 2, 4, 1], $this->order('something else', $list)); // anything unknown sorts like the default
    }

    public function testNewestAndOldestFollowTheId(): void
    {
        $list = [$this->collection(5, 'a'), $this->collection(2, 'b'), $this->collection(9, 'c')];

        $this->assertSame([9, 5, 2], $this->order('newest', $list));
        $this->assertSame([2, 5, 9], $this->order('oldest', $list));
    }

    public function testNameIsCaseInsensitiveAndNaturalSoNumbersSortAsNumbers(): void
    {
        $list = [$this->collection(1, 'banana'), $this->collection(2, 'Apple'), $this->collection(3, 'apple 10'), $this->collection(4, 'apple 2'), $this->collection(5, 'Cherry')];

        $this->assertSame([2, 4, 3, 1, 5], $this->order('name', $list));       // Apple, apple 2, apple 10, banana, Cherry
        $this->assertSame([5, 1, 3, 4, 2], $this->order('name_desc', $list));  // exactly the reverse
    }

    public function testEqualNamesComeOutInAStableOrder(): void
    {
        $list = [$this->collection(7, 'Same'), $this->collection(3, 'same'), $this->collection(5, 'SAME')];

        $this->assertSame([3, 5, 7], $this->order('name', $list));
        $this->assertSame([7, 5, 3], $this->order('name_desc', $list));
    }

    public function testMostItemsFirstAndRecentlyUpdatedBreaksTies(): void
    {
        $list = [
            $this->collection(1, 'a', '2026-01-01', items: 2),
            $this->collection(2, 'b', '2026-01-01', items: 5),
            $this->collection(3, 'c', '2026-02-01', items: 2),
            $this->collection(4, 'd', '2026-01-01', items: 0),
        ];

        $this->assertSame([2, 3, 1, 4], $this->order('items', $list));
    }

    public function testSortingDoesNotChangeTheListItWasGiven(): void
    {
        $list = [$this->collection(1, 'b'), $this->collection(2, 'a')];

        (new CollectionGrouper())->sort($list, 'name');

        $this->assertSame([1, 2], array_map(fn (Collections $c) => $c->getId(), $list));
    }
}
