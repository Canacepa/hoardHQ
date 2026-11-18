<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118231403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE collection_item (id INT AUTO_INCREMENT NOT NULL, notes LONGTEXT DEFAULT NULL, item_condition VARCHAR(50) DEFAULT NULL, collection_id INT NOT NULL, item_id INT NOT NULL, INDEX IDX_556C09F0514956FD (collection_id), INDEX IDX_556C09F0126F525E (item_id), UNIQUE INDEX uniq_collection_item (collection_id, item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0126F525E FOREIGN KEY (item_id) REFERENCES items (id)');
        $this->addSql('INSERT INTO collection_item (collection_id, item_id) SELECT collection_id, id FROM items');
        $this->addSql('ALTER TABLE items DROP FOREIGN KEY `FK_E11EE94D514956FD`');
        $this->addSql('DROP INDEX IDX_E11EE94D514956FD ON items');
        $this->addSql('DROP INDEX uniq_item_source_external ON items');
        $this->addSql('ALTER TABLE items DROP collection_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_source_external ON items (source, external_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collection_item DROP FOREIGN KEY FK_556C09F0514956FD');
        $this->addSql('ALTER TABLE collection_item DROP FOREIGN KEY FK_556C09F0126F525E');
        $this->addSql('DROP TABLE collection_item');
        $this->addSql('DROP INDEX uniq_item_source_external ON items');
        $this->addSql('ALTER TABLE items ADD collection_id INT NOT NULL');
        $this->addSql('ALTER TABLE items ADD CONSTRAINT `FK_E11EE94D514956FD` FOREIGN KEY (collection_id) REFERENCES collections (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E11EE94D514956FD ON items (collection_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_source_external ON items (collection_id, source, external_id)');
    }
}
