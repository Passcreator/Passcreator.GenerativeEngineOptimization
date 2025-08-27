<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Repository\SiteRepository;
use Passcreator\GenerativeEngineOptimization\Service\LLMGeneratorService;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Passcreator\GenerativeEngineOptimization\Service\LLMFileHashService;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * @Flow\Scope("singleton")
 */
class LLMCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var LLMFileHashService
     */
    protected $fileHashService;

    /**
     * @Flow\Inject
     * @var LLMGeneratorService
     */
    protected $llmGeneratorService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ResourceRepository
     */
    protected $resourceRepository;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * Clear all LLM resources from storage
     *
     * This command removes all stored LLM files to force regeneration.
     *
     * @return void
     */
    public function clearCommand(): void
    {
        $this->outputLine('Clearing LLM resources...');

        try {
            // First get all hashes before clearing them
            $this->outputLine('Getting stored hashes...');
            $allHashes = $this->fileHashService->getAllHashes();
            $this->outputLine('Found %d stored hashes', [count($allHashes)]);
            
            // Delete resources based on SHA1 hashes
            $deletedCount = 0;
            foreach ($allHashes as $hashRecord) {
                $sha1 = $hashRecord['sha1'];
                $filename = $hashRecord['filename'];
                $siteName = $hashRecord['site_name'];
                
                // Find resource by SHA1
                $resource = $this->resourceManager->getResourceBySha1($sha1);
                if ($resource !== null) {
                    $this->outputLine('Deleting resource: %s (site: %s, SHA1: %s)', [$filename, $siteName, $sha1]);
                    $this->resourceManager->deleteResource($resource);
                    $deletedCount++;
                } else {
                    $this->outputLine('Resource not found for: %s (SHA1: %s)', [$filename, $sha1]);
                }
            }
            
            // Now clear database hashes
            $this->outputLine('Clearing database hashes...');
            $clearedHashes = $this->fileHashService->clearAllHashes();
            $this->outputLine('Cleared %d database hashes', [$clearedHashes]);

            // Persist changes
            $this->persistenceManager->persistAll();

            $this->outputLine('<success>Deleted %d LLM resources</success>', [$deletedCount]);
        } catch (\Exception $e) {
            $this->outputLine('<error>Error clearing LLM resources: %s</error>', [$e->getMessage()]);
        }
    }

    /**
     * Generate all LLMS files
     *
     * This command generates llms.txt and llms-full.txt files for all sites and dimensions.
     *
     * @param string $host Optional host parameter for URL generation
     * @return void
     */
    public function generateCommand(string $host = null): void
    {
        $this->outputLine('Generating LLMS files...');

        try {
            // Use security context to bypass authorization
            $this->securityContext->withoutAuthorizationChecks(function () use ($host) {
                $this->llmGeneratorService->generateAllFiles($host);
            });

            $this->outputLine('<success>LLMS files generated successfully!</success>');

            // Show generated files from hash table
            $this->outputLine('');
            $this->outputLine('Generated resources:');
            $allHashes = $this->fileHashService->getAllHashes();
            
            foreach ($allHashes as $hashRecord) {
                $sha1 = $hashRecord['sha1'];
                $filename = $hashRecord['filename'];
                $siteName = $hashRecord['site_name'];
                
                // Check if resource exists
                $resource = $this->resourceManager->getResourceBySha1($sha1);
                $status = $resource !== null ? 'OK' : 'MISSING';
                
                $this->outputLine('- %s (site: %s, SHA1: %s, Status: %s)', [
                    $filename,
                    $siteName,
                    $sha1,
                    $status
                ]);
            }
            
            if (count($allHashes) === 0) {
                $this->outputLine('No resources found in hash table');
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Error generating LLMS files: %s</error>', [$e->getMessage()]);
            $this->outputLine('<error>Stack trace:</error>');
            $this->outputLine($e->getTraceAsString());
        }
    }

    /**
     * List all LLM resources
     *
     * This command shows all stored LLM files.
     *
     * @return void
     */
    public function listCommand(): void
    {
        $this->outputLine('Listing LLM resources...');

        try {
            $allHashes = $this->fileHashService->getAllHashes();

            if (count($allHashes) === 0) {
                $this->outputLine('No LLM resources found in hash table');
                return;
            }

            $foundCount = 0;
            $missingCount = 0;
            
            foreach ($allHashes as $hashRecord) {
                $sha1 = $hashRecord['sha1'];
                $filename = $hashRecord['filename'];
                $siteName = $hashRecord['site_name'];
                $dimensionHash = $hashRecord['dimension_hash'];
                
                // Check if resource exists
                $resource = $this->resourceManager->getResourceBySha1($sha1);
                if ($resource !== null) {
                    $foundCount++;
                    $this->outputLine('- %s (site: %s, dimension: %s, SHA1: %s) ✓', [
                        $filename,
                        $siteName,
                        $dimensionHash,
                        $sha1
                    ]);
                } else {
                    $missingCount++;
                    $this->outputLine('- %s (site: %s, dimension: %s, SHA1: %s) ✗ MISSING', [
                        $filename,
                        $siteName,
                        $dimensionHash,
                        $sha1
                    ]);
                }
            }
            
            $this->outputLine('');
            $this->outputLine('Summary: %d found, %d missing', [$foundCount, $missingCount]);
        } catch (\Exception $e) {
            $this->outputLine('<error>Error listing LLM resources: %s</error>', [$e->getMessage()]);
        }
    }
    
    /**
     * Regenerate all LLM files
     *
     * This command will clear all cached files and regenerate llms.txt and llms-full.txt
     * for all sites and dimensions.
     *
     * @param string $host Optional host to use for URL generation
     * @return void
     */
    public function regenerateCommand(string $host = null): void
    {
        $this->outputLine('Starting LLM file regeneration...');
        
        try {
            // Use the clearCommand logic to properly delete resources
            $this->clearCommand();
            
            // Generate new files
            $this->outputLine('');
            $this->outputLine('Generating new LLM files...');
            $this->securityContext->withoutAuthorizationChecks(function () use ($host) {
                $this->llmGeneratorService->generateAllFiles($host);
            });

            // Persist changes
            $this->persistenceManager->persistAll();
            
            $this->outputLine('<success>LLM files regenerated successfully!</success>');
            $this->outputLine('');
            $this->outputLine('Files are now available at:');
            $this->outputLine('- https://%s/llms.txt', [$host]);
            $this->outputLine('- https://%s/llms-full.txt', [$host]);
        } catch (\Exception $e) {
            $this->outputLine('<error>Error regenerating LLM files: %s</error>', [$e->getMessage()]);
            $this->outputLine('<error>Stack trace:</error>');
            $this->outputLine($e->getTraceAsString());
        }
    }
    
    /**
     * Show SHA1 hashes stored in the database
     *
     * This command displays all LLM file hashes from the custom table.
     *
     * @return void
     */
    public function showHashesCommand(): void
    {
        $this->outputLine('Showing LLM file hashes from database...');

        try {
            // Query the database directly
            $connection = $this->objectManager->get(\Doctrine\DBAL\Connection::class);
            $sql = 'SELECT filename, site_name, dimension_hash, sha1, created_at, updated_at FROM passcreator_llm_file_hashes ORDER BY site_name, filename';

            $statement = $connection->prepare($sql);
            $result = $statement->executeQuery();

            $count = 0;
            while ($row = $result->fetchAssociative()) {
                $this->outputLine('');
                $this->outputLine('Filename: %s', [$row['filename']]);
                $this->outputLine('  Site: %s', [$row['site_name']]);
                $this->outputLine('  Dimension Hash: %s', [$row['dimension_hash']]);
                $this->outputLine('  SHA1: %s', [$row['sha1']]);
                $this->outputLine('  Created: %s', [$row['created_at']]);
                $this->outputLine('  Updated: %s', [$row['updated_at']]);
                $count++;
            }

            if ($count === 0) {
                $this->outputLine('No hashes found in database');
            } else {
                $this->outputLine('');
                $this->outputLine('<success>Total hashes: %d</success>', [$count]);
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Error showing hashes: %s</error>', [$e->getMessage()]);
        }
    }
}
