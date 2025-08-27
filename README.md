# Generative Engine Optimization Package for Neos CMS

A fully configurable Neos package that automatically generates `llms.txt` and `llms-full.txt` files for any website, making your content optimized for Large Language Models (LLMs) and AI systems.

> ⚠️ **DISCLAIMER - Beta Version**  
> This is an early version that is not yet fully optimized for production use on large-scale websites. The current implementation iterates through ALL pages in the content repository on each generation, which may cause performance issues on sites with:
> - Lots of pages
> - Complex content structures with deep nesting
> - Multiple language dimensions with many variants
> - Limited server resources
> 
> **Known Limitations:**
> - No pagination or chunking for large page collections
> - No incremental updates (regenerates entire files even for small changes)
> - Memory usage increases linearly with content size
> - Generation time can exceed PHP max_execution_time on large sites

## Features

1. **Dynamic Content Generation**: Reads actual pages from Neos CMS and generates structured text files
2. **Multi-language Support**: Includes all configured languages (EN, DE) in a single consolidated file
3. **Automatic Updates**: Regenerates files when pages are published via signal/slot connection
4. **Configurable Content**: Allows adding custom sections via Settings.yaml

## How It Works

- The `LLMGeneratorService` queries all Neos pages from the content repository
- Generates consolidated files that include all language variants
- Extracts page titles, descriptions (meta or llm-specific), and content
- Stores generated files in Flow's resource storage
- Files are served via HTTP middleware at `/llms.txt` and `/llms-full.txt`
- Automatically regenerates when content is published to the live workspace

## File Structure

### llms.txt
- Site title and description
- Pages organized by language (English, Deutsch)
- Each page includes: title, URL, and description
- Only includes pages with descriptions
- Timestamp of generation

### llms-full.txt
- Same structure as llms.txt
- Additionally includes full text content extracted from each page
- All pages included (even without descriptions)

## Configuration

### Configuration (Settings.yaml)

The package can be configured to work with any Neos website:

```yaml
Passcreator:
  GenerativeEngineOptimization:
    storage:
      collection: 'persistent'  # Resource collection name
    
    # Configure which node types should be treated as homepage/site root
    homePageNodeTypes:
      - 'Neos.NodeTypes:Page'
      - 'Neos.Neos:Document'
      # Add your custom homepage node types here:
      # - 'Your.Package:HomePage'
    
    # Optional fallback domain when no domain can be determined automatically
    fallbackDomain: 'your-site.com'
    
    # Additional content sections
    additionalContent:
      simple:  # For llms.txt
        'Custom Section Title': |
          Additional content for your site...
      full:    # For llms-full.txt
        'Technical Details': |
          Detailed technical information about your site...
```

#### Configuration Options

- **homePageNodeTypes**: Array of NodeType names that should be treated as homepage/site root pages. The package will prioritize these pages in the output.
- **fallbackDomain**: Domain to use when the package cannot determine the site's domain automatically. This is optional but recommended.
- **siteDescription**: Optional site description that overrides the description from the site node (single description for all languages).
- **siteDescriptions**: Language-specific site descriptions (e.g., `en: 'English description'`, `de: 'German description'`). Takes precedence over `siteDescription`.
- **additionalContent**: Custom content sections to include in the generated files.

## Installation & Setup

1. **Install the package** using Composer:
   ```bash
   composer require passcreator/generativeengineoptimization
   ```

2. **Run database migrations**:
   ```bash
   ./flow doctrine:migrate
   ```

3. **Create your configuration**:
   ```bash
   cp DistributionPackages/Passcreator.GenerativeEngineOptimization/Configuration/Settings.Generic.yaml.template \
      DistributionPackages/YourPackage/Configuration/Settings.yaml
   ```

4. **Customize the configuration** to match your site:
   - Set your fallback domain
   - Configure language detection (path patterns, domains, etc.)
   - Define page categorization rules
   - Set up exclusion patterns for llms-full.txt
   - Add translations for your languages

