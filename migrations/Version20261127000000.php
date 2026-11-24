<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collections.cover_entry_id (the item whose poster is the collection cover)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD cover_entry_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE62338F19 FOREIGN KEY (cover_entry_id) REFERENCES collection_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D325D3EE62338F19 ON collections (cover_entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP FOREIGN KEY FK_D325D3EE62338F19');
        $this->addSql('DROP INDEX IDX_D325D3EE62338F19 ON collections');
        $this->addSql('ALTER TABLE collections DROP cover_entry_id');
    }
}
