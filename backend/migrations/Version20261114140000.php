<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add single-use, expiring board invitations stored by token hash.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_invitations (id UUID NOT NULL, board_id UUID NOT NULL, invited_by_id UUID NOT NULL, email VARCHAR(320) NOT NULL, role VARCHAR(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_invitation_token_hash ON board_invitations (token_hash)');
        $this->addSql('CREATE INDEX idx_board_invitations_board ON board_invitations (board_id)');
        $this->addSql('ALTER TABLE board_invitations ADD CONSTRAINT fk_board_invitations_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board_invitations ADD CONSTRAINT fk_board_invitations_invited_by FOREIGN KEY (invited_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_invitations');
    }
}
