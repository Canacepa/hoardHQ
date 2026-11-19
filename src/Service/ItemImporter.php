<?php

namespace App\Service;

use App\Entity\CollectionItem;
use App\Entity\Collections;
use App\Entity\Items;
use App\Repository\CollectionItemRepository;
use App\Repository\ItemsRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Adds an item from an external API to a collection, creating the shared catalog row only if it does not exist yet. */
final class ItemImporter
{
    public function __construct(
        private EntityManagerInterface $em,
        private ItemsRepository $items,
        private CollectionItemRepository $entries,
    ) {
    }

    /**
     * @param array{externalId: string, name: string, attributes: array<string, mixed>} $data trusted data from a provider, never from the request
     * @return bool false when the collection already has the item
     */
    public function add(Collections $collection, string $source, array $data): bool
    {
        // An existing catalog row is reused as is: items are not modified after creation.
        $item = $this->items->findOneBy(['source' => $source, 'externalId' => $data['externalId']])
            ?? (new Items())->setName($data['name'])->setSource($source)->setExternalId($data['externalId'])->setAttributes($data['attributes']);

        if ($item->getId() && $this->entries->findOneBy(['collection' => $collection, 'item' => $item])) {
            return false;
        }

        // shortcut: two simultaneous imports of the same new item hit the unique index and the loser gets a 500; handle when it shows up.
        $collection->touch();
        $this->em->persist((new CollectionItem())->setCollection($collection)->setItem($item));
        $this->em->flush();

        return true;
    }
}
