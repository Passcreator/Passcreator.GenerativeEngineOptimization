<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Passcreator\GenerativeEngineOptimization\Service\LLMGeneratorService;

/**
 * @Flow\Scope("singleton")
 */
class LLMTestCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var LLMGeneratorService
     */
    protected $llmGeneratorService;

    /**
     * Test basic LLM file generation
     *
     * @return void
     */
    public function testCommand(): void
    {
        $this->outputLine('Testing LLM file generation...');
        
        try {
            // Test basic content
            $testContent = "# Test LLM File\n\nThis is a test file generated at " . date('Y-m-d H:i:s') . "\n";
            
            // Get the generator service properties
            $siteRepository = $this->llmGeneratorService->getSiteRepository();
            $sites = $siteRepository->findAll();
            
            $this->outputLine('Found %d sites', [$sites->count()]);
            
            foreach ($sites as $site) {
                $this->outputLine('Site: %s', [$site->getNodeName()]);
            }
            
            // Try to store a test file directly
            $resourceManager = $this->llmGeneratorService->getResourceManager();
            $tempFile = tempnam(sys_get_temp_dir(), 'llmtest');
            file_put_contents($tempFile, $testContent);
            
            $resource = $resourceManager->importResource($tempFile, 'llms');
            if ($resource) {
                $this->outputLine('Test resource created: %s', [$resource->getSha1()]);
                $resource->setFilename('test-llms.txt');
                $resource->setMediaType('text/plain');
                
                // Persist
                $persistenceManager = $this->objectManager->get(\Neos\Flow\Persistence\PersistenceManagerInterface::class);
                $persistenceManager->persistAll();
                
                $this->outputLine('<success>Test file created successfully!</success>');
            } else {
                $this->outputLine('<error>Failed to create test resource</error>');
            }
            
            unlink($tempFile);
            
        } catch (\Exception $e) {
            $this->outputLine('<error>Error: %s</error>', [$e->getMessage()]);
            $this->outputLine($e->getTraceAsString());
        }
    }
}