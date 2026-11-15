<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the active/archive/trash lifecycle to boards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE boards ADD lifecycle_status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE boards ADD archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE boards ADD trashed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_boards_workspace_lifecycle ON boards (workspace_id, lifecycle_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_boards_workspace_lifecycle');
        $this->addSql('ALTER TABLE boards DROP trashed_at');
        $this->addSql('ALTER TABLE boards DROP archived_at');
        $this->addSql('ALTER TABLE boards DROP lifecycle_status');
    }
}
