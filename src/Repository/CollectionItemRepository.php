<?php

namespace App\Repository;

use App\Entity\CollectionItem;
use App\Entity\Collections;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CollectionItem>
 */
class CollectionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionItem::class);
    }

    /**
     * External ids the collection already has from $source.
     *
     * @return list<string>
     */
    public function externalIds(Collections $collection, string $source): array
    {
        return $this->createQueryBuilder('e')
            ->select('i.externalId')
            ->join('e.item', 'i')
            ->andWhere('e.collection = :collection')
            ->andWhere('i.source = :source')
            ->setParameter('collection', $collection)
            ->setParameter('source', $source)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Most recently added entries first, across all collections owned by $owner.
     *
     * @return CollectionItem[]
     */
    public function latestOwnedBy(User $owner, int $limit): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.collection', 'c')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
