<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\CollectionsRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Applies one change to several of a user's collections at once: remove them, or make them public or private. */
final class CollectionBulkEditor
{
    public const ACTIONS = ['remove', 'public', 'private'];

    public function __construct(
        private CollectionsRepository $collections,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Only collections owned by $owner are touched: an id of anyone else's collection is silently ignored, so a forged request
     * cannot reach them. Removing deletes the collection with its entries (the shared catalog items stay).
     *
     * @param string[] $ids
     * @return int how many collections were changed
     */
    public function apply(string $action, array $ids, User $owner): int
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown bulk action "%s".', $action));
        }

        $found = $ids ? $this->collections->findBy(['id' => $ids, 'owner' => $owner]) : [];
        foreach ($found as $collection) {
            $action === 'remove' ? $this->em->remove($collection) : $collection->setPublic($action === 'public');
        }
        if ($found) {
            $this->em->flush();
        }

        return \count($found);
    }
}
