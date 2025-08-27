<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\PersistentResource;
use Passcreator\GenerativeEngineOptimization\Service\LLMGeneratorService;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Passcreator\GenerativeEngineOptimization\Service\LanguageDetectionService;

class LLMController extends ActionController
{
    /**
     * @Flow\Inject
     * @var LLMGeneratorService
     */
    protected $llmGeneratorService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    // Temporarily commented out for debugging
    // /**
    //  * @Flow\Inject
    //  * @var \Passcreator\GenerativeEngineOptimization\Service\LanguageDetectionService
    //  */
    // protected $languageDetectionService;

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['text/plain'];

    /**
     * Regenerate LLM files
     *
     * @return void
     */
    public function regenerateAction(): void
    {
        try {
            // Get request host for URL generation
            $requestHost = $this->request->getHttpRequest()->getUri()->getHost();
            
            // Regenerate all files
            $this->llmGeneratorService->generateAllFiles($requestHost);
            
            // Return success message
            $this->response->setContent('LLM files regenerated successfully');
            $this->response->setStatusCode(200);
            $this->response->setHeader('Content-Type', 'text/plain');
        } catch (\Exception $e) {
            $this->response->setContent('Error regenerating files: ' . $e->getMessage());
            $this->response->setStatusCode(500);
            $this->response->setHeader('Content-Type', 'text/plain');
        }
    }

    /**
     * Serve llms.txt file
     *
     * @return void
     */
    public function llmsAction(): void
    {
        $this->serveFile('llms.txt');
    }

    /**
     * Serve llms-full.txt file
     *
     * @return void
     */
    public function llmsFullAction(): void
    {
        $this->serveFile('llms-full.txt');
    }

    /**
     * Test action to verify controller is working
     *
     * @return string
     */
    public function testAction(): string
    {
        return 'LLM Controller is working! Package loaded successfully.';
    }

    /**
     * Serve LLMS file based on current domain and dimensions
     *
     * @param string $filename
     * @return void
     */
    protected function serveFile(string $filename): void
    {
        try {
            // Get all sites and use the first one for now
            $sites = $this->siteRepository->findAll();
            
            if ($sites->count() === 0) {
                $this->throwStatus(500, 'No sites found in the system');
                return;
            }
            
            $site = $sites->getFirst();
            $dimensions = $this->detectDimensions();

            $resource = $this->llmGeneratorService->getLLMSFileResource($filename, $site->getNodeName(), $dimensions);
            
            if (!$resource) {
                $this->llmGeneratorService->generateAllFiles();
                $resource = $this->llmGeneratorService->getLLMSFileResource($filename, $site->getNodeName(), $dimensions);
            }

            if (!$resource) {
                $this->throwStatus(404, 'File not found');
                return;
            }

            $this->response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
            $this->response->setHeader('Cache-Control', 'public, max-age=3600');
            
            $stream = $resource->getStream();
            if ($stream) {
                $content = stream_get_contents($stream);
                fclose($stream);
                $this->response->setContent($content);
            } else {
                $this->throwStatus(500, 'Could not read file');
            }
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Detect current dimensions from request
     *
     * @return array
     */
    protected function detectDimensions(): array
    {
        $dimensions = [];
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        
        foreach ($presets as $dimensionName => $dimensionConfiguration) {
            $preset = $this->detectDimensionPreset($dimensionName);
            if ($preset !== null) {
                $dimensions[$dimensionName] = [$preset];
            }
        }
        
        return $dimensions;
    }

    /**
     * Detect dimension preset from request
     *
     * @param string $dimensionName
     * @return string|null
     */
    protected function detectDimensionPreset(string $dimensionName): ?string
    {
        if ($dimensionName === 'language') {
            // Temporary fallback while debugging
            $requestPath = $this->request->getHttpRequest()->getUri()->getPath();
            
            if (strpos($requestPath, '/en/') === 0 || $requestPath === '/en') {
                return 'en';
            }
            
            return 'de';
        }
        
        return null;
    }
}