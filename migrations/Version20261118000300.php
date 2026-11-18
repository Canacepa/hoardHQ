<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collections: followers join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE collections_followers (collections_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_E688696E242C7AD2 (collections_id), INDEX IDX_E688696EA76ED395 (user_id), PRIMARY KEY (collections_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE collections_followers ADD CONSTRAINT FK_COLLFOLLOW_COLL FOREIGN KEY (collections_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collections_followers ADD CONSTRAINT FK_COLLFOLLOW_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE collections_followers');
    }
}
