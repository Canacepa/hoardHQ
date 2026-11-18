<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000000 extends AbstractMigration
{
    private const CATEGORIES = ['Movies', 'TV Shows'];

    public function getDescription(): string
    {
        return 'Seed Movies and TV Shows categories';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CATEGORIES as $name) {
            $this->addSql('INSERT INTO categories (name) VALUES (?)', [$name]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CATEGORIES as $name) {
            $this->addSql('DELETE FROM categories WHERE name = ?', [$name]);
        }
    }
}
