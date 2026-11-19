<?php

namespace App\Tests\Service;

use App\Entity\Collections;
use App\Entity\User;
use App\Repository\CollectionsRepository;
use App\Service\CollectionBulkEditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CollectionBulkEditorTest extends TestCase
{
    private function collection(bool $public): Collections
    {
        return (new Collections())->setName('c')->setPublic($public);
    }

    public function testPublicAndPrivateSetTheFlagOnEveryFoundCollectionAndSaveOnce(): void
    {
        $owner = new User();
        $a = $this->collection(false);
        $b = $this->collection(true);
        $repo = $this->createMock(CollectionsRepository::class);
        $repo->expects($this->exactly(2))->method('findBy')->with(['id' => ['1', '2'], 'owner' => $owner])->willReturn([$a, $b]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->never())->method('remove');
        $editor = new CollectionBulkEditor($repo, $em);

        $this->assertSame(2, $editor->apply('public', ['1', '2'], $owner));
        $this->assertTrue($a->isPublic() && $b->isPublic());

        $this->assertSame(2, $editor->apply('private', ['1', '2'], $owner));
        $this->assertFalse($a->isPublic() || $b->isPublic());
    }

    public function testRemoveDeletesEachFoundCollection(): void
    {
        $owner = new User();
        $a = $this->collection(true);
        $b = $this->collection(false);
        $repo = $this->createStub(CollectionsRepository::class);   // no expectations of its own: it only answers
        $repo->method('findBy')->willReturn([$a, $b]);
        $removed = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $o) use (&$removed) { $removed[] = $o; });
        $em->expects($this->once())->method('flush');

        $this->assertSame(2, (new CollectionBulkEditor($repo, $em))->apply('remove', ['1', '2'], $owner));
        $this->assertSame([$a, $b], $removed);
    }

    public function testTheLookupIsScopedToTheOwnerSoOtherPeoplesIdsAreIgnored(): void
    {
        $me = new User();
        $repo = $this->createMock(CollectionsRepository::class);
        // somebody else's collection id is in the request, but the repository only answers for what $me owns
        $repo->expects($this->once())->method('findBy')->with($this->callback(fn (array $criteria) => $criteria['owner'] === $me && $criteria['id'] === ['1', '99']))->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $this->assertSame(0, (new CollectionBulkEditor($repo, $em))->apply('remove', ['1', '99'], $me));
    }

    public function testNothingSelectedDoesNothingAtAll(): void
    {
        $repo = $this->createMock(CollectionsRepository::class);
        $repo->expects($this->never())->method('findBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->assertSame(0, (new CollectionBulkEditor($repo, $em))->apply('private', [], new User()));
    }

    public function testAnUnknownActionIsRefusedBeforeAnythingIsLoaded(): void
    {
        $repo = $this->createMock(CollectionsRepository::class);
        $repo->expects($this->never())->method('findBy');
        $editor = new CollectionBulkEditor($repo, $this->createStub(EntityManagerInterface::class));

        foreach (['', 'delete', 'PUBLIC', 'public ', 'archive'] as $bad) {
            try {
                $editor->apply($bad, ['1'], new User());
                $this->fail("'$bad' should have been refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
