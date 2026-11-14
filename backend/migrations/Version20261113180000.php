<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Mosaiq whiteboard schema: workspaces, users, boards, canvas elements, comments and timeline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workspaces (id UUID NOT NULL, name VARCHAR(255) NOT NULL, plan VARCHAR(32) NOT NULL, price_per_member INT NOT NULL, currency VARCHAR(8) NOT NULL, seats INT NOT NULL, renews_on DATE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_workspaces_name ON workspaces (name)');

        $this->addSql('CREATE TABLE users (id UUID NOT NULL, workspace_id UUID NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(320) NOT NULL, role VARCHAR(16) NOT NULL, city VARCHAR(120) NOT NULL, accent VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');
        $this->addSql('CREATE INDEX idx_users_workspace ON users (workspace_id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT fk_users_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE boards (id UUID NOT NULL, workspace_id UUID NOT NULL, title VARCHAR(255) NOT NULL, starred BOOLEAN NOT NULL, is_template BOOLEAN NOT NULL, note_colors JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_boards_workspace ON boards (workspace_id)');
        $this->addSql('CREATE INDEX idx_boards_updated ON boards (updated_at)');
        $this->addSql('ALTER TABLE boards ADD CONSTRAINT fk_boards_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE board_members (board_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY(board_id, user_id))');
        $this->addSql('CREATE INDEX idx_board_members_user ON board_members (user_id)');
        $this->addSql('ALTER TABLE board_members ADD CONSTRAINT fk_board_members_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board_members ADD CONSTRAINT fk_board_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE canvas_elements (id UUID NOT NULL, board_id UUID NOT NULL, author_id UUID NOT NULL, type VARCHAR(16) NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, width DOUBLE PRECISION NOT NULL, height DOUBLE PRECISION NOT NULL, rotation DOUBLE PRECISION NOT NULL, content TEXT DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_canvas_elements_board ON canvas_elements (board_id)');
        $this->addSql('CREATE INDEX idx_canvas_elements_author ON canvas_elements (author_id)');
        $this->addSql('ALTER TABLE canvas_elements ADD CONSTRAINT fk_canvas_elements_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE canvas_elements ADD CONSTRAINT fk_canvas_elements_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE comment_threads (id UUID NOT NULL, board_id UUID NOT NULL, resolved BOOLEAN NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_comment_threads_board ON comment_threads (board_id)');
        $this->addSql('ALTER TABLE comment_threads ADD CONSTRAINT fk_comment_threads_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE comment_messages (id UUID NOT NULL, thread_id UUID NOT NULL, author_id UUID NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_comment_messages_thread ON comment_messages (thread_id)');
        $this->addSql('ALTER TABLE comment_messages ADD CONSTRAINT fk_comment_messages_thread FOREIGN KEY (thread_id) REFERENCES comment_threads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE comment_messages ADD CONSTRAINT fk_comment_messages_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE timeline_entries (id UUID NOT NULL, board_id UUID NOT NULL, author_id UUID NOT NULL, at TIMESTAMP(0) WITH TIME ZONE NOT NULL, label VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, reveals JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_timeline_entries_board ON timeline_entries (board_id)');
        $this->addSql('CREATE INDEX idx_timeline_entries_at ON timeline_entries (at)');
        $this->addSql('ALTER TABLE timeline_entries ADD CONSTRAINT fk_timeline_entries_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE timeline_entries ADD CONSTRAINT fk_timeline_entries_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE timeline_entries');
        $this->addSql('DROP TABLE comment_messages');
        $this->addSql('DROP TABLE comment_threads');
        $this->addSql('DROP TABLE canvas_elements');
        $this->addSql('DROP TABLE board_members');
        $this->addSql('DROP TABLE boards');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE workspaces');
    }
}
