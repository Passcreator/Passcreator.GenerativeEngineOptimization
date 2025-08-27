<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Http\Middleware;

use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use GuzzleHttp\Psr7\Response;
use Passcreator\GenerativeEngineOptimization\Service\LLMGeneratorService;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Log\LoggerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;

/**
 * HTTP Middleware to intercept llms.txt and llms-full.txt requests
 */
class LLMFileMiddleware implements MiddlewareInterface
{
    /**
     * @Flow\Inject
     * @var LLMGeneratorService
     */
    protected $llmGeneratorService;

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

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;


    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\ResourceManagement\ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        $this->logger->debug('LLMFileMiddleware processing request', ['path' => $path]);
        
        if ($path === '/llms.txt' || $path === '/llms-full.txt') {
            $this->logger->info('Handling LLMS file request', ['path' => $path]);
            return $this->handleLLMSRequest($request, $path);
        }
        
        return $handler->handle($request);
    }

    /**
     * Handle LLMS file request
     *
     * @param ServerRequestInterface $request
     * @param string $path
     * @return ResponseInterface
     */
    protected function handleLLMSRequest(ServerRequestInterface $request, string $path): ResponseInterface
    {
        $filename = ltrim($path, '/');

        try {
            // Get current site from domain
            $host = $request->getUri()->getHost();
            $site = null;
            
            $domain = $this->domainRepository->findOneByHost($host);
            
            if (!$domain) {
                // Try without port
                $hostWithoutPort = explode(':', $host)[0];
                $domain = $this->domainRepository->findOneByHost($hostWithoutPort);
            }
            
            if (!$domain) {
                // Fallback to first active domain
                $domains = $this->domainRepository->findByActive(true);
                $domain = $domains->getFirst();
            }

            if ($domain) {
                $site = $domain->getSite();
            }
            
            if (!$site) {
                // Try to use the first available site
                $sites = $this->siteRepository->findAll();
                if ($sites->count() > 0) {
                    $site = $sites->getFirst();
                    $this->logger->info('Using first available site', [
                        'siteName' => $site->getNodeName()
                    ]);
                }
            }

            if (!$site) {
                throw new \RuntimeException('No site found in the system');
            }
            
            // Check if force regeneration is requested
            $queryParams = $request->getQueryParams();
            $forceRegenerate = isset($queryParams['force']) || isset($queryParams['regenerate']);
            
            // Try to get content (either from existing resource or generate new)
            $dimensions = ['all' => true];
            $content = null;
            
            if (!$forceRegenerate) {
                $content = $this->llmGeneratorService->getLLMSFileContent($filename, $site->getNodeName(), $dimensions);
            }

            if (!$content || $forceRegenerate) {
                // Generate files from actual content if not found
                $this->logger->info('Content not found, generating from content', [
                    'filename' => $filename,
                    'siteName' => $site->getNodeName()
                ]);
                
                // Use security context to access nodes
                $this->securityContext->withoutAuthorizationChecks(function () use ($request) {
                    $requestHost = $request->getUri()->getHost();
                    $this->llmGeneratorService->generateAllFiles($requestHost);
                });
                
                // Ensure resources are persisted
                $this->persistenceManager->persistAll();
                
                // Try to get content again
                $content = $this->llmGeneratorService->getLLMSFileContent($filename, $site->getNodeName(), $dimensions);
                
                $this->logger->info('Content lookup after generation', [
                    'contentFound' => $content !== null,
                    'contentLength' => $content ? strlen($content) : 0
                ]);
            }
            
            if ($content) {
                $this->logger->info('Content retrieved successfully', [
                    'contentLength' => strlen($content),
                    'contentPreview' => substr($content, 0, 100)
                ]);
                
                return new Response(200, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Cache-Control' => 'public, max-age=3600',
                    'Pragma' => 'public',
                    'Expires' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT'
                ], $content);
            }
            
            throw new \RuntimeException('Failed to generate or retrieve LLM files');
        } catch (\Exception $e) {
            $this->logger->error('Failed to serve LLM file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Re-throw the exception to properly indicate the error
            throw $e;
        }
    }

    /**
     * Detect current dimensions from request
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    protected function detectDimensions(ServerRequestInterface $request): array
    {
        $dimensions = [];
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        
        foreach ($presets as $dimensionName => $dimensionConfiguration) {
            $preset = $this->detectDimensionPreset($dimensionName, $request);
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
     * @param ServerRequestInterface $request
     * @return string|null
     */
    protected function detectDimensionPreset(string $dimensionName, ServerRequestInterface $request): ?string
    {
        if ($dimensionName === 'language') {
            $requestPath = $request->getUri()->getPath();
            
            if (strpos($requestPath, '/en/') === 0 || $requestPath === '/en') {
                return 'en';
            }
            
            return 'de';
        }
        
        return null;
    }

    /**
     * Get hash for dimension combination
     *
     * @param array $dimensions
     * @return string
     */
    protected function getDimensionHash(array $dimensions): string
    {
        if (empty($dimensions)) {
            return 'default';
        }
        
        $dimensionString = '';
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            $dimensionString .= $dimensionName . '-' . implode('-', $dimensionValues);
        }
        
        return md5($dimensionString);
    }
}
