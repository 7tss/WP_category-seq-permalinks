# Installation Guide

This guide will help you install and configure the Category Sequential Permalinks plugin.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Installation Methods

### Method 1: Manual Installation

1. Download the plugin files
2. Upload the plugin folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings > Permalinks and click "Save Changes"

### Method 2: WordPress Admin Upload

1. Go to Plugins > Add New
2. Click "Upload Plugin"
3. Choose the plugin zip file
4. Click "Install Now"
5. Activate the plugin
6. Go to Settings > Permalinks and click "Save Changes"

## Configuration

### Basic Setup

1. **Activate the Plugin**
   - Go to Plugins > Installed Plugins
   - Find "Category Sequential Permalinks (Fixed Version v3)"
   - Click "Activate"

2. **Flush Rewrite Rules**
   - Go to Settings > Permalinks
   - Click "Save Changes" (this flushes the rewrite rules)

3. **Configure Categories**
   - Create or edit your categories
   - Assign categories to your posts

### Advanced Configuration

Edit the plugin file to customize settings:

```php
// Target post types
$csp_target_post_types = ['post'];

// Allowed categories (empty = all categories)
$csp_allowed_categories = [];

// Slug overrides
$csp_slug_override = [
  'news' => 'topics',
];
```

## Usage

### Automatic Numbering

1. Create a new post
2. Assign a category
3. Publish the post
4. The plugin will automatically assign a sequential number

### Manual Numbering

1. Edit a post
2. Find the "Category Sequential Permalinks" meta box
3. Select the primary category
4. Enter a manual sequence number
5. Update the post

### Managing Sequences

- Each category has its own sequence
- Numbers start from 1
- Duplicate numbers are automatically adjusted
- You can manually set any number

## Troubleshooting

### Common Issues

**Permalinks not working:**
- Go to Settings > Permalinks and click "Save Changes"
- Check if your theme supports custom permalinks

**404 errors:**
- Ensure the plugin is activated
- Check if categories are properly assigned
- Verify rewrite rules are flushed

**Sequence conflicts:**
- The plugin automatically resolves conflicts
- Manual sequences take priority over automatic ones

### Debug Mode

Enable WordPress debug mode to see detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Getting Help

If you encounter issues:

1. Check the WordPress error log
2. Disable other plugins temporarily
3. Switch to a default theme
4. Create an issue on GitHub with details

## Uninstallation

### Removing the Plugin

1. Go to Plugins > Installed Plugins
2. Find the plugin and click "Deactivate"
3. Click "Delete" to remove the plugin files

### Data Cleanup

The plugin stores data in:
- `_csp_primary_cat` meta field
- `cat_seq_{term_id}` meta fields

To clean up this data:

```sql
-- Remove primary category meta
DELETE FROM wp_postmeta WHERE meta_key = '_csp_primary_cat';

-- Remove sequence meta
DELETE FROM wp_postmeta WHERE meta_key LIKE 'cat_seq_%';
```

## Backup

Before installing or updating:

1. Backup your WordPress database
2. Backup your WordPress files
3. Test in a staging environment first

## Support

For support and questions:
- Create an issue on GitHub
- Check the documentation
- Review the troubleshooting section
