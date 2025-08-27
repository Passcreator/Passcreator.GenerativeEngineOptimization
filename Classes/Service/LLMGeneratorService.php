<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Psr\Log\LoggerInterface;
use Neos\Neos\Service\LinkingService;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Passcreator\GenerativeEngineOptimization\Service\CategorizationService;
use Passcreator\GenerativeEngineOptimization\Service\LanguageDetectionService;
use Passcreator\GenerativeEngineOptimization\Service\TranslationService;
use Passcreator\GenerativeEngineOptimization\Service\FullContentExclusionService;

/**
 * @Flow\Scope("singleton")
 */
class LLMGeneratorService
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var SiteRepository
     */
    #[Flow\Inject]
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

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
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var LLMFileHashService
     */
    protected $fileHashService;

    // Temporarily commented out for debugging
    // /**
    //  * @Flow\Inject
    //  * @var CategorizationService
    //  */
    // protected $categorizationService;

    // /**
    //  * @Flow\Inject
    //  * @var LanguageDetectionService
    //  */
    // protected $languageDetectionService;

    // /**
    //  * @Flow\Inject
    //  * @var TranslationService
    //  */
    // protected $translationService;

    /**
     * @Flow\Inject
     * @var FullContentExclusionService
     */
    protected $fullContentExclusionService;

    /**
     * @Flow\InjectConfiguration(path="additionalContent", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $additionalContent = [];

    /**
     * @Flow\InjectConfiguration(path="storage.collection", package="Passcreator.GenerativeEngineOptimization")
     * @var string
     */
    protected $storageCollection = 'persistent';

    /**
     * @Flow\InjectConfiguration(path="homePageNodeTypes", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $homePageNodeTypes = ['Neos.NodeTypes:Page', 'Neos.Neos:Document'];

    /**
     * @Flow\InjectConfiguration(path="fallbackDomain", package="Passcreator.GenerativeEngineOptimization")
     * @var string|null
     */
    protected $fallbackDomain = null;

    /**
     * @Flow\InjectConfiguration(path="siteDescription", package="Passcreator.GenerativeEngineOptimization")
     * @var string|null
     */
    protected $siteDescription = null;

    /**
     * @Flow\InjectConfiguration(path="siteDescriptions", package="Passcreator.GenerativeEngineOptimization")
     * @var array|null
     */
    protected $siteDescriptions = null;

    /**
     * @Flow\InjectConfiguration(path="fallbacks", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $fallbacks = [];


    /**
     * @var string|null
     */
    protected $requestHost = null;

    /**
     * Generate llms.txt content for all sites and dimensions
     *
     * @param string|null $requestHost The host from the request to use for URL generation
     * @return void
     */
    public function generateAllFiles(string $requestHost = null): void
    {
        try {
            $sites = $this->siteRepository->findAll();
            
            if ($sites->count() === 0) {
                $this->logger->error('No sites found in repository');
                throw new \RuntimeException('No sites found in repository');
            }
            
            $this->logger->info('Found sites', ['count' => $sites->count()]);
            
            foreach ($sites as $site) {
                $this->logger->info('Processing site', ['site' => $site->getNodeName()]);
                
                // Generate consolidated files that include all languages
                $this->generateConsolidatedFilesForSite($site, $requestHost);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate all files', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate consolidated llms.txt and llms-full.txt that include all languages
     *
     * @param \Neos\Neos\Domain\Model\Site $site
     * @param string|null $requestHost The host from the request to use for URL generation
     * @return void
     */
    protected function generateConsolidatedFilesForSite($site, string $requestHost = null): void
    {
        try {
            $this->logger->info('Generating consolidated files for site', [
                'site' => $site->getNodeName()
            ]);
            
            try {
                $llmsTxtContent = $this->generateConsolidatedLLMSTxt($site, $requestHost);
                $llmsFullTxtContent = $this->generateConsolidatedLLMSFullTxt($site, $requestHost);

                $this->logger->info('Generated consolidated content', [
                    'llmsTxtLength' => strlen($llmsTxtContent),
                    'llmsFullTxtLength' => strlen($llmsFullTxtContent)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to generate content', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            // Store with 'all' as dimension hash to indicate all languages
            $this->storeLLMSFile('llms.txt', $llmsTxtContent, $site->getNodeName(), 'all');
            $this->storeLLMSFile('llms-full.txt', $llmsFullTxtContent, $site->getNodeName(), 'all');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate consolidated files', [
                'site' => $site->getNodeName(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate llms.txt and llms-full.txt for a specific site and dimension
     *
     * @param \Neos\Neos\Domain\Model\Site $site
     * @param array $dimensions
     * @return void
     */
    protected function generateFilesForSiteAndDimension($site, array $dimensions): void
    {
        try {
            $this->logger->info('Starting generation for site', [
                'site' => $site->getNodeName(), 
                'dimensions' => $dimensions
            ]);
            
            $context = $this->contextFactory->create([
                'currentSite' => $site,
                'dimensions' => $dimensions,
                'targetDimensions' => array_map(function($dimensionValues) {
                    return array_shift($dimensionValues);
                }, $dimensions),
                'invisibleContentShown' => false,
                'removedContentShown' => false,
                'inaccessibleContentShown' => false,
                'workspaceName' => 'live',
                'currentDateTime' => new \DateTime()
            ]);

            $siteNode = $context->getNode('/sites/' . $site->getNodeName());
            if (!$siteNode) {
                $this->logger->warning('Site node not found', ['path' => '/sites/' . $site->getNodeName()]);
                return;
            }

            $llmsTxtContent = $this->generateLLMSTxt($siteNode);
            $llmsFullTxtContent = $this->generateLLMSFullTxt($siteNode);

            $this->logger->info('Generated content', [
                'llmsTxtLength' => strlen($llmsTxtContent),
                'llmsFullTxtLength' => strlen($llmsFullTxtContent)
            ]);

            $dimensionHash = $this->getDimensionHash($dimensions);
            
            $this->storeLLMSFile('llms.txt', $llmsTxtContent, $site->getNodeName(), $dimensionHash);
            $this->storeLLMSFile('llms-full.txt', $llmsFullTxtContent, $site->getNodeName(), $dimensionHash);
            
            $this->logger->info('Generated LLMS files', array_merge(
                LogEnvironment::fromMethodName(__METHOD__),
                ['site' => $site->getNodeName(), 'dimensions' => $dimensions]
            ));
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate LLMS files', array_merge(
                LogEnvironment::fromMethodName(__METHOD__),
                ['site' => $site->getNodeName(), 'dimensions' => $dimensions, 'error' => $e->getMessage()]
            ));
            throw $e; // Re-throw to see the error in the response
        }
    }

    /**
     * Generate llms.txt content
     *
     * @param NodeInterface $siteNode
     * @return string
     */
    protected function generateLLMSTxt(NodeInterface $siteNode): string
    {
        $siteName = $siteNode->getProperty('title') ?: $siteNode->getLabel();
        $siteDescription = $this->getNodeDescription($siteNode);
        
        // Determine language from node context - temporary fallback
        $context = $siteNode->getContext();
        $dimensions = $context->getDimensions();
        $language = 'en';
        if (!empty($dimensions['language'])) {
            $language = reset($dimensions['language']);
        }
        
        // Add additional context if provided
        $additionalContext = $siteNode->getProperty('llmContext');
        
        $content = "# {$siteName}\n\n";
        
        // Use language-specific configured description or fallback to node description
        $description = $this->getSiteDescriptionForLanguage($language, $siteDescription);
        if ($description) {
            $content .= "> {$description}\n\n";
        } else {
            // Use configurable fallback description
            $fallbackDescription = $this->fallbacks['siteDescription'] ?? 'A website built with Neos CMS';
            $content .= "> {$fallbackDescription}\n\n";
        }
        
        // Add additional context if available
        if ($additionalContext) {
            $content .= $additionalContext . "\n\n";
        }
        
        // Add configured additional content for llms.txt
        if (isset($this->additionalContent['simple']) && is_array($this->additionalContent['simple'])) {
            foreach ($this->additionalContent['simple'] as $section => $sectionContent) {
                $content .= "## " . $section . "\n\n";
                $content .= $sectionContent . "\n\n";
            }
        }
        
        // Collect and organize pages hierarchically
        $pageTree = $this->buildPageTree($siteNode);
        
        // Get language from context
        $context = $siteNode->getContext();
        $dimensions = $context->getDimensions();
        $language = 'en';
        if (!empty($dimensions['language'])) {
            $language = reset($dimensions['language']);
        }
        
        // Render the hierarchical structure
        $content .= $this->renderPageTree($pageTree, $siteNode, '', 2, $language);
        
        // Add optional section
        $optionalPages = $this->collectOptionalPages($siteNode);
        if (!empty($optionalPages)) {
            $content .= "## Optional\n\n";
            foreach ($optionalPages as $page) {
                $url = $this->getNodeUrl($page);
                $title = $page->getProperty('title') ?: $page->getLabel();
                $description = $this->getNodeDescription($page);
                
                if ($url) {
                    $content .= "- [{$title}]({$url})";
                    if ($description) {
                        $content .= ": {$description}";
                    }
                    $content .= "\n";
                }
            }
            $content .= "\n";
        }
        
        // Add shortcuts that are marked for inclusion
        $shortcuts = $this->collectLLMShortcuts($siteNode);
        if (!empty($shortcuts)) {
            $content .= "## External Resources\n\n";
            foreach ($shortcuts as $shortcut) {
                $target = $this->getShortcutTarget($shortcut);
                $title = $shortcut->getProperty('title') ?: $shortcut->getLabel();
                $description = $shortcut->getProperty('llmShortcutDescription');
                
                if ($target) {
                    $content .= "- [{$title}]({$target})";
                    if ($description) {
                        $content .= ": {$description}";
                    }
                    $content .= "\n";
                }
            }
            $content .= "\n";
        }
        
        $content .= "---\n";
        $content .= "Generated: " . (new \DateTime())->format('Y-m-d H:i:s T') . "\n";
        
        return $content;
    }

    /**
     * Generate llms-full.txt content
     *
     * @param NodeInterface $siteNode
     * @return string
     */
    protected function generateLLMSFullTxt(NodeInterface $siteNode): string
    {
        $siteName = $siteNode->getProperty('title') ?: $siteNode->getLabel();
        $siteDescription = $this->getNodeDescription($siteNode);
        
        // Determine language from node context - temporary fallback
        $context = $siteNode->getContext();
        $dimensions = $context->getDimensions();
        $language = 'en';
        if (!empty($dimensions['language'])) {
            $language = reset($dimensions['language']);
        }
        
        // Add additional context if provided
        $additionalContext = $siteNode->getProperty('llmContext');
        
        $content = "# {$siteName}\n\n";
        
        if ($siteDescription) {
            $content .= "> {$siteDescription}\n\n";
        }
        
        // Add additional context if available
        if ($additionalContext) {
            $content .= $additionalContext . "\n\n";
        }
        
        $content .= "This file contains detailed content for key pages on the {$siteName} website.\n\n";
        
        // Add configured additional content for llms-full.txt
        if (isset($this->additionalContent['full']) && is_array($this->additionalContent['full'])) {
            foreach ($this->additionalContent['full'] as $section => $sectionContent) {
                $content .= "## " . $section . "\n\n";
                $content .= $sectionContent . "\n\n";
            }
        }
        
        // Only collect pages that are explicitly marked for full content inclusion
        $pages = $this->collectPagesForFullContent($siteNode);
        
        // Temporary simplified grouping while debugging 
        $grouped = [
            'Core Features' => [],
            'Solutions' => [],
            'API & Developer Resources' => [],
            'Company Information' => [],
            'Other Resources' => []
        ];
        
        foreach ($pages as $page) {
            $path = $this->getNodePath($page);
            $nodeName = $page->getName();
            
            if (str_contains($path, '/features/') || $nodeName === 'features') {
                $grouped['Core Features'][] = $page;
            } elseif (str_contains($path, '/solutions/') || $nodeName === 'solutions') {
                $grouped['Solutions'][] = $page;
            } elseif (str_contains($path, '/api') || strpos($path, '/developer') !== false) {
                $grouped['API & Developer Resources'][] = $page;
            } elseif (str_contains($path, '/about') || strpos($path, '/company') !== false) {
                $grouped['Company Information'][] = $page;
            } else {
                $grouped['Other Resources'][] = $page;
            }
        }
        
        // Remove empty groups
        $groupedPages = array_filter($grouped, function($group) {
            return !empty($group);
        });
        
        foreach ($groupedPages as $groupTitle => $groupPages) {
            if (empty($groupPages)) continue;
            
            $content .= "## {$groupTitle}\n\n";
            
            foreach ($groupPages as $page) {
                $url = $this->getNodeUrl($page);
                $title = $page->getProperty('title') ?: $page->getLabel();
                $description = $this->getNodeDescription($page);
                
                $content .= "### [{$title}]({$url})\n\n";
                
                if ($description) {
                    $content .= "{$description}\n\n";
                }
                
                // Add additional context if available
                $pageContext = $page->getProperty('llmContext');
                if ($pageContext) {
                    $content .= $pageContext . "\n\n";
                }
                
                // Extract and add page content
                $pageContent = $this->extractPageContent($page);
                if ($pageContent) {
                    $content .= $pageContent . "\n\n";
                }
                
                $content .= "---\n\n";
            }
        }
        
        $content .= "Generated: " . (new \DateTime())->format('Y-m-d H:i:s T') . "\n";
        
        return $content;
    }

    /**
     * Get description for a node (prefers llmDescription over metaDescription)
     *
     * @param NodeInterface $node
     * @return string|null
     */
    protected function getNodeDescription(NodeInterface $node): ?string
    {
        $llmDescription = $node->getProperty('llmDescription');
        if ($llmDescription) {
            return trim($llmDescription);
        }
        
        $metaDescription = $node->getProperty('metaDescription');
        if ($metaDescription) {
            return trim($metaDescription);
        }
        
        return null;
    }
    
    /**
     * Check if a node is publicly accessible
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isNodeAccessible(NodeInterface $node): bool
    {
        // Check if node is hidden
        if ($node->isHidden()) {
            return false;
        }
        
        // Check if node is removed
        if ($node->isRemoved()) {
            return false;
        }
        
        // Skip check for uriPathSegment as some valid pages might have empty segments
        // (e.g., homepage or pages with special routing)
        
        // Check parent nodes are also accessible
        $parent = $node->getParent();
        if ($parent && !$parent->getNodeType()->isOfType('Neos.Neos:Sites')) {
            if ($parent->isHidden() || $parent->isRemoved()) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Build hierarchical page tree
     *
     * @param NodeInterface $parentNode
     * @param int $depth
     * @param bool $includeRoot Include the root node itself
     * @return array
     */
    protected function buildPageTree(NodeInterface $parentNode, int $depth = 0, bool $includeRoot = false): array
    {
        $tree = [];
        
        // Include the root node itself if requested (for site root pages)
        if ($includeRoot && $depth === 0 && $this->isNodeAccessible($parentNode)) {
            $tree[] = [
                'node' => $parentNode,
                'children' => []
            ];
        }
        
        // Increase depth limit to capture more pages
        if ($depth > 5) {
            return $tree;
        }
        
        $childNodes = $parentNode->getChildNodes('Neos.Neos:Document');
        
        $this->logger->info('Checking child nodes', [
            'parent' => $parentNode->getPath(),
            'childCount' => count($childNodes),
            'depth' => $depth
        ]);
        
        foreach ($childNodes as $childNode) {
            $this->logger->info('Processing child node', [
                'nodeName' => $childNode->getName(),
                'nodeType' => $childNode->getNodeType()->getName(),
                'path' => $childNode->getPath(),
                'isHidden' => $childNode->isHidden(),
                'isRemoved' => $childNode->isRemoved()
            ]);
            
            // Skip shortcuts in the main tree
            if ($childNode->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
                $this->logger->info('Skipping shortcut node', ['nodeName' => $childNode->getName()]);
                continue;
            }
            
            // Skip nodes marked for optional section
            if ($childNode->getProperty('llmIncludeInOptional') === true) {
                $this->logger->info('Skipping optional node', ['nodeName' => $childNode->getName()]);
                continue;
            }
            
            // Skip inaccessible nodes
            if (!$this->isNodeAccessible($childNode)) {
                $this->logger->info('Skipping inaccessible node', ['nodeName' => $childNode->getName()]);
                continue;
            }
            
            // Skip excluded page types
            if ($this->isExcludedPageType($childNode)) {
                $this->logger->info('Excluding page from llms.txt', [
                    'nodeName' => $childNode->getName(),
                    'nodeType' => $childNode->getNodeType()->getName(),
                    'path' => $childNode->getPath()
                ]);
                continue;
            }
            
            // Include all other accessible pages
            $nodeData = [
                'node' => $childNode,
                'children' => $this->buildPageTree($childNode, $depth + 1)
            ];
            
            $tree[] = $nodeData;
        }
        
        return $tree;
    }
    
    /**
     * Check if a page type should be excluded from llms.txt
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isExcludedPageType(NodeInterface $node): bool
    {
        $nodeName = strtolower($node->getName());
        $nodeType = $node->getNodeType()->getName();
        
        // Exclude footer pages
        if (strpos($nodeName, 'footer') !== false) {
            return true;
        }
        
        // Exclude 404 error pages
        if ($nodeName === '404' || strpos($nodeName, '404') !== false) {
            return true;
        }
        
        // Exclude pages that are marked as noindex
        if ($node->getProperty('metaRobotsNoindex') === true) {
            return true;
        }
        
        // You can add more exclusion rules here as needed
        
        return false;
    }
    
    /**
     * Get fallback homepage title for the given language
     *
     * @param string $language
     * @return string
     */
    protected function getFallbackHomePageTitle(string $language): string
    {
        $homePageTitles = $this->fallbacks['homePageTitle'] ?? [];
        return $homePageTitles[$language] ?? $homePageTitles['en'] ?? 'Homepage';
    }
    
    /**
     * Render page tree as markdown
     *
     * @param array $tree
     * @param NodeInterface $siteNode
     * @param string $parentTitle
     * @param int $level
     * @param string $language
     * @return string
     */
    protected function renderPageTree(array $tree, NodeInterface $siteNode, string $parentTitle = '', int $level = 2, string $language = 'en'): string
    {
        $content = '';
        
        // Group pages by section
        $sections = [];
        $uncategorized = [];
        $homePageItem = null;
        
        foreach ($tree as $item) {
            $node = $item['node'];
            
            // Check if this is the home page (site root)
            if ($node === $siteNode || 
                $node->getNodeType()->isOfType('Neos.Neos:Site') ||
                $this->isHomePageNodeType($node)) {
                $homePageItem = $item;
                continue;
            }
            
            // Temporary fallback to simple categorization while debugging
            $path = $this->getNodePath($node);
            
            // Determine section based on path or node properties
            if (strpos($path, '/features/') !== false || $node->getName() === 'features') {
                $sections['Features'][] = $item;
            } elseif (strpos($path, '/solutions/') !== false || $node->getName() === 'solutions') {
                $sections['Solutions'][] = $item;
            } elseif (strpos($path, '/api') !== false || $node->getName() === 'api') {
                $sections['API Documentation'][] = $item;
            } elseif (strpos($path, '/pricing') !== false || $node->getName() === 'pricing') {
                $sections['Pricing'][] = $item;
            } elseif ($node->getParent() === $siteNode) {
                // Main level pages
                $sections['Main Pages'][] = $item;
            } else {
                $uncategorized[] = $item;
            }
        }
        
        // Render sections
        foreach ($sections as $sectionTitle => $items) {
            if (empty($items)) continue;
            
            // Temporary fallback to simple translation while debugging
            $translations = [
                'Features' => ['en' => 'Features', 'de' => 'Funktionen'],
                'Solutions' => ['en' => 'Solutions', 'de' => 'Lösungen'],
                'API Documentation' => ['en' => 'API Documentation', 'de' => 'API-Dokumentation'],
                'Pricing' => ['en' => 'Pricing', 'de' => 'Preise'],
                'Main Pages' => ['en' => 'Main Pages', 'de' => 'Hauptseiten'],
                'Additional Resources' => ['en' => 'Additional Resources', 'de' => 'Weitere Ressourcen']
            ];
            $translatedTitle = $translations[$sectionTitle][$language] ?? $sectionTitle;
            $content .= str_repeat('#', $level) . " {$translatedTitle}\n\n";
            
            // If this is the Main Pages section and we have a home page, render it first
            if ($sectionTitle === 'Main Pages' && $homePageItem !== null) {
                $node = $homePageItem['node'];
                $url = $this->getNodeUrl($node);
                $title = $node->getProperty('title') ?: $this->getFallbackHomePageTitle($language);
                $description = $this->getNodeDescription($node);
                
                if ($url) {
                    $content .= "- [{$title}]({$url})";
                    if ($description) {
                        $content .= ": {$description}";
                    }
                    $content .= "\n";
                }
            }
            
            foreach ($items as $item) {
                $node = $item['node'];
                $url = $this->getNodeUrl($node);
                $title = $node->getProperty('title') ?: $node->getLabel();
                $description = $this->getNodeDescription($node);
                
                if ($url) {
                    $content .= "- [{$title}]({$url})";
                    if ($description) {
                        $content .= ": {$description}";
                    }
                    $content .= "\n";
                    
                    // Render children if they exist
                    if (!empty($item['children'])) {
                        foreach ($item['children'] as $child) {
                            $childNode = $child['node'];
                            $childUrl = $this->getNodeUrl($childNode);
                            $childTitle = $childNode->getProperty('title') ?: $childNode->getLabel();
                            $childDescription = $this->getNodeDescription($childNode);
                            
                            if ($childUrl) {
                                $content .= "  - [{$childTitle}]({$childUrl})";
                                if ($childDescription) {
                                    $content .= ": {$childDescription}";
                                }
                                $content .= "\n";
                            }
                        }
                    }
                }
            }
            $content .= "\n";
        }
        
        // Render uncategorized pages
        if (!empty($uncategorized)) {
            $translatedTitle = $this->getTranslatedSectionTitle('Additional Resources', $language);
            $content .= str_repeat('#', $level) . " {$translatedTitle}\n\n";
            foreach ($uncategorized as $item) {
                $node = $item['node'];
                $url = $this->getNodeUrl($node);
                $title = $node->getProperty('title') ?: $node->getLabel();
                $description = $this->getNodeDescription($node);
                
                if ($url) {
                    $content .= "- [{$title}]({$url})";
                    if ($description) {
                        $content .= ": {$description}";
                    }
                    $content .= "\n";
                }
            }
            $content .= "\n";
        }
        
        return $content;
    }
    
    /**
     * Collect pages marked for optional section
     *
     * @param NodeInterface $siteNode
     * @return array
     */
    protected function collectOptionalPages(NodeInterface $siteNode): array
    {
        $pages = [];
        $this->collectOptionalPagesRecursively($siteNode, $pages);
        return $pages;
    }
    
    /**
     * Recursively collect optional pages
     *
     * @param NodeInterface $node
     * @param array $pages
     * @return void
     */
    protected function collectOptionalPagesRecursively(NodeInterface $node, array &$pages): void
    {
        if ($node->getNodeType()->isOfType('Neos.Neos:Document') && 
            !$node->getNodeType()->isOfType('Neos.Neos:Shortcut') &&
            $node->getProperty('llmIncludeInOptional') === true) {
            $pages[] = $node;
        }
        
        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->collectOptionalPagesRecursively($childNode, $pages);
        }
    }
    
    /**
     * Collect shortcuts marked for LLM inclusion
     *
     * @param NodeInterface $siteNode
     * @return array
     */
    protected function collectLLMShortcuts(NodeInterface $siteNode): array
    {
        $shortcuts = [];
        $this->collectLLMShortcutsRecursively($siteNode, $shortcuts);
        return $shortcuts;
    }
    
    /**
     * Recursively collect LLM shortcuts
     *
     * @param NodeInterface $node
     * @param array $shortcuts
     * @return void
     */
    protected function collectLLMShortcutsRecursively(NodeInterface $node, array &$shortcuts): void
    {
        if ($node->getNodeType()->isOfType('Neos.Neos:Shortcut') &&
            $node->getProperty('llmIncludeShortcut') === true) {
            $shortcuts[] = $node;
        }
        
        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->collectLLMShortcutsRecursively($childNode, $shortcuts);
        }
    }
    
    /**
     * Get shortcut target URL
     *
     * @param NodeInterface $shortcut
     * @return string|null
     */
    protected function getShortcutTarget(NodeInterface $shortcut): ?string
    {
        $targetNode = $shortcut->getProperty('targetNode');
        if ($targetNode instanceof NodeInterface) {
            return $this->getNodeUrl($targetNode);
        }
        
        $targetUri = $shortcut->getProperty('targetUri') ?: $shortcut->getProperty('target');
        if ($targetUri) {
            // Ensure it's an absolute URL
            if (strpos($targetUri, 'http') !== 0 && strpos($targetUri, '/') === 0) {
                // Get domain for the site
                $site = $this->getSiteForNode($shortcut);
                if ($site) {
                    $domains = $this->domainRepository->findBySite($site);
                    if ($domains->count() > 0) {
                        $domain = $domains->getFirst();
                        $scheme = $domain->getScheme() ?: 'https';
                        $host = $domain->getHostname();
                        return $scheme . '://' . $host . $targetUri;
                    }
                }
            }
            return $targetUri;
        }
        
        return null;
    }

    /**
     * Collect all document pages that have a description
     *
     * @param NodeInterface $siteNode
     * @return array
     */
    protected function collectPagesWithDescription(NodeInterface $siteNode): array
    {
        $pages = [];
        $this->collectPagesRecursively($siteNode, $pages, true);
        return $pages;
    }

    /**
     * Collect all document pages
     *
     * @param NodeInterface $siteNode
     * @return array
     */
    protected function collectAllPages(NodeInterface $siteNode): array
    {
        $pages = [];
        $this->collectPagesRecursively($siteNode, $pages, false);
        return $pages;
    }

    /**
     * Recursively collect pages
     *
     * @param NodeInterface $node
     * @param array $pages
     * @param bool $requireDescription
     * @return void
     */
    protected function collectPagesRecursively(NodeInterface $node, array &$pages, bool $requireDescription = false): void
    {
        if ($node->getNodeType()->isOfType('Neos.Neos:Document') && !$node->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
            if (!$requireDescription || $this->getNodeDescription($node) !== null) {
                $pages[] = $node;
            }
        }
        
        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->collectPagesRecursively($childNode, $pages, $requireDescription);
        }
    }

    /**
     * Extract text content from a page
     *
     * @param NodeInterface $page
     * @return string
     */
    protected function extractPageContent(NodeInterface $page): string
    {
        $content = '';
        $mainContentNode = $page->getNode('main');
        
        if ($mainContentNode) {
            $this->extractTextFromNode($mainContentNode, $content);
        }
        
        return trim($content);
    }

    /**
     * Recursively extract text from node and its children
     *
     * @param NodeInterface $node
     * @param string $content
     * @return void
     */
    protected function extractTextFromNode(NodeInterface $node, string &$content): void
    {
        $nodeType = $node->getNodeType();
        
        if ($nodeType->isOfType('Neos.NodeTypes:Text') || $nodeType->isOfType('Neos.NodeTypes:Headline')) {
            $text = $node->getProperty('text');
            if ($text) {
                $content .= strip_tags($text) . "\n\n";
            }
        }
        
        foreach ($node->getChildNodes() as $childNode) {
            $this->extractTextFromNode($childNode, $content);
        }
    }

    /**
     * Get URL for a node
     *
     * @param NodeInterface $node
     * @return string|null
     */
    protected function getNodeUrl(NodeInterface $node): ?string
    {
        try {
            // Build path manually since we don't have controller context in middleware
            $path = '';
            $currentNode = $node;
            
            while ($currentNode && !$currentNode->getNodeType()->isOfType('Neos.Neos:Sites')) {
                // Skip the site node itself when building path
                if (!$currentNode->getNodeType()->isOfType('Neos.Neos:Site') && 
                    !$this->isHomePageNodeType($currentNode)) {
                    $segment = $currentNode->getProperty('uriPathSegment');
                    if ($segment && $currentNode->getParent()) {
                        $path = '/' . $segment . $path;
                    }
                }
                $currentNode = $currentNode->getParent();
            }
            
            // Add language prefix if needed
            $context = $node->getContext();
            $dimensions = $context->getDimensions();
            if (!empty($dimensions['language'])) {
                $language = reset($dimensions['language']);
                // Always add language prefix for clarity
                $path = '/' . $language . $path;
            }
            
            // Get domain for the site
            $site = $this->getSiteForNode($node);
            if ($site) {
                try {
                    $domains = $this->domainRepository->findBySite($site);
                    $this->logger->debug('Domain lookup for node URL', [
                        'nodeId' => $node->getIdentifier(),
                        'siteName' => $site ? $site->getName() : 'null',
                        'domainCount' => $domains->count()
                    ]);
                    if ($domains->count() > 0) {
                        $domain = $domains->getFirst();
                        $scheme = $domain->getScheme() ?: 'https';
                        $host = $domain->getHostname();
                        $absoluteUrl = $scheme . '://' . $host . ($path ?: '/');
                        $this->logger->debug('Generated absolute URL', [
                            'scheme' => $scheme,
                            'host' => $host,
                            'path' => $path,
                            'absoluteUrl' => $absoluteUrl
                        ]);
                        return $absoluteUrl;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to lookup domains for site', [
                        'siteName' => $site->getName(),
                        'error' => $e->getMessage()
                    ]);
                }

                // If no domain found, try to construct one
                if ($this->requestHost) {
                    $host = $this->requestHost;
                } else {
                    // Try to get any active domain from repository as fallback
                    $allDomains = $this->domainRepository->findByActive(true);
                    if ($allDomains->count() > 0) {
                        $fallbackDomain = $allDomains->getFirst();
                        $host = $fallbackDomain->getHostname();
                        $this->logger->info('Using fallback domain from repository', [
                            'host' => $host
                        ]);
                    } else {
                        // Last resort - use configured fallback domain
                        if ($this->fallbackDomain) {
                            $host = $this->fallbackDomain;
                            $this->logger->warning('No domain available, using configured fallback', [
                                'host' => $host,
                                'path' => $path
                            ]);
                        } else {
                            // No fallback configured - throw error
                            throw new \RuntimeException('No domain available and no fallbackDomain configured. Please configure a fallbackDomain in Settings.yaml or ensure proper domain configuration.');
                        }
                    }
                }
                
                $scheme = 'https';
                $absoluteUrl = $scheme . '://' . $host . ($path ?: '/');
                $this->logger->debug('Generated URL with fallback host', [
                    'scheme' => $scheme,
                    'host' => $host,
                    'path' => $path,
                    'absoluteUrl' => $absoluteUrl,
                    'requestHost' => $this->requestHost
                ]);
                return $absoluteUrl;
            }
            
            // Fallback - use hardcoded domain
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $absoluteUrl = $scheme . '://' . $host . ($path ?: '/');
            $this->logger->warning('No site found, using hardcoded domain', [
                'nodeId' => $node->getIdentifier(),
                'nodePath' => $node->getPath(),
                'host' => $host,
                'absoluteUrl' => $absoluteUrl
            ]);
            return $absoluteUrl;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to generate URL for node', [
                'node' => $node->getIdentifier(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Store LLMS file using Flow ResourceManager
     *
     * @param string $filename
     * @param string $content
     * @param string $siteName
     * @param string $dimensionHash
     * @return void
     */
    protected function storeLLMSFile(string $filename, string $content, string $siteName, string $dimensionHash): void
    {
        $resourceFilename = sprintf('%s-%s-%s', $siteName, $dimensionHash, $filename);
        
        $this->logger->info('Storing LLM file', [
            'filename' => $resourceFilename,
            'collection' => $this->storageCollection,
            'contentLength' => strlen($content)
        ]);
        
        try {
            // First check if a resource with this filename already exists and delete it
            $collection = $this->resourceManager->getCollection($this->storageCollection);
            if (!$collection) {
                throw new \RuntimeException('Collection not found: ' . $this->storageCollection);
            }
            
            // Skip deletion for now - just overwrite
            
            // Create file with content
            $tempFile = tempnam(sys_get_temp_dir(), 'llms');
            if (!file_put_contents($tempFile, $content)) {
                throw new \RuntimeException('Failed to write temp file: ' . $tempFile);
            }
            
            $resource = $this->resourceManager->importResource($tempFile, $this->storageCollection);
            if (!$resource) {
                throw new \RuntimeException('Failed to import resource');
            }
            
            $resource->setFilename($resourceFilename);
            $resource->setMediaType('text/plain');
            
            // Add resource to repository to ensure it's tracked
            $resourceRepository = $this->objectManager->get(\Neos\Flow\ResourceManagement\ResourceRepository::class);
            $resourceRepository->add($resource);
            
            // Persist the resource immediately
            $this->persistenceManager->persistAll();
            
            $this->logger->info('Stored LLM file successfully', [
                'filename' => $resourceFilename,
                'sha1' => $resource->getSha1()
            ]);
            
            // Store SHA1 hash in database
            $this->fileHashService->storeHash($filename, $siteName, $dimensionHash, $resource->getSha1());
            
            // Clean up temp file
            unlink($tempFile);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store LLM file', [
                'filename' => $resourceFilename,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if a node is a homepage node type
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isHomePageNodeType(NodeInterface $node): bool
    {
        if (empty($this->homePageNodeTypes) || !is_array($this->homePageNodeTypes)) {
            return false;
        }
        
        foreach ($this->homePageNodeTypes as $nodeType) {
            if ($node->getNodeType()->isOfType($nodeType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get configured site description for a specific language
     *
     * @param string $language
     * @param string|null $fallbackDescription
     * @return string|null
     */
    protected function getSiteDescriptionForLanguage(string $language, string $fallbackDescription = null): ?string
    {
        // Check language-specific descriptions first
        if (!empty($this->siteDescriptions) && is_array($this->siteDescriptions)) {
            if (isset($this->siteDescriptions[$language])) {
                return $this->siteDescriptions[$language];
            }
            
            // Try English as fallback
            if ($language !== 'en' && isset($this->siteDescriptions['en'])) {
                return $this->siteDescriptions['en'];
            }
        }
        
        // Fall back to single siteDescription configuration
        if (!empty($this->siteDescription)) {
            return $this->siteDescription;
        }
        
        // Finally use the provided fallback
        return $fallbackDescription;
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
            return 'all'; // Return 'all' for consolidated files
        }
        
        // Check if this is a request for consolidated files
        if (isset($dimensions['all']) && $dimensions['all'] === true) {
            return 'all';
        }
        
        $dimensionString = '';
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            if ($dimensionName !== 'all') { // Skip the 'all' flag
                $dimensionString .= $dimensionName . '-' . implode('-', $dimensionValues);
            }
        }
        
        return empty($dimensionString) ? 'all' : md5($dimensionString);
    }


    /**
     * Get LLMS file resource
     *
     * @param string $sha1
     *
     * @return PersistentResource|null
     */
    public function getLLMSFileResource(string $sha1): ?PersistentResource
    {
        return $this->resourceManager->getResourceBySha1($sha1);
    }
    
    /**
     * Get LLMS file content directly from filesystem
     *
     * @param string $filename
     * @param string $siteName
     * @param array $dimensions
     * @return string|null
     */
    public function getLLMSFileContent(string $filename, string $siteName, array $dimensions): ?string
    {
        $dimensionHash = $this->getDimensionHash($dimensions);
        
        // Get SHA1 from database
        $sha1 = $this->fileHashService->getHash($filename, $siteName, $dimensionHash);
        
        if (!$sha1) {
            $this->logger->info('No SHA1 hash found in database for LLM file', [
                'filename' => $filename,
                'siteName' => $siteName,
                'dimensionHash' => $dimensionHash
            ]);
            return null;
        }

        // First try to get through resource manager
        $resource = $this->getLLMSFileResource($sha1);

        if ($resource !== null) {
            try {
                $content = file_get_contents('resource://' . $resource->getSha1());
                if ($content !== false) {
                    return $content;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get content for resource', [
                    'error' => $e->getMessage(),
                    'sha1' => $resource->getSha1()
                ]);
            }
        }
        
        return null;
    }

    /**
     * Handle node publishing signal
     *
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return void
     */
    public function handleNodePublished(NodeInterface $node, Workspace $targetWorkspace): void
    {
        if ($targetWorkspace->getName() !== 'live') {
            return;
        }

        $this->logger->info('Node published, regenerating LLMS files', array_merge(
            LogEnvironment::fromMethodName(__METHOD__),
            ['node' => $node->getIdentifier()]
        ));

        try {
            // Clear all cached hashes first to force regeneration
            $this->fileHashService->clearAllHashes();
            
            // Get the site for the published node
            $site = $this->getSiteForNode($node);
            if ($site) {
                // Clear resources for this site
                $this->fileHashService->deleteAllHashesForSite($site->getNodeName());
            }
            
            // Regenerate all files
            $this->securityContext->withoutAuthorizationChecks(function () {
                $this->generateAllFiles();
            });
            
            $this->logger->info('LLMS files regenerated after node publishing');
        } catch (\Exception $e) {
            $this->logger->error('Failed to regenerate LLMS files after publishing', [
                'error' => $e->getMessage(),
                'node' => $node->getIdentifier()
            ]);
        }
    }

    /**
     * Get site for a node
     *
     * @param NodeInterface $node
     * @return \Neos\Neos\Domain\Model\Site|null
     */
    protected function getSiteForNode(NodeInterface $node): ?\Neos\Neos\Domain\Model\Site
    {
        $currentNode = $node;
        $iterations = 0;
        while ($currentNode && !$currentNode->getNodeType()->isOfType('Neos.Neos:Sites')) {
            $this->logger->debug('Walking up tree to find site', [
                'iteration' => $iterations++,
                'nodeName' => $currentNode->getName(),
                'nodeType' => $currentNode->getNodeType()->getName(),
                'isOfTypeSite' => $currentNode->getNodeType()->isOfType('Neos.Neos:Site'),
                'path' => $currentNode->getPath()
            ]);
            
            // Check for both standard Site type and custom HomePage type used as site root
            if ($currentNode->getNodeType()->isOfType('Neos.Neos:Site') || 
                $this->isHomePageNodeType($currentNode)) {
                $nodeName = $currentNode->getName();
                $site = $this->siteRepository->findOneByNodeName($nodeName);
                $this->logger->debug('Site lookup by node name', [
                    'nodeName' => $nodeName,
                    'nodeType' => $currentNode->getNodeType()->getName(),
                    'siteFound' => $site !== null,
                    'siteName' => $site ? $site->getName() : 'null'
                ]);
                return $site;
            }
            $currentNode = $currentNode->getParent();
        }
        $this->logger->debug('No site found for node', [
            'nodeId' => $node->getIdentifier(),
            'nodePath' => $node->getPath(),
            'iterations' => $iterations
        ]);
        return null;
    }

    /**
     * Get node path relative to site root
     *
     * @param NodeInterface $node
     * @return string
     */
    protected function getNodePath(NodeInterface $node): string
    {
        $path = '';
        $currentNode = $node;
        
        while ($currentNode && !$currentNode->getNodeType()->isOfType('Neos.Neos:Sites')) {
            if (!$currentNode->getNodeType()->isOfType('Neos.Neos:Site')) {
                $path = '/' . $currentNode->getName() . $path;
            }
            $currentNode = $currentNode->getParent();
        }
        
        return $path ?: '/';
    }

    /**
     * Generate consolidated llms.txt content with all languages
     *
     * @param \Neos\Neos\Domain\Model\Site $site
     * @return string
     */
    protected function generateConsolidatedLLMSTxt($site, string $requestHost = null): string
    {
        // Store request host for URL generation
        $this->requestHost = $requestHost;
        
        // Initialize site properties
        $siteTitle = null;
        $siteDescription = null;
        $siteContext = null;
        
        // Get site node to retrieve properties
        $this->securityContext->withoutAuthorizationChecks(function () use ($site, &$siteTitle, &$siteDescription, &$siteContext) {
            $context = $this->contextFactory->create([
                'currentSite' => $site,
                'dimensions' => ['language' => ['de']],
                'targetDimensions' => ['language' => 'de'],
                'invisibleContentShown' => false,
                'removedContentShown' => false,
                'inaccessibleContentShown' => false,
                'workspaceName' => 'live'
            ]);
            $siteNode = $context->getNode('/sites/' . $site->getNodeName());
            if ($siteNode) {
                $siteTitle = $siteNode->getProperty('title') ?: $siteNode->getLabel();
                $siteDescription = $this->getNodeDescription($siteNode);
                $siteContext = $siteNode->getProperty('llmContext');
            }
        });
        
        // Build content with configurable title and description
        $content = "# " . ($siteTitle ?: $site->getName()) . "\n\n";
        
        // Use configured site description (prefer English for consolidated file) or fallback to site node description  
        $description = $this->getSiteDescriptionForLanguage('en', $siteDescription);
        if ($description) {
            // Split description into blockquote (first paragraph) and extended content
            $descriptionLines = explode("\n", trim($description));
            $firstParagraph = '';
            $extendedContent = '';
            $inExtended = false;
            
            foreach ($descriptionLines as $line) {
                $trimmedLine = trim($line);
                if (empty($trimmedLine) && !$inExtended && !empty($firstParagraph)) {
                    $inExtended = true;
                    continue;
                }
                
                if (!$inExtended) {
                    $firstParagraph .= ($firstParagraph ? ' ' : '') . $trimmedLine;
                } else {
                    $extendedContent .= $line . "\n";
                }
            }
            
            // Add blockquote
            if (!empty($firstParagraph)) {
                $content .= "> {$firstParagraph}\n\n";
            }
            
            // Add extended content
            if (!empty($extendedContent)) {
                $content .= trim($extendedContent) . "\n\n";
            }
        }
        
        if ($siteContext) {
            $content .= $siteContext . "\n\n";
        }
        
        // Add configured additional content
        if (isset($this->additionalContent['simple']) && is_array($this->additionalContent['simple'])) {
            foreach ($this->additionalContent['simple'] as $section => $sectionContent) {
                $content .= "## " . $section . "\n\n";
                $content .= $sectionContent . "\n\n";
            }
        }
        
        // Get all language dimensions
        $languagePresets = [];
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        if (isset($presets['language']['presets'])) {
            $languagePresets = $presets['language']['presets'];
        }
        
        // Collect all language content
        $allContent = [];
        $optionalContent = [];
        $shortcutContent = [];
        
        // Use withoutAuthorizationChecks to access nodes
        $this->securityContext->withoutAuthorizationChecks(function () use ($site, &$allContent, &$optionalContent, &$shortcutContent, $languagePresets) {
            foreach ($languagePresets as $languageKey => $languageConfig) {
                $dimensions = ['language' => $languageConfig['values']];
                
                $context = $this->contextFactory->create([
                    'currentSite' => $site,
                    'dimensions' => $dimensions,
                    'targetDimensions' => array_map(function($dimensionValues) {
                        return array_shift($dimensionValues);
                    }, $dimensions),
                    'invisibleContentShown' => false,
                    'removedContentShown' => false,
                    'inaccessibleContentShown' => false,
                    'workspaceName' => 'live'
                ]);

                $siteNode = $context->getNode('/sites/' . $site->getNodeName());
                if (!$siteNode) {
                    continue;
                }
                
                $languageLabel = $languageConfig['label'] ?? strtoupper($languageKey);
                
                // Build hierarchical tree for this language, including the site root
                $pageTree = $this->buildPageTree($siteNode, 0, true);
                $this->logger->info('Built page tree for language', [
                    'language' => $languageLabel,
                    'nodeCount' => count($pageTree),
                    'siteNode' => $siteNode->getPath()
                ]);
                if (!empty($pageTree)) {
                    $allContent[$languageLabel] = $this->renderPageTree($pageTree, $siteNode, '', 3, $languageKey);
                }
                
                // Collect optional pages
                $optionalPages = $this->collectOptionalPages($siteNode);
                if (!empty($optionalPages)) {
                    $optionalContent[$languageLabel] = $optionalPages;
                }
                
                // Collect shortcuts
                $shortcuts = $this->collectLLMShortcuts($siteNode);
                if (!empty($shortcuts)) {
                    $shortcutContent[$languageLabel] = $shortcuts;
                }
            }
        });
        
        // Render content for all languages
        foreach ($allContent as $languageLabel => $languageContent) {
            $content .= "## {$languageLabel}\n\n";
            
            // Add language-specific description if configured
            $languageKey = strtolower($languageLabel === 'English' ? 'en' : ($languageLabel === 'Deutsch' ? 'de' : $languageLabel));
            $languageDescription = $this->getSiteDescriptionForLanguage($languageKey);
            if ($languageDescription && $languageKey !== 'en') { // Don't repeat English description
                // For language sections, use the full multi-line description
                $content .= $languageDescription . "\n\n";
            }
            
            $content .= $languageContent;
        }
        
        // Add optional section if there are any
        if (!empty($optionalContent)) {
            $content .= "## Optional\n\n";
            foreach ($optionalContent as $languageLabel => $pages) {
                if (!empty($pages)) {
                    $content .= "### {$languageLabel}\n\n";
                    foreach ($pages as $page) {
                        $url = $this->getNodeUrl($page);
                        $title = $page->getProperty('title') ?: $page->getLabel();
                        $description = $this->getNodeDescription($page);
                        
                        if ($url) {
                            $content .= "- [{$title}]({$url})";
                            if ($description) {
                                $content .= ": {$description}";
                            }
                            $content .= "\n";
                        }
                    }
                    $content .= "\n";
                }
            }
        }
        
        // Add external resources if there are any
        if (!empty($shortcutContent)) {
            $content .= "## External Resources\n\n";
            foreach ($shortcutContent as $languageLabel => $shortcuts) {
                if (!empty($shortcuts)) {
                    $content .= "### {$languageLabel}\n\n";
                    foreach ($shortcuts as $shortcut) {
                        $target = $this->getShortcutTarget($shortcut);
                        $title = $shortcut->getProperty('title') ?: $shortcut->getLabel();
                        $description = $shortcut->getProperty('llmShortcutDescription');
                        
                        if ($target) {
                            $content .= "- [{$title}]({$target})";
                            if ($description) {
                                $content .= ": {$description}";
                            }
                            $content .= "\n";
                        }
                    }
                    $content .= "\n";
                }
            }
        }
        
        $content .= "---\n";
        $content .= "Generated: " . (new \DateTime())->format('Y-m-d H:i:s T') . "\n";
        
        return $content;
    }

    /**
     * Generate consolidated llms-full.txt content with all languages
     *
     * @param \Neos\Neos\Domain\Model\Site $site
     * @return string
     */
    protected function generateConsolidatedLLMSFullTxt($site, string $requestHost = null): string
    {
        // Store request host for URL generation
        $this->requestHost = $requestHost;
        
        // Initialize site properties
        $siteTitle = null;
        $siteDescription = null;
        $siteContext = null;
        
        // Get site node to retrieve properties
        $this->securityContext->withoutAuthorizationChecks(function () use ($site, &$siteTitle, &$siteDescription, &$siteContext) {
            $context = $this->contextFactory->create([
                'currentSite' => $site,
                'dimensions' => ['language' => ['de']],
                'targetDimensions' => ['language' => 'de'],
                'invisibleContentShown' => false,
                'removedContentShown' => false,
                'inaccessibleContentShown' => false,
                'workspaceName' => 'live'
            ]);
            $siteNode = $context->getNode('/sites/' . $site->getNodeName());
            if ($siteNode) {
                $siteTitle = $siteNode->getProperty('title') ?: $siteNode->getLabel();
                $siteDescription = $this->getNodeDescription($siteNode);
                $siteContext = $siteNode->getProperty('llmContext');
            }
        });
        
        // Build content with configurable title and description
        $content = "# " . ($siteTitle ?: $site->getName()) . "\n\n";
        if ($siteDescription) {
            $content .= "> {$siteDescription}\n\n";
        }
        
        if ($siteContext) {
            $content .= $siteContext . "\n\n";
        }
        
        $content .= "This file contains detailed content for key pages on the " . ($siteTitle ?: 'website') . " in all available languages.\n\n";
        
        // Add configured additional content
        if (isset($this->additionalContent['full']) && is_array($this->additionalContent['full'])) {
            foreach ($this->additionalContent['full'] as $section => $sectionContent) {
                $content .= "## " . $section . "\n\n";
                $content .= $sectionContent . "\n\n";
            }
        }
        
        // Get all language dimensions
        $languagePresets = [];
        $presets = $this->contentDimensionPresetSource->getAllPresets();
        if (isset($presets['language']['presets'])) {
            $languagePresets = $presets['language']['presets'];
        }
        
        // Use withoutAuthorizationChecks to access nodes
        $this->securityContext->withoutAuthorizationChecks(function () use ($site, &$content, $languagePresets) {
            // Generate content for each language
            foreach ($languagePresets as $languageKey => $languageConfig) {
                $dimensions = ['language' => $languageConfig['values']];
                
                $context = $this->contextFactory->create([
                    'currentSite' => $site,
                    'dimensions' => $dimensions,
                    'targetDimensions' => array_map(function($dimensionValues) {
                        return array_shift($dimensionValues);
                    }, $dimensions),
                    'invisibleContentShown' => false,
                    'removedContentShown' => false,
                    'inaccessibleContentShown' => false,
                    'workspaceName' => 'live'
                ]);

                $siteNode = $context->getNode('/sites/' . $site->getNodeName());
                if (!$siteNode) {
                    continue;
                }
                
                $languageLabel = $languageConfig['label'] ?? strtoupper($languageKey);
                
                // Only collect pages marked for full content
                $pages = $this->collectPagesForFullContent($siteNode);
                
                if (empty($pages)) {
                    continue;
                }
                
                $content .= "# Content in {$languageLabel}\n\n";
                
                // Temporary simplified grouping while debugging
                $grouped = [
                    'Core Features' => [],
                    'Solutions' => [],
                    'API & Developer Resources' => [],
                    'Company Information' => [],
                    'Other Resources' => []
                ];
                
                foreach ($pages as $page) {
                    $path = $this->getNodePath($page);
                    $nodeName = $page->getName();
                    
                    if (str_contains($path, '/features/') || $nodeName === 'features') {
                        $grouped['Core Features'][] = $page;
                    } elseif (str_contains($path, '/solutions/') || $nodeName === 'solutions') {
                        $grouped['Solutions'][] = $page;
                    } elseif (str_contains($path, '/api') || strpos($path, '/developer') !== false) {
                        $grouped['API & Developer Resources'][] = $page;
                    } elseif (str_contains($path, '/about') || strpos($path, '/company') !== false) {
                        $grouped['Company Information'][] = $page;
                    } else {
                        $grouped['Other Resources'][] = $page;
                    }
                }
                
                // Remove empty groups
                $groupedPages = array_filter($grouped, function($group) {
                    return !empty($group);
                });
                
                foreach ($groupedPages as $groupTitle => $groupPages) {
                    if (empty($groupPages)) continue;
                    
                    $content .= "## {$groupTitle}\n\n";
                    
                    foreach ($groupPages as $page) {
                        $url = $this->getNodeUrl($page);
                        $title = $page->getProperty('title') ?: $page->getLabel();
                        $description = $this->getNodeDescription($page);
                        
                        $content .= "### [{$title}]({$url})\n\n";
                        
                        if ($description) {
                            $content .= "{$description}\n\n";
                        }
                        
                        // Add additional context if available
                        $pageContext = $page->getProperty('llmContext');
                        if ($pageContext) {
                            $content .= $pageContext . "\n\n";
                        }
                        
                        // Extract and add page content
                        $pageContent = $this->extractPageContent($page);
                        if ($pageContent) {
                            $content .= $pageContent . "\n\n";
                        }
                        
                        $content .= "---\n\n";
                    }
                }
            }
        });
        
        $content .= "Generated: " . (new \DateTime())->format('Y-m-d H:i:s T') . "\n";
        
        return $content;
    }

    /**
     * Collect pages marked for full content inclusion
     *
     * @param NodeInterface $siteNode
     * @return array
     */
    protected function collectPagesForFullContent(NodeInterface $siteNode): array
    {
        $pages = [];
        $this->collectPagesForFullContentRecursively($siteNode, $pages);
        return $pages;
    }
    
    /**
     * Recursively collect pages for full content
     *
     * @param NodeInterface $node
     * @param array $pages
     * @return void
     */
    protected function collectPagesForFullContentRecursively(NodeInterface $node, array &$pages): void
    {
        if ($node->getNodeType()->isOfType('Neos.Neos:Document') && 
            !$node->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
            
            // Use the dedicated exclusion service
            if ($this->fullContentExclusionService->isNodeExcluded($node)) {
                return; // Skip this page and its children
            }
            
            // Apply the same basic filtering as llms.txt for consistency
            if (!$this->isExcludedPageType($node) && $this->isNodeAccessible($node)) {
                $pages[] = $node;
            }
        }
        
        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->collectPagesForFullContentRecursively($childNode, $pages);
        }
    }
    
    /**
     * Group pages by type/section using configurable grouping
     *
     * @param array $pages
     * @param string $language
     * @return array
     */
    protected function groupPagesByType(array $pages, string $language = 'en'): array
    {
        $grouped = [];
        
        foreach ($pages as $page) {
            $groupName = $this->categorizationService->groupNode($page, $language);
            if ($groupName) {
                $grouped[$groupName][] = $page;
            }
        }
        
        // Remove empty groups
        return array_filter($grouped, function($group) {
            return !empty($group);
        });
    }
}
