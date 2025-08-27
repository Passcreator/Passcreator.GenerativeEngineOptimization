<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create table for storing LLM file SHA1 hashes
 */
class Version20250128000001 extends AbstractMigration
{
    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Create table passcreator_llm_file_hashes to store SHA1 hashes for generated LLM files';
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        // Create the table for storing LLM file hashes
        $this->addSql('CREATE TABLE passcreator_llm_file_hashes (
            filename VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) NOT NULL,
            dimension_hash VARCHAR(32) NOT NULL,
            sha1 VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (filename, site_name, dimension_hash)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // Add indexes for faster lookups
        $this->addSql('CREATE INDEX IDX_LLM_SHA1 ON passcreator_llm_file_hashes (sha1)');
        $this->addSql('CREATE INDEX IDX_LLM_SITE ON passcreator_llm_file_hashes (site_name)');
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP TABLE passcreator_llm_file_hashes');
    }
}