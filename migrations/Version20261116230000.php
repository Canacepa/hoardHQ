<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add categories.active (default inactive)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD active TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories DROP active');
    }
}
