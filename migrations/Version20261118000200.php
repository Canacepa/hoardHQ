<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collections: add public flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD public TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP public');
    }
}