5. **Access your LLM files**:
   - Visit `https://yoursite.com/llms.txt` for the structured overview
   - Visit `https://yoursite.com/llms-full.txt` for full content
   - Files are automatically generated on first access
   - Use `?force=1` to force regeneration

### Flexible Configuration System

The package uses a powerful rule-based configuration system that eliminates all hardcoded assumptions:

#### 1. Fallback Content Configuration
```yaml
Passcreator:
  GenerativeEngineOptimization:
    fallbacks:
      siteDescription: 'Your site description when no other is available'
      homePageTitle:
        en: 'Homepage'
        de: 'Startseite'
        # Add more languages as needed
```

#### 2. Language Detection Configuration
```yaml
Passcreator:
  GenerativeEngineOptimization:
    languageDetection:
      strategy: 'path' # Options: path, domain, header, auto
      defaultLanguage: 'en'
      pathPatterns:
        en: ['/en/', '/english/']
        de: ['/de/', '/deutsch/']
        # Add more languages and patterns
      domainMappings: # For domain-based detection
        'example.com': 'en'
        'beispiel.de': 'de'
```

#### 3. Flexible Page Categorization
```yaml
Passcreator:
  GenerativeEngineOptimization:
    categorization:
      defaultCategory: 'Other Resources'
      categories:
        'Features':
          priority: 10
          matchers:
            - type: 'path'
              patterns: ['/features/', '/feature/']
            - type: 'nodeType'
              types: ['Your.Package:FeaturePage']
            - type: 'property'
              property: 'category'
              values: ['feature', 'features']
```

**Supported Matcher Types:**
- `path`: Match by URL path patterns (supports wildcards: `/api/*`)
- `nodeType`: Match by Neos NodeType inheritance
- `property`: Match by node property values
- `parentRelation`: Match by parent-child relationships
- `always`: Always match (useful for catchall categories)

#### 4. Content Grouping for llms-full.txt
```yaml
Passcreator:
  GenerativeEngineOptimization:
    fullContentGrouping:
      'Core Features':
        priority: 10
        matchers:
          - type: 'path'
            patterns: ['/features/']
          - type: 'property'
            property: 'llmGroup'
            values: ['core-features']
```

#### 5. Translation System
```yaml
Passcreator:
  GenerativeEngineOptimization:
    translations:
      'Features':
        en: 'Features'
        de: 'Funktionen'
        fr: 'Fonctionnalités'
        # Add more languages as needed
```

### Advanced Matcher Examples

**Property-based matching:**
```yaml
- type: 'property'
  property: 'category'
  values: ['feature', 'product']
  operator: 'in' # Options: in, equals, contains, exists
```

**Parent relationship matching:**
```yaml
- type: 'parentRelation'
  relation: 'directChild' # Direct child of site root
```

**Depth-based matching:**
```yaml
- type: 'parentRelation'
  relation: 'depth'
  depth: 2
  operator: 'lessThan' # Only pages at depth < 2
```

### Advanced: Excluding Pages from llms-full.txt

The package provides multiple ways to exclude pages from llms-full.txt:

#### 1. Per-Page Exclusion (Editor Control)
In the Neos backend, each page has an "LLM Metadata" section in the inspector with:
- **"Exclude from llms-full.txt"** - Checkbox to exclude specific pages

#### 2. Configuration-Based Exclusion
```yaml
Passcreator:
  GenerativeEngineOptimization:
    fullContentExclusions:
      pathPatterns:
        - '/legal/*'      # Exclude all pages under /legal/
        - '/privacy/*'    # Exclude all pages under /privacy/
        - '/impress*'     # Exclude pages starting with /impress
      nodeTypes:
        - 'Your.Package:LegalPage'    # Exclude specific NodeTypes
        - 'Your.Package:PrivacyPage'
      excludeHidden: true      # Exclude hidden pages (default: true)
      excludeFooterPages: true # Exclude footer pages (default: true)
```

