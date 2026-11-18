<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users: follow other users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_follows (follower_id INT NOT NULL, followed_id INT NOT NULL, INDEX IDX_136E9479AC24F853 (follower_id), INDEX IDX_136E9479D956F010 (followed_id), PRIMARY KEY (follower_id, followed_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_follows ADD CONSTRAINT FK_136E9479AC24F853 FOREIGN KEY (follower_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_follows ADD CONSTRAINT FK_136E9479D956F010 FOREIGN KEY (followed_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_follows');
    }
}
