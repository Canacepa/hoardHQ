<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116225443 extends AbstractMigration
{
    private const CATEGORIES = [
        'Trading Cards', 'Coins & Currency', 'Stamps', 'Comics & Manga', 'Books',
        'Vinyl & Music', 'Video Games', 'Toys & Figurines', 'Action Figures',
        'Art & Prints', 'Memorabilia', 'Antiques', 'Watches & Jewelry',
        'Clothing & Sneakers', 'Board Games', 'Other',
    ];

    public function getDescription(): string
    {
        return 'Seed starter categories';
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
