<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bearer-token expiry and short-lived authentication attempt throttling.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD api_token_expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE TABLE auth_attempts (id UUID NOT NULL, action VARCHAR(32) NOT NULL, key_hash VARCHAR(64) NOT NULL, attempted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_auth_attempts_lookup ON auth_attempts (action, key_hash, attempted_at)');
        $this->addSql('CREATE INDEX idx_auth_attempts_time ON auth_attempts (attempted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_attempts');
        $this->addSql('ALTER TABLE users DROP api_token_expires_at');
    }
}
