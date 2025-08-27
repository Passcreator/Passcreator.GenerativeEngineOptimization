<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

/**
 * Service for flexible node matching based on various criteria
 *
 * @Flow\Scope("singleton")
 */
class MatcherService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="homePageNodeTypes", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $homePageNodeTypes = ['Neos.NodeTypes:Page', 'Neos.Neos:Document'];

    /**
     * Check if a node matches the given matcher configuration
     *
     * @param NodeInterface $node
     * @param array $matcher
     * @return bool
     */
    public function matches(NodeInterface $node, array $matcher): bool
    {
        $type = $matcher['type'] ?? '';

        try {
            switch ($type) {
                case 'path':
                    return $this->matchesPath($node, $matcher['patterns'] ?? []);

                case 'nodeType':
                    return $this->matchesNodeType($node, $matcher['types'] ?? []);

                case 'property':
                    return $this->matchesProperty($node, $matcher);

                case 'parentRelation':
                    return $this->matchesParentRelation($node, $matcher);

                case 'always':
                    return true; // Always matches - useful for catchall categories

                case 'never':
                    return false; // Never matches - useful for disabling categories

                default:
                    $this->logger->warning('Unknown matcher type', [
                        'type' => $type,
                        'nodeId' => $node->getIdentifier()
                    ]);
                    return false;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in matcher evaluation', [
                'type' => $type,
                'nodeId' => $node->getIdentifier(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if node path matches any of the given patterns
     *
     * @param NodeInterface $node
     * @param array $patterns
     * @return bool
     */
    protected function matchesPath(NodeInterface $node, array $patterns): bool
    {
        if (empty($patterns)) {
            return false;
        }

        $path = $this->getNodePath($node);

        foreach ($patterns as $pattern) {
            // Support both exact matches and contains matches
            if (strpos($pattern, '*') !== false) {
                // Wildcard matching
                $regexPattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
                if (preg_match('/^' . $regexPattern . '$/i', $path)) {
                    return true;
                }
            } else {
                // Simple contains matching
                if (stripos($path, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if node is of any of the given node types
     *
     * @param NodeInterface $node
     * @param array $nodeTypes
     * @return bool
     */
    protected function matchesNodeType(NodeInterface $node, array $nodeTypes): bool
    {
        if (empty($nodeTypes)) {
            return false;
        }

        foreach ($nodeTypes as $nodeType) {
            if ($node->getNodeType()->isOfType($nodeType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if node property matches given criteria
     *
     * @param NodeInterface $node
     * @param array $matcher
     * @return bool
     */
    protected function matchesProperty(NodeInterface $node, array $matcher): bool
    {
        $property = $matcher['property'] ?? '';
        $values = $matcher['values'] ?? [];
        $operator = $matcher['operator'] ?? 'in'; // in, equals, contains, exists

        if (empty($property)) {
            return false;
        }

        $nodeValue = $node->getProperty($property);

        switch ($operator) {
            case 'exists':
                return $nodeValue !== null && $nodeValue !== '';

            case 'equals':
                return in_array($nodeValue, $values, true);

            case 'contains':
                if (!is_string($nodeValue)) {
                    return false;
                }
                foreach ($values as $value) {
                    if (stripos($nodeValue, (string)$value) !== false) {
                        return true;
                    }
                }
                return false;

            case 'in':
            default:
                return in_array($nodeValue, $values, false); // Loose comparison
        }
    }

    /**
     * Check if node has a specific relationship to its parent
     *
     * @param NodeInterface $node
     * @param array $matcher
     * @return bool
     */
    protected function matchesParentRelation(NodeInterface $node, array $matcher): bool
    {
        $relation = $matcher['relation'] ?? '';

        switch ($relation) {
            case 'directChild':
                // Check if node is a direct child of site root
                $parent = $node->getParent();
                if (!$parent) {
                    return false;
                }
                return $parent->getNodeType()->isOfType('Neos.Neos:Site') || 
                       $this->isHomePageNodeType($parent);

            case 'hasParent':
                $parentTypes = $matcher['parentTypes'] ?? [];
                $parent = $node->getParent();
                while ($parent && !$parent->getNodeType()->isOfType('Neos.Neos:Sites')) {
                    foreach ($parentTypes as $parentType) {
                        if ($parent->getNodeType()->isOfType($parentType)) {
                            return true;
                        }
                    }
                    $parent = $parent->getParent();
                }
                return false;

            case 'depth':
                $targetDepth = $matcher['depth'] ?? 0;
                $currentDepth = $this->getNodeDepth($node);
                $operator = $matcher['operator'] ?? 'equals'; // equals, greaterThan, lessThan
                
                switch ($operator) {
                    case 'greaterThan':
                        return $currentDepth > $targetDepth;
                    case 'lessThan':
                        return $currentDepth < $targetDepth;
                    case 'equals':
                    default:
                        return $currentDepth === $targetDepth;
                }

            default:
                return false;
        }
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
     * Get node depth relative to site root
     *
     * @param NodeInterface $node
     * @return int
     */
    protected function getNodeDepth(NodeInterface $node): int
    {
        $depth = 0;
        $currentNode = $node->getParent();

        while ($currentNode && !$currentNode->getNodeType()->isOfType('Neos.Neos:Sites')) {
            if (!$currentNode->getNodeType()->isOfType('Neos.Neos:Site') &&
                !$this->isHomePageNodeType($currentNode)) {
                $depth++;
            }
            $currentNode = $currentNode->getParent();
        }

        return $depth;
    }

    /**
     * Check if node is a configured homepage node type
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isHomePageNodeType(NodeInterface $node): bool
    {
        if (empty($this->homePageNodeTypes) || !is_array($this->homePageNodeTypes)) {
            return false;
        }

        foreach ($this->homePageNodeTypes as $type) {
            if ($node->getNodeType()->isOfType($type)) {
                return true;
            }
        }

        return false;
    }
}