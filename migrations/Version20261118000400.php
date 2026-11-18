<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Items: belong to a collection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE items ADD collection_id INT NOT NULL');
        $this->addSql('ALTER TABLE items ADD CONSTRAINT FK_E11EE94D514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E11EE94D514956FD ON items (collection_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE items DROP FOREIGN KEY FK_E11EE94D514956FD');
        $this->addSql('DROP INDEX IDX_E11EE94D514956FD ON items');
        $this->addSql('ALTER TABLE items DROP collection_id');
    }
}
