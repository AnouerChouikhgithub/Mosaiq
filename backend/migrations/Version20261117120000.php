<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Client mutation ids make canvas creation idempotent. Existing elements keep
 * NULL keys: Postgres treats NULLs as distinct in a unique index, so the
 * constraint applies only to rows created through the idempotent path.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the client mutation id and payload fingerprint used to make canvas creation idempotent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE canvas_elements ADD mutation_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE canvas_elements ADD request_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_canvas_elements_mutation ON canvas_elements (board_id, author_id, mutation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_canvas_elements_mutation');
        $this->addSql('ALTER TABLE canvas_elements DROP request_hash');
        $this->addSql('ALTER TABLE canvas_elements DROP mutation_id');
    }
}
