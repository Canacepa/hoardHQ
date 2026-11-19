<?php

namespace App\Tests\Entity;

use App\Entity\CollectionItem;
use App\Entity\Items;
use PHPUnit\Framework\TestCase;

final class CollectionItemTest extends TestCase
{
    /** A show with two seasons: 3 and 2 episodes. */
    private function show(): CollectionItem
    {
        $item = (new Items())->setName('Show')->setAttributes(['type' => 'tv', 'season_list' => [
            ['number' => 1, 'name' => 'Season 1', 'episodes' => 3],
            ['number' => 2, 'name' => 'Season 2', 'episodes' => 2],
        ]]);

        return (new CollectionItem())->setItem($item);
    }

    public function testOnlyTvShowsWithSeasonDataAreTrackedPerEpisode(): void
    {
        $this->assertTrue($this->show()->hasEpisodeTracking());
        $this->assertFalse((new CollectionItem())->setItem((new Items())->setName('Movie')->setAttributes(['type' => 'movie']))->hasEpisodeTracking());
        $this->assertFalse((new CollectionItem())->setItem((new Items())->setName('Old import')->setAttributes(null))->hasEpisodeTracking());
    }

    public function testEpisodesToggleOnAndOff(): void
    {
        $entry = $this->show();

        $this->assertTrue($entry->toggleWatched('s1e2'));
        $this->assertTrue($entry->isEpisodeWatched(1, 2));
        $this->assertSame([1, 5], [$entry->getWatchedTotal(), $entry->getEpisodeTotal()]);
        $this->assertSame('1 / 5 episodes', $entry->getStatusLabel());

        $entry->toggleWatched('s1e2');
        $this->assertFalse($entry->isEpisodeWatched(1, 2));
        $this->assertNull($entry->getStatusLabel());
    }

    public function testSeasonFillsThenClears(): void
    {
        $entry = $this->show();
        $entry->toggleWatched('s1e1'); // partly done: a season click completes it, it does not clear it

        $entry->toggleWatched('s1');
        $this->assertSame(3, $entry->getWatchedIn(1));

        $entry->toggleWatched('s1');
        $this->assertSame(0, $entry->getWatchedIn(1));
    }

    public function testStatusBecomesWatchedWhenEveryEpisodeIsTickedAndNotBefore(): void
    {
        $entry = $this->show()->setStatus(CollectionItem::WANT);

        $entry->toggleWatched('s1');
        $this->assertNotSame(CollectionItem::WATCHED, $entry->getStatus());

        $entry->toggleWatched('s2');
        $this->assertSame(CollectionItem::WATCHED, $entry->getStatus());
        $this->assertSame('Watched', $entry->getStatusLabel());

        $entry->toggleWatched('s2e1'); // un-ticking one episode takes the show back out of Watched
        $this->assertNull($entry->getStatus());
        $this->assertSame('4 / 5 episodes', $entry->getStatusLabel());
    }

    public function testAllMarksThenClearsEverything(): void
    {
        $entry = $this->show();

        $entry->toggleWatched('all');
        $this->assertSame([5, CollectionItem::WATCHED], [$entry->getWatchedTotal(), $entry->getStatus()]);

        $entry->toggleWatched('all');
        $this->assertSame([0, null], [$entry->getWatchedTotal(), $entry->getStatus()]);
    }

    public function testApplyingAStatusCoversEverySeasonAndEpisodeOfAShow(): void
    {
        $entry = $this->show();
        $entry->toggleWatched('s1e1'); // some progress already

        $entry->applyStatus(CollectionItem::WATCHED);
        $this->assertSame([5, CollectionItem::WATCHED], [$entry->getWatchedTotal(), $entry->getStatus()]);
        $this->assertTrue($entry->isEpisodeWatched(2, 2));

        $entry->applyStatus(CollectionItem::WANT); // resets every episode, not just the status
        $this->assertSame([0, CollectionItem::WANT, 'Wanna watch'], [$entry->getWatchedTotal(), $entry->getStatus(), $entry->getStatusLabel()]);

        $entry->applyStatus(CollectionItem::WATCHED);
        $entry->applyStatus(null);
        $this->assertSame([0, null, null], [$entry->getWatchedTotal(), $entry->getStatus(), $entry->getStatusLabel()]);
    }

    public function testApplyingAStatusToAMovieJustSetsIt(): void
    {
        $movie = (new CollectionItem())->setItem((new Items())->setName('Movie')->setAttributes(['type' => 'movie']));

        $movie->applyStatus(CollectionItem::WATCHED);
        $this->assertSame('Watched', $movie->getStatusLabel());

        $movie->applyStatus(null);
        $this->assertNull($movie->getStatusLabel());
    }

    public function testUnknownTargetsChangeNothing(): void
    {
        $entry = $this->show()->setStatus(CollectionItem::WANT);

        foreach (['', 's3', 's1e0', 's1e4', 's2e3', 'e1', 's1e', 'x', 's-1', 'all '] as $bad) {
            $this->assertFalse($entry->toggleWatched($bad), $bad);
        }
        $this->assertSame([0, CollectionItem::WANT], [$entry->getWatchedTotal(), $entry->getStatus()]);
    }
}
