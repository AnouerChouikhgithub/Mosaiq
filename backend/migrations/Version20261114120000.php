<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auth credentials, board sharing, presence and timeline snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD api_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_api_token_hash ON users (api_token_hash)');
        $this->addSql('ALTER TABLE boards ADD public_access BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE board_members ADD role VARCHAR(16) DEFAULT 'Viewer' NOT NULL");

        $this->addSql('ALTER TABLE comment_threads ADD author_id UUID DEFAULT NULL');
        $this->addSql('UPDATE comment_threads SET author_id = (SELECT comment_messages.author_id FROM comment_messages WHERE comment_messages.thread_id = comment_threads.id ORDER BY comment_messages.created_at ASC LIMIT 1) WHERE author_id IS NULL');
        $this->addSql('UPDATE comment_threads SET author_id = (SELECT board_members.user_id FROM board_members WHERE board_members.board_id = comment_threads.board_id LIMIT 1) WHERE author_id IS NULL');
        $this->addSql('ALTER TABLE comment_threads ALTER author_id SET NOT NULL');
        $this->addSql('ALTER TABLE comment_threads ADD CONSTRAINT fk_comment_threads_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE timeline_entries ADD snapshot JSON DEFAULT NULL');

        $this->addSql('CREATE TABLE board_presence (id UUID NOT NULL, board_id UUID NOT NULL, user_id UUID NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, tool VARCHAR(24) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_presence ON board_presence (board_id, user_id)');
        $this->addSql('CREATE INDEX idx_board_presence_updated ON board_presence (updated_at)');
        $this->addSql('ALTER TABLE board_presence ADD CONSTRAINT fk_board_presence_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board_presence ADD CONSTRAINT fk_board_presence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_presence');
        $this->addSql('ALTER TABLE timeline_entries DROP snapshot');
        $this->addSql('ALTER TABLE comment_threads DROP CONSTRAINT fk_comment_threads_author');
        $this->addSql('ALTER TABLE comment_threads DROP author_id');
        $this->addSql('ALTER TABLE board_members DROP role');
        $this->addSql('ALTER TABLE boards DROP public_access');
        $this->addSql('DROP INDEX uniq_users_api_token_hash');
        $this->addSql('ALTER TABLE users DROP api_token_hash');
        $this->addSql('ALTER TABLE users DROP password_hash');
    }
}
