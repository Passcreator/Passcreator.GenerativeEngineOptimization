# LLM Text Generation Features

## Overview

The LLM package has been updated to follow best practices for llms.txt generation as specified by the official llms.txt repository (https://github.com/AnswerDotAI/llms-txt).

## Key Improvements

### 1. Hierarchical Page Structure

The llms.txt file now generates pages in a hierarchical structure with proper categorization:

- **Main Pages**: Top-level pages of the site
- **Features**: Pages under /features/
- **Solutions**: Pages under /solutions/
- **API Documentation**: API-related pages
- **Pricing**: Pricing-related pages
- **Additional Resources**: Other important pages
- **Optional**: Pages marked as optional by editors
- **External Resources**: Shortcuts/external links marked for inclusion

### 2. Absolute URLs

All URLs in llms.txt are now absolute URLs (e.g., https://passcreator.de/en/features) rather than relative paths, making them directly accessible.

### 3. Editor Controls

New NodeType properties allow editors to control LLM content:

#### For All Document Pages

- **LLM Description**: Custom description specifically for llms.txt (overrides meta description)
- **Additional LLM Context**: Extended context/information about the page
- **Include in Optional Section**: Mark page for the optional section in llms.txt
- **Include Full Content in llms-full.txt**: Mark page to have its full content included in llms-full.txt

#### For Shortcuts

- **Include Shortcut in llms.txt**: Include this external link in llms.txt
- **Shortcut Description for LLM**: Description of what the external resource provides

### 4. Selective llms-full.txt

The llms-full.txt file is no longer a full dump of all content. Instead:

- Only pages explicitly marked with "Include Full Content in llms-full.txt" are included
- Content is organized by categories (Core Features, Solutions, API & Developer Resources, etc.)
- Each page includes its description, additional context, and extracted content

### 5. Multi-language Support

Both llms.txt and llms-full.txt include content from all language dimensions:

- Content is grouped by language (e.g., "## German", "## English")
- Each language section contains its own hierarchical structure
- URLs include the appropriate language prefix

## Usage

### For Editors

1. **Add LLM-specific descriptions**: Use the "LLM Description" field to provide AI-optimized descriptions
2. **Add context**: Use "Additional LLM Context" for detailed information about important pages
3. **Mark optional pages**: Check "Include in Optional Section" for secondary pages
4. **Select full content pages**: Check "Include Full Content" only for the most important pages
5. **Include external links**: For shortcuts, check "Include Shortcut" and provide a description

### For Developers

The files are generated automatically when content is published. To manually regenerate:

```bash
./flow llm:generate
```

Files are stored in the resource collection and served via middleware at:
- `/llms.txt`
- `/llms-full.txt`

## Best Practices

1. **Be selective**: Only mark the most important pages for full content inclusion
2. **Write clear descriptions**: LLM descriptions should be concise but informative
3. **Organize content**: Use the hierarchical structure to group related pages
4. **Keep it focused**: The llms.txt file should provide a clear overview of your site's structure and purpose