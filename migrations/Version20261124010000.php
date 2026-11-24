<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collection_item.platform (the platform a game entry is for)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_item ADD platform VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_item DROP platform');
    }
}
