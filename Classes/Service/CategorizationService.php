<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Passcreator\GenerativeEngineOptimization\Service\MatcherService;
use Passcreator\GenerativeEngineOptimization\Service\TranslationService;

/**
 * Service for categorizing nodes based on configurable rules
 *
 * @Flow\Scope("singleton")
 */
class CategorizationService
{
    /**
     * @Flow\Inject
     * @var MatcherService
     */
    protected $matcherService;

    /**
     * @Flow\Inject
     * @var TranslationService
     */
    protected $translationService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="categorization", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $categorizationConfig = [];

    /**
     * @Flow\InjectConfiguration(path="fullContentGrouping", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $fullContentGroupingConfig = [];

    /**
     * Categorize a node for llms.txt structure
     *
     * @param NodeInterface $node
     * @param string $language
     * @return string|null
     */
    public function categorizeNode(NodeInterface $node, string $language = 'en'): ?string
    {
        $categories = $this->categorizationConfig['categories'] ?? [];
        $defaultCategory = $this->categorizationConfig['defaultCategory'] ?? 'Other Resources';

        if (empty($categories)) {
            $this->logger->warning('No categorization configuration found, using default category');
            return $this->translationService->translate($defaultCategory, $language);
        }

        // Sort categories by priority (lower number = higher priority)
        uasort($categories, function($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;
            return $priorityA <=> $priorityB;
        });

        // Find first matching category
        foreach ($categories as $categoryName => $categoryConfig) {
            if ($this->nodeMatchesCategory($node, $categoryConfig)) {
                $this->logger->debug('Node categorized', [
                    'nodeId' => $node->getIdentifier(),
                    'category' => $categoryName,
                    'language' => $language
                ]);
                return $this->translationService->translate($categoryName, $language);
            }
        }

        // No category matched, use default
        $this->logger->debug('Node using default category', [
            'nodeId' => $node->getIdentifier(),
            'defaultCategory' => $defaultCategory,
            'language' => $language
        ]);

        return $this->translationService->translate($defaultCategory, $language);
    }

    /**
     * Group a node for llms-full.txt structure
     *
     * @param NodeInterface $node
     * @param string $language
     * @return string|null
     */
    public function groupNode(NodeInterface $node, string $language = 'en'): ?string
    {
        $groups = $this->fullContentGroupingConfig ?? [];

        if (empty($groups)) {
            $this->logger->warning('No full content grouping configuration found');
            return null;
        }

        // Sort groups by priority (lower number = higher priority)
        uasort($groups, function($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;
            return $priorityA <=> $priorityB;
        });

        // Find first matching group
        foreach ($groups as $groupName => $groupConfig) {
            if ($this->nodeMatchesCategory($node, $groupConfig)) {
                $this->logger->debug('Node grouped', [
                    'nodeId' => $node->getIdentifier(),
                    'group' => $groupName,
                    'language' => $language
                ]);
                return $this->translationService->translate($groupName, $language);
            }
        }

        return null;
    }

    /**
     * Check if a node matches a category configuration
     *
     * @param NodeInterface $node
     * @param array $categoryConfig
     * @return bool
     */
    protected function nodeMatchesCategory(NodeInterface $node, array $categoryConfig): bool
    {
        $matchers = $categoryConfig['matchers'] ?? [];

        if (empty($matchers)) {
            return false;
        }

        // Node matches if ANY matcher matches (OR logic)
        foreach ($matchers as $matcher) {
            if ($this->matcherService->matches($node, $matcher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all configured categories with their translations
     *
     * @param string $language
     * @return array
     */
    public function getAllCategories(string $language = 'en'): array
    {
        $categories = $this->categorizationConfig['categories'] ?? [];
        $result = [];

        foreach (array_keys($categories) as $categoryName) {
            $result[$categoryName] = $this->translationService->translate($categoryName, $language);
        }

        // Add default category
        $defaultCategory = $this->categorizationConfig['defaultCategory'] ?? 'Other Resources';
        $result[$defaultCategory] = $this->translationService->translate($defaultCategory, $language);

        return $result;
    }

    /**
     * Get all configured groups with their translations
     *
     * @param string $language
     * @return array
     */
    public function getAllGroups(string $language = 'en'): array
    {
        $groups = $this->fullContentGroupingConfig ?? [];
        $result = [];

        foreach (array_keys($groups) as $groupName) {
            $result[$groupName] = $this->translationService->translate($groupName, $language);
        }

        return $result;
    }

    /**
     * Check if a category is configured
     *
     * @param string $categoryName
     * @return bool
     */
    public function isCategoryConfigured(string $categoryName): bool
    {
        $categories = $this->categorizationConfig['categories'] ?? [];
        return isset($categories[$categoryName]);
    }

    /**
     * Check if a group is configured
     *
     * @param string $groupName
     * @return bool
     */
    public function isGroupConfigured(string $groupName): bool
    {
        $groups = $this->fullContentGroupingConfig ?? [];
        return isset($groups[$groupName]);
    }
}