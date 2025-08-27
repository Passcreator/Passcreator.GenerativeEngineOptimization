<?php
declare(strict_types=1);

namespace Passcreator\GenerativeEngineOptimization\Service;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

/**
 * Service for translating section titles and other text elements
 *
 * @Flow\Scope("singleton")
 */
class TranslationService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="translations", package="Passcreator.GenerativeEngineOptimization")
     * @var array
     */
    protected $translations = [];

    /**
     * Translate a key to the specified language
     *
     * @param string $key
     * @param string $language
     * @return string
     */
    public function translate(string $key, string $language = 'en'): string
    {
        // Check if translation exists for the key and language
        if (isset($this->translations[$key][$language])) {
            return $this->translations[$key][$language];
        }

        // Fallback to English if available
        if ($language !== 'en' && isset($this->translations[$key]['en'])) {
            $this->logger->debug('Translation fallback to English', [
                'key' => $key,
                'requestedLanguage' => $language
            ]);
            return $this->translations[$key]['en'];
        }

        // Fallback to first available language
        if (isset($this->translations[$key]) && is_array($this->translations[$key])) {
            $firstTranslation = reset($this->translations[$key]);
            if ($firstTranslation) {
                $this->logger->debug('Translation fallback to first available', [
                    'key' => $key,
                    'requestedLanguage' => $language,
                    'fallbackLanguage' => key($this->translations[$key])
                ]);
                return $firstTranslation;
            }
        }

        // Ultimate fallback: return the key itself
        $this->logger->warning('No translation found, using key as fallback', [
            'key' => $key,
            'requestedLanguage' => $language
        ]);

        return $key;
    }

    /**
     * Check if a translation exists for a key and language
     *
     * @param string $key
     * @param string $language
     * @return bool
     */
    public function hasTranslation(string $key, string $language = 'en'): bool
    {
        return isset($this->translations[$key][$language]);
    }

    /**
     * Get all available languages for a translation key
     *
     * @param string $key
     * @return array
     */
    public function getAvailableLanguages(string $key): array
    {
        if (!isset($this->translations[$key]) || !is_array($this->translations[$key])) {
            return [];
        }

        return array_keys($this->translations[$key]);
    }

    /**
     * Get all translations for a key
     *
     * @param string $key
     * @return array
     */
    public function getAllTranslations(string $key): array
    {
        return $this->translations[$key] ?? [];
    }

    /**
     * Get all translation keys
     *
     * @return array
     */
    public function getAllKeys(): array
    {
        return array_keys($this->translations);
    }

    /**
     * Get all supported languages across all translations
     *
     * @return array
     */
    public function getAllSupportedLanguages(): array
    {
        $languages = [];

        foreach ($this->translations as $translations) {
            if (is_array($translations)) {
                $languages = array_merge($languages, array_keys($translations));
            }
        }

        return array_unique($languages);
    }

    /**
     * Batch translate multiple keys
     *
     * @param array $keys
     * @param string $language
     * @return array
     */
    public function translateMultiple(array $keys, string $language = 'en'): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->translate($key, $language);
        }

        return $result;
    }

    /**
     * Get translation with fallback chain
     *
     * @param string $key
     * @param array $languageFallbackChain e.g., ['de-CH', 'de', 'en']
     * @return string
     */
    public function translateWithFallbackChain(string $key, array $languageFallbackChain): string
    {
        foreach ($languageFallbackChain as $language) {
            if ($this->hasTranslation($key, $language)) {
                return $this->translate($key, $language);
            }
        }

        // Ultimate fallback
        return $this->translate($key, 'en');
    }
}