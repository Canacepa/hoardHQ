<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A collection has a single category: replace the categories_collections join table
 * by collections.category_id, keeping one category per collection (the lowest category id).
 */
final class Version20261118000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collections: many-to-many categories -> many-to-one category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD category_id INT DEFAULT NULL');
        $this->addSql('UPDATE collections c SET c.category_id = (SELECT MIN(cc.categories_id) FROM categories_collections cc WHERE cc.collections_id = c.id)');
        $this->addSql('ALTER TABLE categories_collections DROP FOREIGN KEY `FK_DD5FD59C242C7AD2`');
        $this->addSql('ALTER TABLE categories_collections DROP FOREIGN KEY `FK_DD5FD59CA21214B7`');
        $this->addSql('DROP TABLE categories_collections');
        $this->addSql('ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id)');
        $this->addSql('CREATE INDEX IDX_D325D3EE12469DE2 ON collections (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categories_collections (categories_id INT NOT NULL, collections_id INT NOT NULL, INDEX IDX_DD5FD59CA21214B7 (categories_id), INDEX IDX_DD5FD59C242C7AD2 (collections_id), PRIMARY KEY (categories_id, collections_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE categories_collections ADD CONSTRAINT `FK_DD5FD59C242C7AD2` FOREIGN KEY (collections_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_collections ADD CONSTRAINT `FK_DD5FD59CA21214B7` FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO categories_collections (categories_id, collections_id) SELECT category_id, id FROM collections WHERE category_id IS NOT NULL');
        $this->addSql('ALTER TABLE collections DROP FOREIGN KEY FK_D325D3EE12469DE2');
        $this->addSql('DROP INDEX IDX_D325D3EE12469DE2 ON collections');
        $this->addSql('ALTER TABLE collections DROP category_id');
    }
}
