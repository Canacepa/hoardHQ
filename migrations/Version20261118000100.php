<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collections: add updated_at (existing rows start at the migration time)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD updated_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE collections SET updated_at = NOW()');
        $this->addSql('ALTER TABLE collections MODIFY updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP updated_at');
    }
}
