<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for detecting language based on configurable strategies
 *
 * @Flow\Scope("singleton")
 */
class LanguageDetectionService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="languageDetection", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $languageDetectionConfig = [];

    /**
     * Detect language from HTTP request
     *
     * @param RequestInterface|null $request
     * @return string
     */
    public function detectLanguageFromRequest(?RequestInterface $request = null): string
    {
        $strategy = $this->languageDetectionConfig['strategy'] ?? 'path';
        $defaultLanguage = $this->languageDetectionConfig['defaultLanguage'] ?? 'en';

        if (!$request) {
            $this->logger->debug('No request available, using default language', [
                'defaultLanguage' => $defaultLanguage
            ]);
            return $defaultLanguage;
        }

        try {
            switch ($strategy) {
                case 'path':
                    return $this->detectFromPath($request) ?: $defaultLanguage;

                case 'domain':
                    return $this->detectFromDomain($request) ?: $defaultLanguage;

                case 'header':
                    return $this->detectFromHeaders($request) ?: $defaultLanguage;

                case 'auto':
                    // Try multiple strategies in order
                    return $this->detectFromPath($request) 
                        ?: $this->detectFromDomain($request)
                        ?: $this->detectFromHeaders($request)
                        ?: $defaultLanguage;

                default:
                    $this->logger->warning('Unknown language detection strategy', [
                        'strategy' => $strategy
                    ]);
                    return $defaultLanguage;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in language detection', [
                'strategy' => $strategy,
                'error' => $e->getMessage()
            ]);
            return $defaultLanguage;
        }
    }

    /**
     * Detect language from node context
     *
     * @param NodeInterface $node
     * @return string
     */
    public function detectLanguageFromNode(NodeInterface $node): string
    {
        $defaultLanguage = $this->languageDetectionConfig['defaultLanguage'] ?? 'en';

        try {
            $context = $node->getContext();
            $dimensions = $context->getDimensions();

            if (!empty($dimensions['language'])) {
                $language = reset($dimensions['language']);
                if (is_string($language)) {
                    return $language;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error detecting language from node', [
                'nodeId' => $node->getIdentifier(),
                'error' => $e->getMessage()
            ]);
        }

        return $defaultLanguage;
    }

    /**
     * Detect language from URL path
     *
     * @param RequestInterface $request
     * @return string|null
     */
    protected function detectFromPath(RequestInterface $request): ?string
    {
        $pathPatterns = $this->languageDetectionConfig['pathPatterns'] ?? [];
        $requestPath = $request->getUri()->getPath();

        $this->logger->debug('Detecting language from path', [
            'requestPath' => $requestPath,
            'patterns' => $pathPatterns
        ]);

        foreach ($pathPatterns as $language => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($requestPath, $pattern) === 0 || $requestPath === rtrim($pattern, '/')) {
                    $this->logger->debug('Language detected from path', [
                        'language' => $language,
                        'pattern' => $pattern,
                        'path' => $requestPath
                    ]);
                    return $language;
                }
            }
        }

        return null;
    }

    /**
     * Detect language from domain
     *
     * @param RequestInterface $request
     * @return string|null
     */
    protected function detectFromDomain(RequestInterface $request): ?string
    {
        $domainMappings = $this->languageDetectionConfig['domainMappings'] ?? [];
        $host = $request->getUri()->getHost();

        $this->logger->debug('Detecting language from domain', [
            'host' => $host,
            'mappings' => $domainMappings
        ]);

        // Exact match first
        if (isset($domainMappings[$host])) {
            $language = $domainMappings[$host];
            $this->logger->debug('Language detected from exact domain match', [
                'language' => $language,
                'host' => $host
            ]);
            return $language;
        }

        // Pattern matching (wildcards)
        foreach ($domainMappings as $domainPattern => $language) {
            if (strpos($domainPattern, '*') !== false) {
                $regexPattern = str_replace(['*', '.'], ['.*', '\.'], $domainPattern);
                if (preg_match('/^' . $regexPattern . '$/i', $host)) {
                    $this->logger->debug('Language detected from domain pattern', [
                        'language' => $language,
                        'pattern' => $domainPattern,
                        'host' => $host
                    ]);
                    return $language;
                }
            }
        }

        return null;
    }

    /**
     * Detect language from HTTP headers
     *
     * @param RequestInterface $request
     * @return string|null
     */
    protected function detectFromHeaders(RequestInterface $request): ?string
    {
        $headerMappings = $this->languageDetectionConfig['headerMappings'] ?? [];
        
        // Get Accept-Language header
        $acceptLanguage = $request->getHeader('Accept-Language');
        
        if (!$acceptLanguage) {
            return null;
        }

        $this->logger->debug('Detecting language from headers', [
            'acceptLanguage' => $acceptLanguage,
            'mappings' => $headerMappings
        ]);

        // Parse Accept-Language header
        $languages = $this->parseAcceptLanguageHeader($acceptLanguage);

        foreach ($languages as $headerLang => $priority) {
            // Direct mapping
            if (isset($headerMappings[$headerLang])) {
                $language = $headerMappings[$headerLang];
                $this->logger->debug('Language detected from header mapping', [
                    'language' => $language,
                    'headerLang' => $headerLang
                ]);
                return $language;
            }

            // Try with just the language part (e.g., 'en' from 'en-US')
            $langCode = substr($headerLang, 0, 2);
            if (isset($headerMappings[$langCode])) {
                $language = $headerMappings[$langCode];
                $this->logger->debug('Language detected from header language code', [
                    'language' => $language,
                    'langCode' => $langCode
                ]);
                return $language;
            }
        }

        return null;
    }

    /**
     * Parse Accept-Language header
     *
     * @param string $acceptLanguage
     * @return array
     */
    protected function parseAcceptLanguageHeader(string $acceptLanguage): array
    {
        $languages = [];
        $parts = explode(',', $acceptLanguage);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([a-zA-Z-]+)(?:;q=([0-9.]+))?$/', $part, $matches)) {
                $language = strtolower($matches[1]);
                $priority = isset($matches[2]) ? (float)$matches[2] : 1.0;
                $languages[$language] = $priority;
            }
        }

        // Sort by priority (highest first)
        arsort($languages);

        return $languages;
    }

    /**
     * Get all configured languages
     *
     * @return array
     */
    public function getAllConfiguredLanguages(): array
    {
        $languages = [];

        // From path patterns
        $pathPatterns = $this->languageDetectionConfig['pathPatterns'] ?? [];
        $languages = array_merge($languages, array_keys($pathPatterns));

        // From domain mappings
        $domainMappings = $this->languageDetectionConfig['domainMappings'] ?? [];
        $languages = array_merge($languages, array_values($domainMappings));

        // From header mappings
        $headerMappings = $this->languageDetectionConfig['headerMappings'] ?? [];
        $languages = array_merge($languages, array_values($headerMappings));

        // Add default language
        $defaultLanguage = $this->languageDetectionConfig['defaultLanguage'] ?? 'en';
        $languages[] = $defaultLanguage;

        return array_unique($languages);
    }

    /**
     * Validate language detection configuration
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validateConfiguration(): array
    {
        $errors = [];
        $strategy = $this->languageDetectionConfig['strategy'] ?? '';
        $validStrategies = ['path', 'domain', 'header', 'auto'];

        if (!in_array($strategy, $validStrategies)) {
            $errors[] = "Invalid language detection strategy: {$strategy}. Valid options: " . implode(', ', $validStrategies);
        }

        if (empty($this->languageDetectionConfig['defaultLanguage'])) {
            $errors[] = "Default language is not configured";
        }

        if ($strategy === 'path' || $strategy === 'auto') {
            $pathPatterns = $this->languageDetectionConfig['pathPatterns'] ?? [];
            if (empty($pathPatterns)) {
                $errors[] = "Path patterns are required for '{$strategy}' strategy but none are configured";
            }
        }

        return $errors;
    }
}