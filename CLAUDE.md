# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin (v1.5.0) that registers **28 MCP abilities** for managing WordPress sites via AI agents. Works on both single-site and Multisite installations. Site-level abilities (content, options, Site Editor) register everywhere; network-level abilities (sites, users, themes, plugins) register only on Multisite. Requires PHP 8.0+, WordPress 6.9+. No external dependencies.

## Repository Structure

Two PHP files, no build system:

- **`wpvip-multisite-mcp-abilities.php`** — Main plugin file. Registers the `vip-multisite` ability category, multisite-compatibility helpers, and 18 abilities (sites, users, themes, posts/CPTs, options).
- **`wpvip-mcp-site-editor-abilities.php`** — Registers 10 Site Editor abilities (templates, template parts, synced patterns) plus REST API defence-in-depth infrastructure (capability mapping, auth enforcement, meta/taxonomy registration).

## No Build/Test/Lint

There are no package.json, composer.json, phpcs.xml, phpstan.neon, or CI/CD config files. The plugin is installed by placing it in `wp-content/plugins/` (or `client-mu-plugins/` on VIP) and activating (or network-activating on Multisite).

## Architecture

### Hook Registration
```
wp_abilities_api_categories_init → vip_mcp_register_multisite_category()
wp_abilities_api_init            → vip_mcp_register_multisite_abilities()
                                 → vip_mcp_register_site_editor_abilities()
```

### Ability Registration Pattern
Each ability is registered via a dedicated `vip_mcp_register_ability_*()` function with:
- `input_schema` / `output_schema` (JSON Schema)
- `permission_callback` — gates visibility by capability tier (see Security Model)
- `execute_callback` — the implementation

### Single-Site / Multisite Compatibility
Five helper functions (`vip_mcp_resolve_site_id`, `vip_mcp_validate_site`, `vip_mcp_switch_to_site`, `vip_mcp_restore_site`, `vip_mcp_required_with_site_id`) abstract blog-switching so the same ability code runs on both single-site and Multisite. On single-site the switch/restore calls are no-ops and `site_id` defaults to `get_current_blog_id()`.

All site-level abilities wrap work in `try/finally` to guarantee context restoration:
```php
vip_mcp_switch_to_site($site_id);
try {
    // work
} finally {
    vip_mcp_restore_site();
}
```

### Content Sanitization
`vip_mcp_sanitize_content()` skips `wp_kses_post` for `unfiltered_html` users (Super Admins) to preserve Gutenberg block markup. Non-super-admin content goes through `wp_kses_post`.

### Site Editor REST Defence (4 layers in site editor file)
1. Forces `wp_template`, `wp_template_part`, `wp_block` post types to be REST-visible
2. Maps Site Editor capabilities to `edit_theme_options` via `map_meta_cap` (with recursion guard)
3. Blocks unauthenticated REST access to Site Editor endpoints via `rest_pre_dispatch`
4. Registers `wp_pattern_sync_status` meta and ensures `wp_pattern_category` taxonomy is REST-visible

### Security Model
- Two-layer permission enforcement: `permission_callback` gates visibility; `execute_callback` enforces per-site capabilities after `switch_to_blog()`
- Network ops (sites, users, themes, plugins) require `manage_network_options` (Super Admin)
- Content reads (list-post-types, get-post, list-posts) require `read` + per-site `read`/`read_post`
- Content writes (create-post, update-post) require `edit_posts` + per-site post-type caps (`edit_posts`, `publish_posts`)
- Site options (get/update) require `manage_options` + per-site `manage_options`
- Site editor (templates, parts, patterns) require `edit_theme_options` + per-site `edit_theme_options`
- Options writes use an explicit allowlist (~45 safe options); unlisted keys are silently skipped
- Options reads redact credentials (auth salts, DB/mail passwords)
- Roles, statuses, and areas are enum-validated
- Post type validated at runtime via `get_post_type_object()` — only registered types are accepted
- Custom field writes restricted to keys registered via `register_post_meta()` (defence-in-depth)
- Pagination capped at 100 per page
- **No delete abilities are exposed**

### Code Style
- Procedural PHP — no classes, no namespaces
- All functions prefixed with `vip_mcp_`
- Response variables initialized before `try` blocks to satisfy return types
- Template/template-part updates create override posts preserving original title/content/area
