<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520161519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE categories_collections (categories_id INT NOT NULL, collections_id INT NOT NULL, INDEX IDX_DD5FD59CA21214B7 (categories_id), INDEX IDX_DD5FD59C242C7AD2 (collections_id), PRIMARY KEY (categories_id, collections_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collections (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, owner_id INT NOT NULL, INDEX IDX_D325D3EE7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collections_contributors (collections_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_143903F1242C7AD2 (collections_id), INDEX IDX_143903F1A76ED395 (user_id), PRIMARY KEY (collections_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collections_viewers (collections_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_E1016826242C7AD2 (collections_id), INDEX IDX_E1016826A76ED395 (user_id), PRIMARY KEY (collections_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE items (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, isbn INT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE items_categories (items_id INT NOT NULL, categories_id INT NOT NULL, INDEX IDX_A4EB785F6BB0AE84 (items_id), INDEX IDX_A4EB785FA21214B7 (categories_id), PRIMARY KEY (items_id, categories_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categories_collections ADD CONSTRAINT FK_DD5FD59CA21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categories_collections ADD CONSTRAINT FK_DD5FD59C242C7AD2 FOREIGN KEY (collections_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collections ADD CONSTRAINT FK_D325D3EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE collections_contributors ADD CONSTRAINT FK_143903F1242C7AD2 FOREIGN KEY (collections_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collections_contributors ADD CONSTRAINT FK_143903F1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collections_viewers ADD CONSTRAINT FK_E1016826242C7AD2 FOREIGN KEY (collections_id) REFERENCES collections (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collections_viewers ADD CONSTRAINT FK_E1016826A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE items_categories ADD CONSTRAINT FK_A4EB785F6BB0AE84 FOREIGN KEY (items_id) REFERENCES items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE items_categories ADD CONSTRAINT FK_A4EB785FA21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories_collections DROP FOREIGN KEY FK_DD5FD59CA21214B7');
        $this->addSql('ALTER TABLE categories_collections DROP FOREIGN KEY FK_DD5FD59C242C7AD2');
        $this->addSql('ALTER TABLE collections DROP FOREIGN KEY FK_D325D3EE7E3C61F9');
        $this->addSql('ALTER TABLE collections_contributors DROP FOREIGN KEY FK_143903F1242C7AD2');
        $this->addSql('ALTER TABLE collections_contributors DROP FOREIGN KEY FK_143903F1A76ED395');
        $this->addSql('ALTER TABLE collections_viewers DROP FOREIGN KEY FK_E1016826242C7AD2');
        $this->addSql('ALTER TABLE collections_viewers DROP FOREIGN KEY FK_E1016826A76ED395');
        $this->addSql('ALTER TABLE items_categories DROP FOREIGN KEY FK_A4EB785F6BB0AE84');
        $this->addSql('ALTER TABLE items_categories DROP FOREIGN KEY FK_A4EB785FA21214B7');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE categories_collections');
        $this->addSql('DROP TABLE collections');
        $this->addSql('DROP TABLE collections_contributors');
        $this->addSql('DROP TABLE collections_viewers');
        $this->addSql('DROP TABLE items');
        $this->addSql('DROP TABLE items_categories');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
