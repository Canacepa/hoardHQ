<?php

namespace App\EventListener;

use App\Entity\Collections;
use App\Service\CollectionCover;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;

/** Deletes a collection's uploaded cover from disk whenever the collection is removed (one by one or in bulk). */
#[AsEntityListener(event: Events::postRemove, entity: Collections::class)]
final class CollectionCoverCleanup
{
    public function __construct(private CollectionCover $cover)
    {
    }

    public function postRemove(Collections $collection, PostRemoveEventArgs $event): void
    {
        $this->cover->deleteFile($collection);
    }
}
