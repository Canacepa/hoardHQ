<?php

namespace App\Service;

use App\Entity\Collections;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A collection's cover: nothing (the default icon), the poster of one of its items, or a picture the user uploaded
 * (kept in public/uploads/covers, its file name in Collections::$thumbnail). At most one of the two is set.
 *
 * The form offers it as one choice: "default", "upload" (the picture the collection already has) or "item-<entry id>",
 * plus a file input for a new picture.
 */
final class CollectionCover
{
    public const DEFAULT = 'default';
    public const UPLOAD = 'upload';

    public function __construct(#[Autowire('%kernel.project_dir%/public/uploads/covers')] private string $dir)
    {
    }

    /** The choice a collection is on now, to preselect it in the form. */
    public static function choiceOf(Collections $collection): string
    {
        return $collection->getThumbnail() ? self::UPLOAD : ($collection->getCoverEntry()?->getId() ? 'item-' . $collection->getCoverEntry()->getId() : self::DEFAULT);
    }

    /**
     * Applies what the form sent. A new file always wins (it is the picture); otherwise the choice decides: the current
     * picture is kept, an item of this collection with a poster becomes the cover, anything else means the default icon.
     * The uploaded file must already have been validated as an image.
     */
    public function apply(Collections $collection, string $choice, ?UploadedFile $upload): void
    {
        if ($upload !== null) {
            // the extension comes from the file's content, never from the client's file name
            $name = bin2hex(random_bytes(8)) . '.' . (self::imageExtension($upload->getPathname()) ?? throw new \InvalidArgumentException('Not a JPEG, PNG or WebP picture.'));
            $upload->move($this->dir, $name);
            $this->deleteFile($collection);
            $collection->setThumbnail($name)->setCoverEntry(null);

            return;
        }
        if ($choice === self::UPLOAD && $collection->getThumbnail()) {
            $collection->setCoverEntry(null);

            return;
        }

        // only an item of this collection, with a poster, can be its cover
        $entry = null;
        if (preg_match('/^item-(\d+)$/', $choice, $m)) {
            foreach ($collection->getEntries() as $candidate) {
                if ($candidate->getId() === (int) $m[1] && $candidate->getItem()?->getPoster()) {
                    $entry = $candidate;
                }
            }
        }
        $this->deleteFile($collection);
        $collection->setThumbnail(null)->setCoverEntry($entry);
    }

    /**
     * "jpg", "png" or "webp" for a picture of one of those kinds, null for anything else. Reads the file's header with
     * getimagesize(), which unlike MIME guessing needs no PHP extension.
     */
    public static function imageExtension(string $path): ?string
    {
        return match (@getimagesize($path)[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
    }

    /** Removes the uploaded picture's file, if any (the collection itself is not touched). */
    public function deleteFile(Collections $collection): void
    {
        $name = $collection->getThumbnail();
        // the name is one this class made; anything else in the column is never turned into a path
        if ($name && preg_match('/^[0-9a-f]{16}\.[a-z0-9]{2,5}$/', $name) && is_file($path = $this->dir . '/' . $name)) {
            unlink($path);
        }
    }
}
