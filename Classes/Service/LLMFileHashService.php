<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\Flow\Annotations as Flow;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Service for managing LLM file SHA1 hashes in custom table
 * 
 * @Flow\Scope("singleton")
 */
class LLMFileHashService
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Initialize the database connection
     *
     * @return void
     */
    public function initializeObject()
    {
        $this->connection = $this->objectManager->get(Connection::class);
    }

    /**
     * Get SHA1 hash for a specific LLM file
     *
     * @param string $filename
     * @param string $siteName
     * @param string $dimensionHash
     * @return string|null
     */
    public function getHash(string $filename, string $siteName, string $dimensionHash): ?string
    {
        try {
            $sql = 'SELECT sha1 FROM passcreator_llm_file_hashes WHERE filename = :filename AND site_name = :siteName AND dimension_hash = :dimensionHash';
            
            $statement = $this->connection->prepare($sql);
            $result = $statement->executeQuery([
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            
            $row = $result->fetchAssociative();
            
            if ($row && isset($row['sha1'])) {
                $this->logger->info('Found SHA1 hash for LLM file', [
                    'filename' => $filename,
                    'siteName' => $siteName,
                    'dimensionHash' => $dimensionHash,
                    'sha1' => $row['sha1']
                ]);
                return $row['sha1'];
            }
            
            $this->logger->info('No SHA1 hash found for LLM file', [
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get SHA1 hash from database', [
                'error' => $e->getMessage(),
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            return null;
        }
    }

    /**
     * Store SHA1 hash for a specific LLM file
     *
     * @param string $filename
     * @param string $siteName
     * @param string $dimensionHash
     * @param string $sha1
     * @return bool
     */
    public function storeHash(string $filename, string $siteName, string $dimensionHash, string $sha1): bool
    {
        try {
            $now = new \DateTime();
            $nowString = $now->format('Y-m-d H:i:s');
            
            // Try to update first
            $updateSql = 'UPDATE passcreator_llm_file_hashes SET sha1 = :sha1, updated_at = :updatedAt 
                          WHERE filename = :filename AND site_name = :siteName AND dimension_hash = :dimensionHash';
            
            $statement = $this->connection->prepare($updateSql);
            $rowsAffected = $statement->executeStatement([
                'sha1' => $sha1,
                'updatedAt' => $nowString,
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            
            // If no rows were updated, insert new record
            if ($rowsAffected === 0) {
                $insertSql = 'INSERT INTO passcreator_llm_file_hashes (filename, site_name, dimension_hash, sha1, created_at, updated_at) 
                              VALUES (:filename, :siteName, :dimensionHash, :sha1, :createdAt, :updatedAt)';
                
                $statement = $this->connection->prepare($insertSql);
                $statement->executeStatement([
                    'filename' => $filename,
                    'siteName' => $siteName,
                    'dimensionHash' => $dimensionHash,
                    'sha1' => $sha1,
                    'createdAt' => $nowString,
                    'updatedAt' => $nowString
                ]);
            }
            
            $this->logger->info('Stored SHA1 hash for LLM file', [
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash,
                'sha1' => $sha1
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to store SHA1 hash in database', [
                'error' => $e->getMessage(),
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash,
                'sha1' => $sha1
            ]);
            return false;
        }
    }

    /**
     * Delete SHA1 hash for a specific LLM file
     *
     * @param string $filename
     * @param string $siteName
     * @param string $dimensionHash
     * @return bool
     */
    public function deleteHash(string $filename, string $siteName, string $dimensionHash): bool
    {
        try {
            $sql = 'DELETE FROM passcreator_llm_file_hashes WHERE filename = :filename AND site_name = :siteName AND dimension_hash = :dimensionHash';
            
            $statement = $this->connection->prepare($sql);
            $rowsAffected = $statement->executeStatement([
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            
            $this->logger->info('Deleted SHA1 hash for LLM file', [
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash,
                'rowsAffected' => $rowsAffected
            ]);
            
            return $rowsAffected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete SHA1 hash from database', [
                'error' => $e->getMessage(),
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            return false;
        }
    }

    /**
     * Delete all hashes for a site
     *
     * @param string $siteName
     * @return int Number of deleted records
     */
    public function deleteAllHashesForSite(string $siteName): int
    {
        try {
            $sql = 'DELETE FROM passcreator_llm_file_hashes WHERE site_name = :siteName';
            
            $statement = $this->connection->prepare($sql);
            $rowsAffected = $statement->executeStatement([
                'siteName' => $siteName
            ]);
            
            $this->logger->info('Deleted all SHA1 hashes for site', [
                'siteName' => $siteName,
                'rowsAffected' => $rowsAffected
            ]);
            
            return $rowsAffected;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete SHA1 hashes for site', [
                'error' => $e->getMessage(),
                'siteName' => $siteName
            ]);
            return 0;
        }
    }
    
    /**
     * Clear all stored hashes
     *
     * @return int Number of records deleted
     */
    public function clearAllHashes(): int
    {
        try {
            $sql = 'DELETE FROM passcreator_llm_file_hashes';
            $statement = $this->connection->prepare($sql);
            $result = $statement->executeStatement();
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear all hashes', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Get all stored hashes
     *
     * @return array Array of hash records with filename, site_name, dimension_hash, and sha1
     */
    public function getAllHashes(): array
    {
        try {
            $sql = 'SELECT filename, site_name, dimension_hash, sha1 FROM passcreator_llm_file_hashes';
            $result = $this->connection->fetchAllAssociative($sql);
            
            return $result ?: [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get all hashes', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Get statistics about stored hashes
     *
     * @return array
     */
    public function getStatistics(): array
    {
        try {
            $stats = [
                'total' => 0,
                'bySite' => [],
                'byFile' => [],
                'lastUpdated' => null
            ];
            
            // Total count
            $sql = 'SELECT COUNT(*) as count FROM passcreator_llm_file_hashes';
            $result = $this->connection->fetchAssociative($sql);
            $stats['total'] = (int) $result['count'];
            
            // Count by site
            $sql = 'SELECT site_name, COUNT(*) as count FROM passcreator_llm_file_hashes GROUP BY site_name';
            $result = $this->connection->fetchAllAssociative($sql);
            foreach ($result as $row) {
                $stats['bySite'][$row['site_name']] = (int) $row['count'];
            }
            
            // Count by filename
            $sql = 'SELECT filename, COUNT(*) as count FROM passcreator_llm_file_hashes GROUP BY filename';
            $result = $this->connection->fetchAllAssociative($sql);
            foreach ($result as $row) {
                $stats['byFile'][$row['filename']] = (int) $row['count'];
            }
            
            // Last updated
            $sql = 'SELECT MAX(updated_at) as last_updated FROM passcreator_llm_file_hashes';
            $result = $this->connection->fetchAssociative($sql);
            if ($result['last_updated']) {
                $stats['lastUpdated'] = $result['last_updated'];
            }
            
            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get statistics', [
                'error' => $e->getMessage()
            ]);
            return [
                'total' => 0,
                'bySite' => [],
                'byFile' => [],
                'lastUpdated' => null
            ];
        }
    }
}
