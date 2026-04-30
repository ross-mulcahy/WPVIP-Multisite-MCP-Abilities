# WPVIP MCP Abilities

A WordPress plugin (v1.6.0) that registers **38 MCP abilities** for managing WordPress sites via AI agents. Works on both single-site and Multisite installations. Site-level abilities (content, taxonomies, media, options, Site Editor) register everywhere; network-level abilities (sites, users, themes, plugins) register only on Multisite.

## Requirements

- PHP 8.0+
- WordPress 6.9+
- [MCP Abilities API](https://github.com/WordPress/wordpress-develop) (WordPress core or plugin providing `wp_register_ability`)
- MCP Adapter plugin (connects WordPress to MCP-compatible clients)
- Multisite (Network) enabled — optional; required only for network-level abilities

## Installation

1. Clone or download this repository into your `wp-content/plugins/` directory:

```
wp-content/plugins/
└── WPVIP-Multisite-MCP-Abilities/
    ├── wpvip-multisite-mcp-abilities.php   ← Main plugin file (auto-loads site editor file)
    ├── wpvip-mcp-site-editor-abilities.php ← Site Editor abilities + REST defence-in-depth
    ├── README.md
    └── LICENSE
```

2. **Activate** the plugin from the Plugins screen (or via WP-CLI: `wp plugin activate WPVIP-Multisite-MCP-Abilities`). On Multisite, you can also network-activate from Network Admin → Plugins (or `wp plugin activate WPVIP-Multisite-MCP-Abilities --network`).

On single-site, 28 site-level abilities register. On Multisite, all 38 abilities register (whether activated per-site or network-wide).

## Permissions

Abilities use a two-layer permission model:

1. **`permission_callback`** — gates whether the ability is visible to the user (baseline capability check)
2. **`execute_callback`** — enforces per-site capabilities after `switch_to_blog()` to ensure the user has the right permissions on the target site

| Ability group | permission_callback | Per-site check |
|---|---|---|
| Network ops (sites, users, themes, plugins) | `manage_network_options` | — |
| Content reads (inventory, post types, taxonomies, terms, posts) | `read` | `read` / `read_post` on target site |
| Content writes (create/update posts, assign terms, rewrite content) | `edit_posts` | Post-type caps (`edit_posts`, `publish_posts`), `edit_post`, and taxonomy assign caps on target site |
| Term writes (create/update terms) | `manage_categories` | Taxonomy caps (`manage_terms`, `edit_terms`) on target site |
| Media reads/copy | `upload_files` | `upload_files`, `read_post`, and optional parent `edit_post` on target site |
| Site options (get/update) | `manage_options` | `manage_options` on target site |
| Site editor (templates, parts, patterns) | `edit_theme_options` | `edit_theme_options` on target site |

## Abilities Reference

### Network Management (6 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-sites` | Read | Paginated list of all network sites. Supports search, optional `include_options` toggle for lightweight listing on large networks. |
| `create-site` | Write | Creates a new sub-site. Supports subdomain or subdirectory installs, optional user auto-creation via `create_user_if_missing`. |
| `get-site` | Read | Full site details including name, URL, admin email, active theme, and up to 20 site users. |
| `update-site` | Write | Updates site name, description, public visibility, or admin email. |
| `list-network-plugins` | Read | Lists all network-activated plugins. |
| `list-network-users` | Read | Paginated user list with search and optional `site_id` filter. |

### User Management (2 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `add-user-to-site` | Write | Adds an existing network user to a site with a specified role (`administrator`, `editor`, `author`, `contributor`, `subscriber`). |
| `create-network-user` | Write | Creates a new network user account with username, email, and display name. |

### Theme Management (2 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-themes` | Read | All installed themes with network-enabled status. Optionally shows which is active on a given site. |
| `activate-theme` | Write | Activates a theme on a specific site. Auto-network-enables the theme if needed (controllable via `network_enable`). |

### Site Inventory (1 ability)

| Ability | Type | Description |
|---------|------|-------------|
| `inventory-site` | Read | Compact migration inventory: site identity, active theme, key options, public post type counts, taxonomy counts, and media counts. |

### Taxonomy & Term Management (6 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-taxonomies` | Read | Discovers registered taxonomies on a site, optionally scoped to a post type. |
| `list-terms` | Read | Paginated list of terms for a taxonomy, including hierarchy, URLs, and registered term meta. |
| `get-term` | Read | Retrieves a term by ID or slug, including registered term meta. |
| `create-term` | Write | Creates a taxonomy term with slug, description, parent, and registered term meta. |
| `update-term` | Write | Updates a taxonomy term and registered term meta. |
| `assign-post-terms` | Write | Assigns or appends terms to a post/page/CPT entry. Supports IDs, slugs, names, and optional missing-term creation. |

### Content Management (5 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-post-types` | Read | Discovers all registered post types on a site, including custom post types. Returns labels, capabilities, supported features, REST info, and connected taxonomies. |
| `create-post` | Write | Creates a post, page, or custom post type entry. Supports title, content (HTML/blocks), status, excerpt, slug, template, author, parent, menu order, comment/ping status, featured image, terms, and registered custom fields. |
| `get-post` | Read | Retrieves any post by ID including content, status, permalink, edit URL, template, parent/author/menu metadata, featured image, assigned terms, and registered custom field values. |
| `update-post` | Write | Partial updates — only include fields to change. Supports title, content, excerpt, status, slug, author, parent, menu order, comment/ping status, template, featured image, terms, and registered custom fields. |
| `list-posts` | Read | Paginated list of any post type with status, search, author, parent, and taxonomy filters. Can optionally include assigned terms. |

### Media & Content Rewriting (4 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-media` | Read | Paginated list of media attachments with source URLs, alt text, captions, descriptions, metadata, parent IDs, and file details. |
| `get-media` | Read | Retrieves a single attachment by ID with source URL, alt text, caption, description, and metadata. |
| `copy-media-to-site` | Write | Copies an attachment from a source site to a target site, preserving media fields and returning a source URL to target URL map. |
| `rewrite-content-links` | Write | Rewrites exact URLs or strings in supplied content or an existing post using a source-to-target map. Useful after copying media and linked posts. |

### Site Options (2 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `get-site-option` | Read | Reads up to 20 options from a sub-site. Sensitive keys (auth salts, DB credentials, mail server passwords) are automatically redacted. |
| `update-site-option` | Write | Writes options from a curated allowlist. Supports page-ID-by-title resolution for `page_on_front` / `page_for_posts`. |

**Write-allowlisted options:** `blogname`, `blogdescription`, `WPLANG`, `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page`, `posts_per_rss`, `rss_use_excerpt`, `blog_public`, `default_category`, `default_post_format`, `default_pingback_flag`, `default_comment_status`, `default_ping_status`, `require_name_email`, `comment_registration`, `close_comments_for_old_posts`, `close_comments_days_old`, `thread_comments`, `thread_comments_depth`, `page_comments`, `comments_per_page`, `default_comments_page`, `comment_order`, `comments_notify`, `moderation_notify`, `comment_moderation`, `comment_whitelist`, `comment_max_links`, `date_format`, `time_format`, `start_of_week`, `timezone_string`, `gmt_offset`, `permalink_structure`, `category_base`, `tag_base`, `thumbnail_size_w`, `thumbnail_size_h`, `thumbnail_crop`, `medium_size_w`, `medium_size_h`, `large_size_w`, `large_size_h`, `uploads_use_yearmonth_folders`.

### Site Editor — Templates (3 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-templates` | Read | All templates (theme-supplied and user-customised) on a sub-site. Indicates source (`theme` or `custom`) and whether a DB override post exists. |
| `get-template` | Read | Full template details including block markup content. Accepts `theme-slug//slug` ID format. |
| `update-template` | Write | Updates a template's content, title, or description. Creates a custom override post if the template hasn't been customised yet, preserving the original title, content, and description. |

### Site Editor — Template Parts (3 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-template-parts` | Read | All template parts on a sub-site. Supports optional `area` filter (`header`, `footer`, `sidebar`, `uncategorized`). |
| `get-template-part` | Read | Full template part details including block markup, area assignment, and source. |
| `update-template-part` | Write | Updates content, title, description, or area. Creates override posts for theme-only parts, preserving title, content, description, and area from the original. |

### Site Editor — Synced Patterns (4 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `list-patterns` | Read | Paginated list of synced patterns (`wp_block`). Filterable by category slug, sync status, and search keyword. |
| `get-pattern` | Read | Full pattern details including content, sync status, and categories. |
| `create-pattern` | Write | Creates a new synced or unsynced pattern. Supports category assignment — categories are auto-created if they don't exist (capped at 20). |
| `update-pattern` | Write | Partial updates to title, content, sync status, or categories. |

## Architecture

### File structure

The plugin is split into two files:

**`wpvip-multisite-mcp-abilities.php`** — Main plugin file. Registers the `vip-multisite` ability category, multisite-compatibility helpers, and all network/content/taxonomy/media/options abilities. Loads the site editor file via `require_once`.

**`wpvip-mcp-site-editor-abilities.php`** — Site Editor abilities plus REST API defence-in-depth infrastructure. Organised into five sections:

1. **REST API Support** — Ensures `wp_template`, `wp_template_part`, and `wp_block` post types are exposed via REST with correct controllers.
2. **Permissions** — `map_meta_cap` filter grants `edit_theme_options` users CRUD access to Site Editor post types via REST. Uses a static recursion guard. Delete capabilities are intentionally excluded.
3. **Security** — `rest_pre_dispatch` filter blocks unauthenticated REST access to Site Editor endpoints.
4. **Meta Registration** — Registers `wp_pattern_sync_status` post meta and ensures `wp_pattern_category` taxonomy is REST-visible.
5. **MCP Abilities** — The 10 Site Editor abilities, plus two shared helper functions.

### Key patterns

**Single-site / Multisite compatibility** — Five helper functions (`vip_mcp_resolve_site_id`, `vip_mcp_validate_site`, `vip_mcp_switch_to_site`, `vip_mcp_restore_site`, `vip_mcp_required_with_site_id`) abstract blog-switching so the same ability code runs on both single-site and Multisite. On single-site the switch/restore calls are no-ops and `site_id` defaults to `get_current_blog_id()`. All site-level abilities wrap work in `try/finally` to guarantee context restoration.

**`$response` variable** — Write abilities initialise a `$response` fallback array before `try`, assign it in every code path, and return it after `finally`. This prevents undefined variable issues and satisfies the `array` return type.

**Content sanitization** — The `vip_mcp_sanitize_content()` helper skips `wp_kses_post` for users with `unfiltered_html` capability (which Super Admins have). This prevents block markup corruption — Gutenberg's own controllers use the same approach.

**Category resolution** — The `vip_mcp_resolve_pattern_categories()` helper resolves category slugs to term IDs, auto-creating missing categories with human-readable names.

### Security model

| Layer | Protection |
|-------|-----------|
| MCP abilities | Two-layer permission model: `permission_callback` for visibility + per-site capability check in `execute_callback` |
| REST endpoints | Authentication required + `edit_theme_options` capability check |
| Content writes | `wp_kses_post` for non-super-admin users; `wp_strip_all_tags` for titles; `sanitize_textarea_field` for excerpts/descriptions |
| Options writes | Explicit allowlist — unlisted keys are silently skipped |
| Options reads | Blocklist for credentials (auth keys/salts, DB/mail credentials) — returns `[redacted]` |
| Post types | Validated at runtime via `get_post_type_object()` — only registered types are accepted |
| Taxonomies | Validated at runtime via `get_taxonomy()` and `is_object_in_taxonomy()` — only registered taxonomies assigned to the post type are accepted |
| Custom fields | Writes restricted to keys registered via `register_post_meta()` / `register_term_meta()` (defence-in-depth) |
| Roles | Enum-validated (`administrator`, `editor`, `author`, `contributor`, `subscriber`) |
| Statuses | Enum-validated (`draft`, `publish`, `pending`, `private`, optionally `trash`) |
| Areas | Enum-validated (`header`, `footer`, `sidebar`, `uncategorized`) |
| Pagination | Capped at 100 results per page across all list abilities |
| Categories | Capped at 20 per create/update to prevent abuse |
| Delete operations | Not exposed — no delete abilities registered, no delete capabilities granted |

## Development

```bash
composer install          # Install dev dependencies
composer run phpcs        # WordPress Coding Standards + PHP compatibility
composer run phpcbf       # Auto-fix PHPCS violations
composer run phpstan      # Static analysis (level 6, WordPress stubs)
composer run lint         # Run both PHPCS and PHPStan
```

## Changelog

### 1.6.0
- Added site inventory ability for translation/migration planning
- Added taxonomy and term abilities: list taxonomies, list/get/create/update terms, and assign post terms
- Expanded post create/get/update/list support for terms, featured images, parent IDs, author IDs, menu order, comment/ping status, and taxonomy filters
- Added media abilities for listing, reading, and copying attachments between sites
- Added content link rewriting ability for source-to-target URL maps after media/content copy
- Added `WPLANG` to the safe option allowlist for translated site setup

### 1.5.3
- Removed `Network: true` plugin header so the plugin can be activated on single-site installations

### 1.5.2
- Fixed `get-template-part` output schema: `area` field now allows `null` for template parts without an explicit area assignment

### 1.5.1
- Fixed infinite recursion in `vip_mcp_switch_to_site()` and `vip_mcp_restore_site()` helpers that caused all site-context abilities to return HTTP 500

### 1.5.0
- **Single-site compatibility** — site-level abilities (content, options, Site Editor) now register on both single-site and Multisite installations; network-level abilities remain Multisite-only
- Added multisite-compatibility helpers (`vip_mcp_resolve_site_id`, `vip_mcp_validate_site`, `vip_mcp_switch_to_site`, `vip_mcp_restore_site`, `vip_mcp_required_with_site_id`) so the same code runs on both configurations
- `site_id` parameter is now optional on single-site (defaults to current blog)
- **Granular permissions** — replaced blanket `manage_network_options` with a two-layer model: `permission_callback` gates visibility by capability tier; `execute_callback` enforces per-site capabilities after `switch_to_blog()`
- Content reads require `read`; content writes require `edit_posts` + post-type caps; options require `manage_options`; Site Editor requires `edit_theme_options`
- **Custom post type support** — new `list-post-types` ability for CPT discovery; `create-post` and `list-posts` now accept any registered post type (not just `post`/`page`), validated at runtime via `get_post_type_object()`
- **Custom field support** — `create-post`, `get-post`, and `update-post` now read/write registered custom fields via `meta` parameter (restricted to keys registered with `register_post_meta()`)
- Added PHPCS (WordPress Coding Standards + PHP Compatibility) and PHPStan (level 6) tooling

### 1.3.0
- Added Site Editor abilities: templates, template parts, and synced patterns (10 new abilities)
- Added REST API defence-in-depth for Site Editor post types
- Added `vip_mcp_sanitize_content()` — skips kses for `unfiltered_html` users to preserve block markup
- Added `vip_mcp_resolve_pattern_categories()` shared helper
- Template/template-part overrides now preserve title, content, description, and area from theme originals

### 1.2.3
- Initial release with 16 network management abilities
- Site management, content CRUD, theme activation, user management, site options