**Path Pattern Examples:**
- `/legal/*` - Excludes `/legal/terms`, `/legal/privacy`, etc.
- `/impress*` - Excludes `/impressum`, `/impress.html`, etc.
- `/admin` - Exact match for `/admin` only

#### 3. Benefits of Exclusion
- **Cleaner content**: Remove legal pages, admin pages, or technical content
- **Focused AI training**: Only include relevant content for LLM consumption  
- **Privacy compliance**: Exclude sensitive or internal pages
- **Performance**: Reduce file size by excluding unnecessary pages


## CLI Commands

All commands should be run from your Neos root directory.

### Generate Files
```bash
# Generate all LLM files for all sites
./flow llm:generate

# Force regenerate (clears cache first)
./flow llm:forceregenerate
```

### Cache Management
```bash
# Clear all cached LLM files and hashes
./flow llm:clear

# Show current file hashes (for debugging)
./flow llm:showhashes
```

### Debugging
```bash
# List all LLM resources in storage
./flow llm:listresources
```

## Automatic Generation

Files are automatically regenerated when:
- Content is published from any workspace to live (via signal/slot)
- Files are requested but don't exist in storage
- The manual generation command is run

## URL Generation

The service generates proper URLs by:
1. Using Neos LinkingService for accurate URL building
2. Extracting paths with proper language segments
3. Using configured domains from the Domain Repository
4. Including language prefixes (e.g., /en/, /de/) as needed

## Node Requirements

For pages to appear in llms.txt:
- Must have either `llmDescription` or `metaDescription` property
- The `llmDescription` takes precedence if both exist
- Pages without descriptions only appear in llms-full.txt

## Endpoints

- `/llms.txt` - Structured listing with descriptions
- `/llms-full.txt` - Complete content dump

Both URLs:
- Are handled by HTTP middleware (before Neos routing)
- Support browser caching (Cache-Control: public, max-age=3600)
- Return UTF-8 encoded plain text
- Include generation timestamp
- Support `?force=1` parameter for regeneration

## Complete Example Configuration

Here's a real-world example configuration for a multi-language site:

```yaml
Passcreator:
  GenerativeEngineOptimization:
    # Basic settings
    fallbackDomain: 'example.com'
    
    # Fallback content
    fallbacks:
      siteDescription: 'Your site description for AI systems'
      homePageTitle:
        en: 'Home'
        de: 'Startseite'
        
    # Language detection
    languageDetection:
      strategy: 'path'
      defaultLanguage: 'en'
      pathPatterns:
        en: ['/en/', '/english/']
        de: ['/de/', '/german/']
        
    # Page categorization for llms.txt
    categorization:
      defaultCategory: 'Other'
      categories:
        'Main':
          priority: 1
          matchers:
            - type: 'parentRelation'
              relation: 'directChild'
        'Products':
          priority: 10
          matchers:
            - type: 'path'
              patterns: ['/products/']
            - type: 'nodeType'
              types: ['Your.Package:ProductPage']
              
    # Exclusions for llms-full.txt
    fullContentExclusions:
      pathPatterns:
        - '/legal/*'
        - '/admin/*'
      excludeHidden: true
      
    # Translations
    translations:
      'Main':
        en: 'Main Pages'
        de: 'Hauptseiten'
      'Products':
        en: 'Products'
        de: 'Produkte'
```

## Architecture

### Components
- `LLMGeneratorService`: Core service for content generation
- `LLMFileMiddleware`: HTTP middleware for serving files
- `LLMCommandController`: CLI commands
- `Package.php`: Signal/slot connections for auto-generation

### Storage
- Files are stored using Flow's ResourceManager
- Supports any configured storage backend
- Files are identified by: `{siteName}-{dimensionHash}-{filename}`
- Dimension hash 'all' indicates consolidated multi-language files
