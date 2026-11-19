<?php

namespace App\Tests\Entity;

use App\Entity\Categories;
use App\Entity\Collections;
use PHPUnit\Framework\TestCase;

final class CollectionsTest extends TestCase
{
    private function in(?string $category, bool $active = true): Collections
    {
        $collection = new Collections();

        return $category ? $collection->setCategory((new Categories())->setName($category)->setActive($active)) : $collection;
    }

    public function testTheCategoryDecidesWhatCanBeAdded(): void
    {
        $this->assertSame('movie', $this->in('Movies')->getImportType());
        $this->assertSame('tv', $this->in('TV Shows')->getImportType());
        $this->assertTrue($this->in('Movies')->isImportable());
    }

    public function testCategoriesWithoutAnItemApiCannotImport(): void
    {
        foreach (['Books', 'Video Games', 'Board Games', 'Comics & Manga'] as $category) {
            $this->assertNull($this->in($category)->getImportType(), $category);
            $this->assertFalse($this->in($category)->isImportable(), $category);
        }
        $this->assertNull($this->in(null)->getImportType());
    }

    public function testAnInactiveCategoryCountsAsNoCategory(): void
    {
        $this->assertNull($this->in('Movies', active: false)->getImportType());
    }
}
