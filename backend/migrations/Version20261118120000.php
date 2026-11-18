<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixed-window counters for AI generation quotas. The table stores no prompt or
 * provider content: only a scope (user id or "deployment"), a window key, the
 * count and the window expiry.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add database-backed AI generation rate-limit counters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_generation_usage (scope VARCHAR(120) NOT NULL, window_key VARCHAR(16) NOT NULL, used_count INT NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(scope, window_key))');
        $this->addSql('CREATE INDEX idx_ai_generation_usage_expiry ON ai_generation_usage (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_generation_usage');
    }
}
