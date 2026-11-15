<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workspace membership lifecycle metadata and hashed workspace invitations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD membership_status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE users ADD membership_updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE users SET membership_updated_at = created_at WHERE membership_updated_at IS NULL');
        $this->addSql('ALTER TABLE users ALTER membership_updated_at SET NOT NULL');
        $this->addSql('CREATE INDEX idx_users_workspace_status ON users (workspace_id, membership_status)');

        $this->addSql('CREATE TABLE workspace_invitations (id UUID NOT NULL, workspace_id UUID NOT NULL, invited_by_id UUID NOT NULL, email VARCHAR(320) NOT NULL, role VARCHAR(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_workspace_invitation_token_hash ON workspace_invitations (token_hash)');
        $this->addSql('CREATE INDEX idx_workspace_invitations_workspace ON workspace_invitations (workspace_id, created_at)');
        $this->addSql('CREATE INDEX idx_workspace_invitations_email ON workspace_invitations (workspace_id, email)');
        $this->addSql('CREATE INDEX idx_workspace_invitations_expiry ON workspace_invitations (expires_at)');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT fk_workspace_invitations_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT fk_workspace_invitations_invited_by FOREIGN KEY (invited_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workspace_invitations');
        $this->addSql('DROP INDEX idx_users_workspace_status');
        $this->addSql('ALTER TABLE users DROP membership_updated_at');
        $this->addSql('ALTER TABLE users DROP membership_status');
    }
}
