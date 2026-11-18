<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000100 extends AbstractMigration
{
    private const ACTIVE = ['Comics & Manga', 'Books', 'Video Games', 'Board Games', 'Movies', 'TV Shows'];

    public function getDescription(): string
    {
        return 'Activate initial categories';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIVE as $name) {
            $this->addSql('UPDATE categories SET active = 1 WHERE name = ?', [$name]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ACTIVE as $name) {
            $this->addSql('UPDATE categories SET active = 0 WHERE name = ?', [$name]);
        }
    }
}
