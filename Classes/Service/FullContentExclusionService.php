<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

/**
 * Service for handling exclusions from llms-full.txt
 *
 * @Flow\Scope("singleton")
 */
class FullContentExclusionService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="fullContentExclusions", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $exclusionConfig = [];

    /**
     * Check if a node should be excluded from llms-full.txt
     *
     * @param NodeInterface $node
     * @return bool
     */
    public function isNodeExcluded(NodeInterface $node): bool
    {
        // 1. Check explicit exclusion property
        if ($node->getProperty('llmExcludeFromFullContent') === true) {
            $this->logger->debug('Node excluded by explicit property', [
                'nodeId' => $node->getIdentifier(),
                'nodeName' => $node->getName()
            ]);
            return true;
        }

        // 2. Check path pattern exclusions
        if ($this->isExcludedByPathPattern($node)) {
            return true;
        }

        // 3. Check node type exclusions
        if ($this->isExcludedByNodeType($node)) {
            return true;
        }

        // 4. Check additional exclusion rules
        if ($this->isExcludedByAdditionalRules($node)) {
            return true;
        }

        return false;
    }

    /**
     * Check if node is excluded by path patterns
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isExcludedByPathPattern(NodeInterface $node): bool
    {
        $pathPatterns = $this->exclusionConfig['pathPatterns'] ?? [];
        
        if (empty($pathPatterns)) {
            return false;
        }

        $nodePath = $this->getNodePath($node);

        foreach ($pathPatterns as $pattern) {
            if ($this->matchesPattern($nodePath, $pattern)) {
                $this->logger->debug('Node excluded by path pattern', [
                    'nodeId' => $node->getIdentifier(),
                    'nodeName' => $node->getName(),
                    'nodePath' => $nodePath,
                    'pattern' => $pattern
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if node is excluded by node type
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isExcludedByNodeType(NodeInterface $node): bool
    {
        $excludedNodeTypes = $this->exclusionConfig['nodeTypes'] ?? [];
        
        if (empty($excludedNodeTypes)) {
            return false;
        }

        foreach ($excludedNodeTypes as $nodeType) {
            if ($node->getNodeType()->isOfType($nodeType)) {
                $this->logger->debug('Node excluded by node type', [
                    'nodeId' => $node->getIdentifier(),
                    'nodeName' => $node->getName(),
                    'nodeType' => $node->getNodeType()->getName(),
                    'excludedType' => $nodeType
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check additional exclusion rules
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isExcludedByAdditionalRules(NodeInterface $node): bool
    {
        $excludeHidden = $this->exclusionConfig['excludeHidden'] ?? true;
        $excludeFooterPages = $this->exclusionConfig['excludeFooterPages'] ?? true;

        // Check if hidden pages should be excluded
        if ($excludeHidden && $node->isHidden()) {
            $this->logger->debug('Node excluded because it is hidden', [
                'nodeId' => $node->getIdentifier(),
                'nodeName' => $node->getName()
            ]);
            return true;
        }

        // Check if footer pages should be excluded
        if ($excludeFooterPages) {
            $nodeName = strtolower($node->getName());
            if (strpos($nodeName, 'footer') !== false) {
                $this->logger->debug('Node excluded because it is a footer page', [
                    'nodeId' => $node->getIdentifier(),
                    'nodeName' => $node->getName()
                ]);
                return true;
            }
        }

        return false;
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
            if (!$currentNode->getNodeType()->isOfType('Neos.Neos:Site') &&
                !$this->isHomePageNodeType($currentNode)) {
                $segment = $currentNode->getProperty('uriPathSegment') ?: $currentNode->getName();
                $path = '/' . $segment . $path;
            }
            $currentNode = $currentNode->getParent();
        }

        return $path ?: '/';
    }

    /**
     * Check if path matches pattern (supports wildcards)
     *
     * @param string $path
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Convert pattern to regex
        if (strpos($pattern, '*') !== false) {
            $regexPattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
            return preg_match('/^' . $regexPattern . '$/i', $path) === 1;
        } else {
            // Exact match
            return $path === $pattern;
        }
    }

    /**
     * Check if node is a homepage node type (simplified version)
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isHomePageNodeType(NodeInterface $node): bool
    {
        $homePageTypes = [
            'Neos.NodeTypes:Page',
            'Neos.Neos:Document',
            'Brainswarm.PasscreatorDe:HomePage',
            'Brainswarm.PasscreatorDe:Landingpage'
        ];

        foreach ($homePageTypes as $type) {
            if ($node->getNodeType()->isOfType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all configured exclusion patterns
     *
     * @return array
     */
    public function getExclusionConfig(): array
    {
        return $this->exclusionConfig;
    }

    /**
     * Validate exclusion configuration
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validateConfiguration(): array
    {
        $errors = [];

        // Validate path patterns
        $pathPatterns = $this->exclusionConfig['pathPatterns'] ?? [];
        if (!is_array($pathPatterns)) {
            $errors[] = "fullContentExclusions.pathPatterns must be an array";
        }

        // Validate node types
        $nodeTypes = $this->exclusionConfig['nodeTypes'] ?? [];
        if (!is_array($nodeTypes)) {
            $errors[] = "fullContentExclusions.nodeTypes must be an array";
        }

        return $errors;
    }
}