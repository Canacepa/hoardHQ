<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activate the Board Games category (items come from BoardGameGeek)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET active = 1 WHERE name = 'Board Games'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET active = 0 WHERE name = 'Board Games'");
    }
}
