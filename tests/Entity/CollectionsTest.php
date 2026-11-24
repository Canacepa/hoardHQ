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
        $this->assertSame('game', $this->in('Video Games')->getImportType());
        $this->assertSame('boardgame', $this->in('Board Games')->getImportType());
        $this->assertSame('book', $this->in('Books')->getImportType());
        $this->assertSame('comic', $this->in('Comics & Manga')->getImportType());
        $this->assertTrue($this->in('Movies')->isImportable());
        $this->assertTrue($this->in('Movies')->hasStatus());
        $this->assertSame(['want', 'owned'], array_keys($this->in('Video Games')->getStatusOptions()));
        $this->assertSame(['want', 'owned'], array_keys($this->in('Board Games')->getStatusOptions()));
        $this->assertSame(['want', 'owned', 'ebook'], array_keys($this->in('Comics & Manga')->getStatusOptions()));
        $this->assertTrue($this->in('Books')->hasReadFlag());
        $this->assertFalse($this->in('Movies')->hasReadFlag());
        $this->assertSame(['want', 'watched'], array_keys($this->in('TV Shows')->getStatusOptions()));
        $this->assertFalse($this->in('Vinyl & Music')->hasStatus());
    }

    public function testCategoriesWithoutAnItemApiCannotImport(): void
    {
        foreach (['Vinyl & Music', 'Toys & Figurines'] as $category) {
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
