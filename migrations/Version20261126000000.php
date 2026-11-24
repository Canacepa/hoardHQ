<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126000000 extends AbstractMigration
{
    private const CATEGORIES = ['Books', 'Comics & Manga'];

    public function getDescription(): string
    {
        return 'Add collection_item.is_read and activate the Books and Comics & Manga categories (items come from Google Books)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_item ADD is_read TINYINT DEFAULT 0 NOT NULL');
        foreach (self::CATEGORIES as $name) {
            $this->addSql('UPDATE categories SET active = 1 WHERE name = ?', [$name]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CATEGORIES as $name) {
            $this->addSql('UPDATE categories SET active = 0 WHERE name = ?', [$name]);
        }
        $this->addSql('ALTER TABLE collection_item DROP is_read');
    }
}
