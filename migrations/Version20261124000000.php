<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activate the Video Games category (items come from IGDB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET active = 1 WHERE name = 'Video Games'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE categories SET active = 0 WHERE name = 'Video Games'");
    }
}
