<?php

namespace App\Tests\Service;

use App\Entity\CollectionItem;
use App\Entity\Collections;
use App\Entity\Items;
use App\Service\CollectionCover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CollectionCoverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hoardhq-covers-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        is_dir($this->dir) && rmdir($this->dir);
    }

    private function upload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        // a real 1x1 PNG: the extension of the stored file comes from its content
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'my cover.exe', null, null, true); // the client's name is never used
    }

    private function entry(Collections $collection, int $id, ?string $poster): CollectionItem
    {
        $entry = (new CollectionItem())->setCollection($collection)->setItem((new Items())->setName("Item $id")->setAttributes(['poster' => $poster]));
        (new \ReflectionProperty($entry, 'id'))->setValue($entry, $id);
        $collection->getEntries()->add($entry);

        return $entry;
    }

    public function testAnUploadedPictureBecomesTheCoverAndReplacesThePreviousOne(): void
    {
        $cover = new CollectionCover($this->dir);
        $collection = new Collections();

        $cover->apply($collection, CollectionCover::DEFAULT, $this->upload());
        $first = $collection->getThumbnail();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}\.png$/', $first);
        $this->assertFileExists("$this->dir/$first");
        $this->assertSame("/uploads/covers/$first", $collection->getCoverUrl());
        $this->assertSame(CollectionCover::UPLOAD, CollectionCover::choiceOf($collection));

        $cover->apply($collection, CollectionCover::UPLOAD, $this->upload());
        $this->assertFileDoesNotExist("$this->dir/$first");
        $this->assertFileExists("$this->dir/{$collection->getThumbnail()}");
    }

    public function testKeepingThePictureChoiceKeepsTheFile(): void
    {
        $cover = new CollectionCover($this->dir);
        $collection = new Collections();
        $cover->apply($collection, CollectionCover::UPLOAD, $this->upload());
        $name = $collection->getThumbnail();

        $cover->apply($collection, CollectionCover::UPLOAD, null);

        $this->assertSame($name, $collection->getThumbnail());
        $this->assertFileExists("$this->dir/$name");
    }

    public function testAnItemsPosterCanBeTheCoverAndDropsAnUploadedPicture(): void
    {
        $cover = new CollectionCover($this->dir);
        $collection = new Collections();
        $cover->apply($collection, CollectionCover::UPLOAD, $this->upload());
        $file = $collection->getThumbnail();
        $entry = $this->entry($collection, 7, 'https://img/alien.jpg');

        $cover->apply($collection, 'item-7', null);

        $this->assertSame($entry, $collection->getCoverEntry());
        $this->assertSame('https://img/alien.jpg', $collection->getCoverUrl());
        $this->assertNull($collection->getThumbnail());
        $this->assertFileDoesNotExist("$this->dir/$file");
        $this->assertSame('item-7', CollectionCover::choiceOf($collection));
    }

    public function testOnlyAnItemOfThisCollectionWithAPosterCanBeTheCover(): void
    {
        $cover = new CollectionCover($this->dir);
        $collection = new Collections();
        $this->entry($collection, 1, null);
        $this->entry(new Collections(), 2, 'https://img/x.jpg'); // another collection's item

        foreach (['item-1', 'item-2', 'item-99', 'item-x', 'nonsense'] as $choice) {
            $cover->apply($collection, $choice, null);
            $this->assertNull($collection->getCoverUrl(), $choice);
            $this->assertSame(CollectionCover::DEFAULT, CollectionCover::choiceOf($collection), $choice);
        }
    }

    public function testTheDefaultIconClearsEverythingAndItsFile(): void
    {
        $cover = new CollectionCover($this->dir);
        $collection = new Collections();
        $cover->apply($collection, CollectionCover::DEFAULT, $this->upload());
        $file = $collection->getThumbnail();

        $cover->apply($collection, CollectionCover::DEFAULT, null);

        $this->assertNull($collection->getCoverUrl());
        $this->assertFileDoesNotExist("$this->dir/$file");
    }

    public function testChoosingThePictureWhenThereIsNoneFallsBackToTheDefaultIcon(): void
    {
        $collection = new Collections();

        (new CollectionCover($this->dir))->apply($collection, CollectionCover::UPLOAD, null);

        $this->assertNull($collection->getCoverUrl());
    }

    public function testOnlyJpegPngAndWebpPicturesAreAccepted(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($text, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->assertNull(CollectionCover::imageExtension($text));
        $this->assertNull(CollectionCover::imageExtension('/no/such/file'));
        $this->assertSame('png', CollectionCover::imageExtension($this->upload()->getPathname()));
        $this->expectException(\InvalidArgumentException::class);
        (new CollectionCover($this->dir))->apply(new Collections(), CollectionCover::UPLOAD, new UploadedFile($text, 'x.png', null, null, true));
    }

    public function testOnlyFilesThisClassMadeAreEverDeleted(): void
    {
        mkdir($this->dir);
        file_put_contents("$this->dir/keep.txt", 'x');
        $collection = (new Collections())->setThumbnail('../keep.txt');

        (new CollectionCover($this->dir))->deleteFile($collection);
        $collection->setThumbnail('keep.txt');
        (new CollectionCover($this->dir))->deleteFile($collection);

        $this->assertFileExists("$this->dir/keep.txt");
    }
}
