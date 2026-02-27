# WPVIP Multisite MCP Abilities

A WordPress network (mu-plugin) that registers 26 MCP abilities for managing a WordPress Multisite installation via AI agents. Covers site management, content operations, Site Editor templates/template-parts/patterns, theme activation, user management, and site options.

## Requirements

- PHP 8.0+
- WordPress 6.9+
- WordPress Multisite (Network) enabled
- [MCP Abilities API](https://github.com/WordPress/wordpress-develop) (WordPress core or plugin providing `wp_register_ability`)
- MCP Adapter plugin (connects WordPress to MCP-compatible clients)

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

2. **Network Activate** the plugin from the Network Admin → Plugins screen (or via WP-CLI: `wp plugin activate WPVIP-Multisite-MCP-Abilities --network`).

This is a network-only plugin (`Network: true` in the plugin header). It must be network-activated to function.

## Permissions

Every ability requires **`manage_network_options`** (Super Admin). There are no public or lower-privilege abilities. This is intentional — these abilities perform network-wide operations.

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

### Content Management (4 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `create-post` | Write | Creates a post or page on a sub-site. Supports title, content (HTML/blocks), status, excerpt, slug, page template, and author. |
| `get-post` | Read | Retrieves a post/page by ID including content, status, permalink, edit URL, and page template. |
| `update-post` | Write | Partial updates — only include fields to change. Supports title, content, excerpt, status, slug, and page template. |
| `list-posts` | Read | Paginated list with post type, status, and search filters. |

### Site Options (2 abilities)

| Ability | Type | Description |
|---------|------|-------------|
| `get-site-option` | Read | Reads up to 20 options from a sub-site. Sensitive keys (auth salts, DB credentials, mail server passwords) are automatically redacted. |
| `update-site-option` | Write | Writes options from a curated allowlist. Supports page-ID-by-title resolution for `page_on_front` / `page_for_posts`. |

**Write-allowlisted options:** `blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page`, `posts_per_rss`, `rss_use_excerpt`, `blog_public`, `default_category`, `default_post_format`, `default_pingback_flag`, `default_comment_status`, `default_ping_status`, `require_name_email`, `comment_registration`, `close_comments_for_old_posts`, `close_comments_days_old`, `thread_comments`, `thread_comments_depth`, `page_comments`, `comments_per_page`, `default_comments_page`, `comment_order`, `comments_notify`, `moderation_notify`, `comment_moderation`, `comment_whitelist`, `comment_max_links`, `date_format`, `time_format`, `start_of_week`, `timezone_string`, `gmt_offset`, `permalink_structure`, `category_base`, `tag_base`, `thumbnail_size_w`, `thumbnail_size_h`, `thumbnail_crop`, `medium_size_w`, `medium_size_h`, `large_size_w`, `large_size_h`, `uploads_use_yearmonth_folders`.

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

**`wpvip-multisite-mcp-abilities.php`** — Main plugin file. Registers the `vip-multisite` ability category and all network/content/options abilities. Loads the site editor file via `require_once`.

**`wpvip-mcp-site-editor-abilities.php`** — Site Editor abilities plus REST API defence-in-depth infrastructure. Organised into five sections:

1. **REST API Support** — Ensures `wp_template`, `wp_template_part`, and `wp_block` post types are exposed via REST with correct controllers.
2. **Permissions** — `map_meta_cap` filter grants `edit_theme_options` users CRUD access to Site Editor post types via REST. Uses a static recursion guard. Delete capabilities are intentionally excluded.
3. **Security** — `rest_pre_dispatch` filter blocks unauthenticated REST access to Site Editor endpoints.
4. **Meta Registration** — Registers `wp_pattern_sync_status` post meta and ensures `wp_pattern_category` taxonomy is REST-visible.
5. **MCP Abilities** — The 10 Site Editor abilities, plus two shared helper functions.

### Key patterns

**`switch_to_blog` / `restore_current_blog`** — All abilities that operate on sub-sites wrap their work in `try/finally` to guarantee `restore_current_blog()` runs even on early returns or exceptions.

**`$response` variable** — Write abilities initialise a `$response` fallback array before `try`, assign it in every code path, and return it after `finally`. This prevents undefined variable issues and satisfies the `array` return type.

**Content sanitization** — The `vip_mcp_sanitize_content()` helper skips `wp_kses_post` for users with `unfiltered_html` capability (which Super Admins have). This prevents block markup corruption — Gutenberg's own controllers use the same approach.

**Category resolution** — The `vip_mcp_resolve_pattern_categories()` helper resolves category slugs to term IDs, auto-creating missing categories with human-readable names.

### Security model

| Layer | Protection |
|-------|-----------|
| MCP abilities | `manage_network_options` permission callback on every ability |
| REST endpoints | Authentication required + `edit_theme_options` capability check |
| Content writes | `wp_kses_post` for non-super-admin users; `wp_strip_all_tags` for titles; `sanitize_textarea_field` for excerpts/descriptions |
| Options writes | Explicit allowlist — unlisted keys are silently skipped |
| Options reads | Blocklist for credentials (auth keys/salts, DB/mail credentials) — returns `[redacted]` |
| Roles | Enum-validated (`administrator`, `editor`, `author`, `contributor`, `subscriber`) |
| Statuses | Enum-validated (`draft`, `publish`, `pending`, `private`, optionally `trash`) |
| Areas | Enum-validated (`header`, `footer`, `sidebar`, `uncategorized`) |
| Pagination | Capped at 100 results per page across all list abilities |
| Categories | Capped at 20 per create/update to prevent abuse |
| Delete operations | Not exposed — no delete abilities registered, no delete capabilities granted |

## Changelog

### 1.3.0
- Added Site Editor abilities: templates, template parts, and synced patterns (10 new abilities)
- Added REST API defence-in-depth for Site Editor post types
- Added `vip_mcp_sanitize_content()` — skips kses for `unfiltered_html` users to preserve block markup
- Added `vip_mcp_resolve_pattern_categories()` shared helper
- Template/template-part overrides now preserve title, content, description, and area from theme originals

### 1.2.3
- Initial release with 16 network management abilities
- Site management, content CRUD, theme activation, user management, site options
