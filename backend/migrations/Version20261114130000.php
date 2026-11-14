<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill board-specific roles on existing memberships.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE board_members SET role = users.role FROM users WHERE users.id = board_members.user_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE board_members SET role = 'Viewer'");
    }
}
