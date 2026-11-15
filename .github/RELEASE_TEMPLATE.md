# Release Template

Use this template when creating manual releases or documenting release notes.

---

## Mumega MCP vX.X.X

**Release Date:** YYYY-MM-DD

### Summary

Brief overview of this release (1-2 sentences). What's the main focus?

Example:
> This release introduces advanced Elementor template management and improves API performance for large sites.

### Changes

#### New Features
- **Feature Name:** Description of the new feature and what it enables
- **Another Feature:** What it does and why it's useful

#### Enhancements
- Improved performance for X operation
- Enhanced error handling in Y module
- Better documentation for Z endpoint

#### Bug Fixes
- Fixed issue where X would fail under Y conditions
- Resolved Z error when user does ABC
- Corrected edge case in feature W

#### API Changes
- Added endpoint: `POST /wp-json/mcpwp/v1/new-endpoint`
- Modified endpoint: `GET /wp-json/mcpwp/v1/existing-endpoint` now accepts `param_name`
- Deprecated: `old_parameter` (will be removed in v2.0.0)

#### Licensed Features
- List any features exclusive to paid plans
- These require a valid Freemius license

### Upgrade Notes

**Breaking Changes:**
- List any breaking changes that require user action
- Include migration steps if needed

**Deprecations:**
- List deprecated features/APIs with timeline for removal

**Configuration Changes:**
- Any new settings or environment variables required
- Updated minimum requirements

**Database Changes:**
- Any schema updates or migrations
- Recommended backup steps

### Installation

#### WP.org-Compatible Version
1. Download `mcpwp-X.X.X-wporg.zip`
2. Go to WordPress Admin > Plugins > Add New > Upload
3. Upload the zip file and activate
4. Configure API keys in Mumega MCP > Setup

**Limitations:**
- Licensed modules are not included (forms, users, SEO, WooCommerce, etc.)
- Basic MCP endpoints only

#### Paid/Self-Hosted Version
1. Download `mcpwp-X.X.X.zip`
2. Ensure you have a valid Freemius license or trial
3. Go to WordPress Admin > Plugins > Add New > Upload
4. Upload the zip file and activate
5. Enter your license key when prompted

**Includes:**
- All licensed features
- Priority support
- Automatic updates via Freemius

### Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher (8.0+ recommended)
- **MySQL:** 5.6 or higher
- **Optional:** Elementor (for template features)
- **Optional:** WooCommerce (for ecommerce features - Pro only)

### Testing

This release has been tested with:
- WordPress 6.4.x
- PHP 7.4, 8.0, 8.1, 8.2
- Elementor 3.x
- WooCommerce 8.x

### Known Issues

- **Issue #XX:** Brief description and workaround
- **Issue #YY:** Brief description and expected fix version

### Security

**Security Fixes:**
- If this is a security release, list fixes here (after responsible disclosure)

**Security Advisories:**
- Link to any security advisories if applicable

### Contributors

Thanks to the following contributors for this release:
- @username1 - Feature/fix description
- @username2 - Feature/fix description

### Migration Guide (if major version)

#### From v1.x to v2.x

1. **Backup:** Always backup your database and files before upgrading
2. **Update Configuration:** Change X setting to Y format
3. **API Clients:** Update API calls to use new endpoint format
4. **Test:** Test functionality in staging environment first

### Links

- [Documentation](https://github.com/Digidinc/mcpwp/wiki)
- [API Reference](https://github.com/Digidinc/mcpwp/wiki/API-Reference)
- [Changelog](https://github.com/Digidinc/mcpwp/blob/main/CHANGELOG.md)
- [Support](https://github.com/Digidinc/mcpwp/issues)

---

Built with [Mumega MCP](https://github.com/Digidinc/mcpwp) - Control WordPress with AI via MCP

---

## Example Release

Below is an example of a completed release note:

---

## Mumega MCP v1.0.44

**Release Date:** 2026-02-10

### Summary

This release adds comprehensive Elementor template CRUD operations and improves license validation for paid features.

### Changes

#### New Features
- **Elementor Template CRUD:** Full create, read, update, delete operations for Elementor templates via API
- **License Gating:** Pro tools now require valid Freemius license activation

#### Enhancements
- Improved error messages for API endpoints
- Better validation for template data
- Enhanced documentation for MCP tools

#### Bug Fixes
- Fixed issue where template export would fail for complex nested sections
- Resolved license check error on multisite installations
- Corrected timezone handling in post scheduling

### Upgrade Notes

**No Breaking Changes**

This is a backward-compatible release. All existing API endpoints continue to work as before.

### Installation

#### WP.org-Compatible Version
Download `mcpwp-1.0.44-wporg.zip` and install via WordPress Admin > Plugins > Add New > Upload.

#### Paid/Self-Hosted Version
Download `mcpwp-1.0.44.zip`. Requires valid Freemius license for paid features.

### Requirements

- WordPress 5.0+
- PHP 7.4+
- Elementor (optional, for template features)

### Testing

Tested with WordPress 6.4.x, PHP 7.4-8.2, Elementor 3.x.

---

Built with [Mumega MCP](https://github.com/Digidinc/mcpwp)
