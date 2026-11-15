<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Workspace names are labels, not identifiers. Self-service registration derives a
 * workspace name from the account name, so two people with the same display name
 * must be able to own two different workspaces.
 */
final class Version20261115090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow duplicate workspace names: replace the unique constraint with a lookup index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_workspaces_name');
        $this->addSql('CREATE INDEX idx_workspaces_name ON workspaces (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_workspaces_name');
        $this->addSql('CREATE UNIQUE INDEX uniq_workspaces_name ON workspaces (name)');
    }
}
