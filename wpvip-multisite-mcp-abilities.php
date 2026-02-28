<?php
/**
 * Plugin Name: WPVIP Multisite MCP Abilities
 * Plugin URI:  https://wpvip.com
 * Description: Registers WordPress Multisite network management abilities for MCP/AI access.
 *              Enables creating sites, activating themes, managing users, and listing network
 *              resources — all accessible via MCP-connected AI agents.
 * Version:     1.2.3
 * Author:      Ross Mulcahy
 * Requires PHP: 8.0
 * Requires WP:  6.9
 * Network:     true
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package wpvip-multisite-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/wpvip-mcp-site-editor-abilities.php';

// Core wp_register_ability_category() requires doing_action('wp_abilities_api_categories_init').
// Core wp_register_ability() requires doing_action('wp_abilities_api_init').
// These hooks fire from within their respective registry get_instance() calls.
// The registry is first instantiated when something calls wp_get_abilities() or similar —
// which the MCP Adapter does on rest_api_init. So these hooks fire during that request.
add_action( 'wp_abilities_api_categories_init', 'vip_mcp_register_multisite_category' );
add_action( 'wp_abilities_api_init', 'vip_mcp_register_multisite_abilities' );

/**
 * Register the VIP Multisite ability category.
 * Must be called on wp_abilities_api_categories_init — core enforces this with doing_action() check.
 */
function vip_mcp_register_multisite_category(): void {
	wp_register_ability_category(
		'vip-multisite',
		array(
			'label'       => 'VIP Multisite',
			'description' => 'WordPress multisite network management abilities.',
		)
	);
}

/**
 * Register all multisite management abilities.
 * Must be called on wp_abilities_api_init — core enforces this with doing_action() check.
 */
function vip_mcp_register_multisite_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	if ( ! is_multisite() ) {
		return;
	}

	vip_mcp_register_ability_list_sites();
	vip_mcp_register_ability_create_site();
	vip_mcp_register_ability_get_site();
	vip_mcp_register_ability_update_site();
	vip_mcp_register_ability_list_themes();
	vip_mcp_register_ability_activate_theme();
	vip_mcp_register_ability_list_network_users();
	vip_mcp_register_ability_add_user_to_site();
	vip_mcp_register_ability_create_network_user();
	vip_mcp_register_ability_list_network_plugins();

	// Content management abilities.
	vip_mcp_register_ability_create_post();
	vip_mcp_register_ability_get_post();
	vip_mcp_register_ability_update_post();
	vip_mcp_register_ability_list_posts();

	// Site options abilities.
	vip_mcp_register_ability_get_site_option();
	vip_mcp_register_ability_update_site_option();
}


// =========================================================================
// ABILITY DEFINITIONS
// =========================================================================

/**
 * 1. List all sites in the network.
 */
function vip_mcp_register_ability_list_sites(): void {
	wp_register_ability(
		'vip-multisite/list-sites',
		array(
			'label'       => 'List Network Sites',
			'description' => 'Returns all sites registered in the WordPress multisite network.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Number of sites to return per page (default 50, max 100).',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 100,
					),
					'page' => array(
						'type'        => 'integer',
						'description' => 'Page number for pagination (default 1).',
						'default'     => 1,
						'minimum'     => 1,
					),
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional search term to filter sites by domain or path.',
					),
					'include_options' => array(
						'type'        => 'boolean',
						'description' => 'Include blogname, description, and active_theme in results. Each requires a per-site DB query. Set false for lightweight listing on large networks. Default true.',
						'default'     => true,
					),
				),
			),
			'output_schema' => array(
				'type'  => 'object',
				'properties' => array(
					'sites'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$per_page = min( max( (int) ( $input['per_page'] ?? 50 ), 1 ), 100 );
				$page     = max( (int) ( $input['page'] ?? 1 ), 1 );
				$search   = $input['search'] ?? '';

				$args = array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
					'public' => null,
				);

				if ( $search ) {
					$args['search'] = '*' . sanitize_text_field( $search ) . '*';
				}

				$sites = get_sites( $args );
				$total = get_sites( array_merge( $args, array( 'number' => 0, 'count' => true ) ) );

				// Note: get_blog_option() issues one query per site per key — WordPress has no
				// bulk API for cross-blog options. Pass include_options=false for lightweight
				// listing on large networks to skip the per-site option queries entirely.
				$include_options = $input['include_options'] ?? true;

				$result = array();
				foreach ( $sites as $site ) {
					$row = array(
						'id'           => (int) $site->blog_id,
						'domain'       => $site->domain,
						'path'         => $site->path,
						'url'          => get_site_url( $site->blog_id ),
						'registered'   => $site->registered,
						'last_updated' => $site->last_updated,
						'public'       => (bool) $site->public,
						'archived'     => (bool) $site->archived,
						'deleted'      => (bool) $site->deleted,
						'spam'         => (bool) $site->spam,
					);

					if ( $include_options ) {
						$row['name']         = get_blog_option( $site->blog_id, 'blogname' );
						$row['description']  = get_blog_option( $site->blog_id, 'blogdescription' );
						$row['active_theme'] = get_blog_option( $site->blog_id, 'stylesheet' );
					}

					$result[] = $row;
				}

				return array(
					'sites'       => $result,
					'total'       => (int) $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 2. Create a new site in the network.
 */
function vip_mcp_register_ability_create_site(): void {
	wp_register_ability(
		'vip-multisite/create-site',
		array(
			'label'       => 'Create Network Site',
			'description' => 'Creates a new site in the WordPress multisite network.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'domain', 'title', 'admin_email' ),
				'properties' => array(
					'domain' => array(
						'type'        => 'string',
						'description' => 'The slug for the new site (e.g. "newsroom"). Used as subdomain or subdirectory depending on network config.',
					),
					'title' => array(
						'type'        => 'string',
						'description' => 'The display name / title of the new site.',
					),
					'admin_email' => array(
						'type'        => 'string',
						'description' => 'Email address of the site administrator. Must match an existing network user unless create_user_if_missing is set to true.',
					),
					'public' => array(
						'type'        => 'boolean',
						'description' => 'Whether the site is publicly visible (default true).',
						'default'     => true,
					),
					'create_user_if_missing' => array(
						'type'        => 'boolean',
						'description' => 'If true and admin_email does not match an existing user, a new network user will be created automatically. Defaults to false.',
						'default'     => false,
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'site_id'  => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$network = get_network();
				$base    = $network->domain;
				$slug    = sanitize_title( $input['domain'] );

				if ( empty( $slug ) ) {
					return array( 'success' => false, 'site_id' => 0, 'url' => '', 'message' => "Invalid site slug — the domain value produced an empty slug after sanitization." );
				}

				if ( is_subdomain_install() ) {
					$domain = $slug . '.' . $base;
					$path   = '/';
				} else {
					$domain = $base;
					$path   = $network->path . $slug . '/';
				}

				$admin_email = sanitize_email( $input['admin_email'] );
				if ( empty( $admin_email ) ) {
					return array( 'success' => false, 'site_id' => 0, 'url' => '', 'message' => 'Invalid email address.' );
				}

				$user = get_user_by( 'email', $admin_email );
				if ( ! $user ) {
					if ( empty( $input['create_user_if_missing'] ) ) {
						return array(
							'success' => false,
							'site_id' => 0,
							'url'     => '',
							'message' => "No network user found for '{$admin_email}'. Set create_user_if_missing to true to auto-create a user.",
						);
					}
					$base_username = sanitize_user( strstr( $admin_email, '@', true ), true );
					$username      = $base_username;
					$suffix        = 1;
					$max_attempts  = 100;
					while ( username_exists( $username ) && $suffix <= $max_attempts ) {
						$username = $base_username . $suffix;
						$suffix++;
					}
					if ( username_exists( $username ) ) {
						return array( 'success' => false, 'site_id' => 0, 'url' => '', 'message' => "Could not generate a unique username for '{$admin_email}'. Too many collisions." );
					}
					$user_id = wpmu_create_user( $username, wp_generate_password(), $admin_email );
					if ( ! $user_id ) {
						return array( 'success' => false, 'site_id' => 0, 'url' => '', 'message' => "Failed to create user for '{$admin_email}'." );
					}
					wp_new_user_notification( $user_id, null, 'user' );
				} else {
					$user_id = $user->ID;
				}

				$site_id = wpmu_create_blog(
					$domain,
					$path,
					wp_strip_all_tags( $input['title'] ),
					$user_id,
					array( 'public' => isset( $input['public'] ) ? (int) $input['public'] : 1 ),
					get_current_network_id()
				);

				if ( is_wp_error( $site_id ) ) {
					return array( 'success' => false, 'site_id' => 0, 'url' => '', 'message' => $site_id->get_error_message() );
				}

				return array(
					'success' => true,
					'site_id' => (int) $site_id,
					'url'     => get_site_url( $site_id ),
					'message' => sprintf( 'Site "%s" created successfully (ID: %d).', $input['title'], $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 3. Get details for a specific site.
 */
function vip_mcp_register_ability_get_site(): void {
	wp_register_ability(
		'vip-multisite/get-site',
		array(
			'label'       => 'Get Site Details',
			'description' => 'Returns detailed information about a specific site in the network.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the site to retrieve.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'domain'       => array( 'type' => 'string' ),
					'path'         => array( 'type' => 'string' ),
					'url'          => array( 'type' => 'string' ),
					'admin_url'    => array( 'type' => 'string' ),
					'name'         => array( 'type' => 'string' ),
					'description'  => array( 'type' => 'string' ),
					'registered'   => array( 'type' => 'string' ),
					'last_updated' => array( 'type' => 'string' ),
					'public'       => array( 'type' => 'boolean' ),
					'archived'     => array( 'type' => 'boolean' ),
					'deleted'      => array( 'type' => 'boolean' ),
					'spam'         => array( 'type' => 'boolean' ),
					'active_theme' => array( 'type' => 'string' ),
					'admin_email'  => array( 'type' => 'string' ),
					'users'        => array( 'type' => 'array' ),
					'success'      => array( 'type' => 'boolean' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$site    = get_site( $site_id );

				if ( ! $site ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}

				$users     = get_users( array( 'blog_id' => $site_id, 'fields' => array( 'ID', 'user_login', 'user_email' ), 'number' => 20 ) );
				$user_list = array_map( static fn( $u ) => array( 'id' => $u->ID, 'username' => $u->user_login, 'email' => $u->user_email ), $users );

				return array(
					'success'      => true,
					'id'           => (int) $site->blog_id,
					'domain'       => $site->domain,
					'path'         => $site->path,
					'url'          => get_site_url( $site_id ),
					'admin_url'    => get_admin_url( $site_id ),
					'name'         => get_blog_option( $site_id, 'blogname' ),
					'description'  => get_blog_option( $site_id, 'blogdescription' ),
					'registered'   => $site->registered,
					'last_updated' => $site->last_updated,
					'public'       => (bool) $site->public,
					'archived'     => (bool) $site->archived,
					'deleted'      => (bool) $site->deleted,
					'spam'         => (bool) $site->spam,
					'active_theme' => get_blog_option( $site_id, 'stylesheet' ),
					'admin_email'  => get_blog_option( $site_id, 'admin_email' ),
					'users'        => $user_list,
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 4. Update a site's basic settings.
 */
function vip_mcp_register_ability_update_site(): void {
	wp_register_ability(
		'vip-multisite/update-site',
		array(
			'label'       => 'Update Site Settings',
			'description' => 'Updates settings (name, description, public visibility) for a network site.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id'     => array( 'type' => 'integer', 'description' => 'The ID of the site to update.' ),
					'name'        => array( 'type' => 'string',  'description' => 'New display name for the site.' ),
					'description' => array( 'type' => 'string',  'description' => 'New tagline / description.' ),
					'public'      => array( 'type' => 'boolean', 'description' => 'Set whether the site is publicly visible.' ),
					'admin_email' => array( 'type' => 'string',  'description' => 'New admin email address.' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}

				// --- Validation phase — validate all inputs before writing anything. ---

				if ( ! isset( $input['name'] ) && ! isset( $input['description'] ) &&
				     ! isset( $input['public'] ) && ! isset( $input['admin_email'] ) ) {
					return array( 'success' => false, 'message' => 'No fields provided to update.' );
				}

				$validated_email = null;
				if ( isset( $input['admin_email'] ) ) {
					$validated_email = sanitize_email( $input['admin_email'] );
					if ( empty( $validated_email ) ) {
						return array( 'success' => false, 'message' => 'Invalid admin email address.' );
					}
					if ( ! get_user_by( 'email', $validated_email ) ) {
						return array( 'success' => false, 'message' => "Admin email must belong to an existing network user. No user found for '{$validated_email}'." );
					}
				}

				// --- Write phase — only reached if all validation passed. ---

				$updated = array();

				if ( isset( $input['name'] ) ) {
					update_blog_option( $site_id, 'blogname', sanitize_text_field( $input['name'] ) );
					$updated[] = 'name';
				}
				if ( isset( $input['description'] ) ) {
					update_blog_option( $site_id, 'blogdescription', sanitize_text_field( $input['description'] ) );
					$updated[] = 'description';
				}
				if ( isset( $input['public'] ) ) {
					update_blog_option( $site_id, 'blog_public', (int) $input['public'] );
					update_blog_status( $site_id, 'public', (int) $input['public'] );
					$updated[] = 'public';
				}
				if ( null !== $validated_email ) {
					update_blog_option( $site_id, 'admin_email', $validated_email );
					$updated[] = 'admin_email';
				}

				return array(
					'success' => true,
					'message' => sprintf( 'Updated fields for site %d: %s.', $site_id, implode( ', ', $updated ) ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 5. List all themes available on the network.
 */
function vip_mcp_register_ability_list_themes(): void {
	wp_register_ability(
		'vip-multisite/list-themes',
		array(
			'label'       => 'List Network Themes',
			'description' => 'Returns all themes installed on the network, including which are network-enabled.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'Optional site ID to check which theme is active on that site.',
					),
				),
			),
			'output_schema' => array(
				'type'  => 'object',
				'properties' => array(
					'themes' => array( 'type' => 'array' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$all_themes     = wp_get_themes();
				$allowed_themes = get_site_option( 'allowedthemes', array() );
				$site_id        = isset( $input['site_id'] ) ? (int) $input['site_id'] : null;
				$site_active    = $site_id ? get_blog_option( $site_id, 'stylesheet' ) : null;

				$result = array();
				foreach ( $all_themes as $slug => $theme ) {
					$result[] = array(
						'slug'            => $slug,
						'name'            => $theme->get( 'Name' ),
						'version'         => $theme->get( 'Version' ),
						'author'          => wp_strip_all_tags( $theme->get( 'Author' ) ),
						'description'     => wp_strip_all_tags( $theme->get( 'Description' ) ),
						'network_enabled' => isset( $allowed_themes[ $slug ] ),
						'active_on_site'  => $site_active === $slug,
					);
				}

				return array( 'themes' => $result );
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 6. Activate a theme for a specific site.
 */
function vip_mcp_register_ability_activate_theme(): void {
	wp_register_ability(
		'vip-multisite/activate-theme',
		array(
			'label'       => 'Activate Theme on Site',
			'description' => 'Activates a theme on a specific network site, network-enabling it first if necessary.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id', 'theme_slug' ),
				'properties' => array(
					'site_id'        => array( 'type' => 'integer', 'description' => 'The ID of the site to activate the theme on.' ),
					'theme_slug'     => array( 'type' => 'string',  'description' => 'The theme stylesheet slug (directory name) to activate.' ),
					'network_enable' => array( 'type' => 'boolean', 'description' => 'Whether to also network-enable the theme if not already (default true).', 'default' => true ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'message'    => array( 'type' => 'string' ),
					'theme_name' => array( 'type' => 'string' ),
					'site_url'   => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id    = (int) $input['site_id'];
				$theme_slug = sanitize_text_field( $input['theme_slug'] );

				$site = get_site( $site_id );
				if ( ! $site ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} does not exist.", 'theme_name' => '', 'site_url' => '' );
				}

				$theme = wp_get_theme( $theme_slug );
				if ( ! $theme->exists() ) {
					return array( 'success' => false, 'message' => "Theme '{$theme_slug}' is not installed.", 'theme_name' => '', 'site_url' => get_site_url( $site_id ) );
				}

				if ( $input['network_enable'] ?? true ) {
					$allowed = get_site_option( 'allowedthemes', array() );
					if ( ! isset( $allowed[ $theme_slug ] ) ) {
						$allowed[ $theme_slug ] = true;
						update_site_option( 'allowedthemes', $allowed );
					}
				}

				switch_to_blog( $site_id );
				try {
					switch_theme( $theme_slug ); // Handles template/stylesheet correctly, fires switch_theme and after_switch_theme hooks.
				} finally {
					restore_current_blog();
				}

				return array(
					'success'    => true,
					'message'    => sprintf( 'Theme "%s" activated on site "%s" (ID: %d).', $theme->get( 'Name' ), get_blog_option( $site_id, 'blogname' ), $site_id ),
					'theme_name' => $theme->get( 'Name' ),
					'site_url'   => get_site_url( $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 7. List all users in the network.
 */
function vip_mcp_register_ability_list_network_users(): void {
	wp_register_ability(
		'vip-multisite/list-network-users',
		array(
			'label'       => 'List Network Users',
			'description' => 'Returns all users registered in the WordPress multisite network.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'maximum' => 100, 'minimum' => 1 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'search'   => array( 'type' => 'string',  'description' => 'Search by username, email, or display name.' ),
					'site_id'  => array( 'type' => 'integer', 'description' => 'Filter to users belonging to a specific site.' ),
				),
			),
			'output_schema' => array(
				'type' => 'object',
				'properties' => array(
					'users'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$per_page = min( max( (int) ( $input['per_page'] ?? 50 ), 1 ), 100 );
				$page     = max( (int) ( $input['page'] ?? 1 ), 1 );

				$args = array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				);

				if ( ! empty( $input['search'] ) ) {
					$args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
					$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
				}
				if ( ! empty( $input['site_id'] ) ) {
					$args['blog_id'] = (int) $input['site_id'];
				}

				$query = new WP_User_Query( $args );
				$users = $query->get_results();
				$total = $query->get_total();

				$result = array();
				foreach ( $users as $user ) {
					$result[] = array(
						'id'           => $user->ID,
						'username'     => $user->user_login,
						'display_name' => $user->display_name,
						'email'        => $user->user_email,
						'registered'   => $user->user_registered,
						'super_admin'  => is_super_admin( $user->ID ),
					);
				}

				return array(
					'users'       => $result,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 8. Add an existing user to a site with a given role.
 */
function vip_mcp_register_ability_add_user_to_site(): void {
	wp_register_ability(
		'vip-multisite/add-user-to-site',
		array(
			'label'       => 'Add User to Site',
			'description' => 'Adds an existing network user to a specific site with a given role.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id', 'user_id', 'role' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the site.' ),
					'user_id' => array( 'type' => 'integer', 'description' => 'The ID of the user to add.' ),
					'role'    => array(
						'type'        => 'string',
						'description' => 'The role to assign.',
						'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$user_id = (int) $input['user_id'];
				$role    = sanitize_text_field( $input['role'] );

				$allowed_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
				if ( ! in_array( $role, $allowed_roles, true ) ) {
					return array( 'success' => false, 'message' => "Role '{$role}' is not allowed. Must be one of: " . implode( ', ', $allowed_roles ) . '.' );
				}

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}
				$user = get_user_by( 'id', $user_id );
				if ( ! $user ) {
					return array( 'success' => false, 'message' => "User ID {$user_id} not found." );
				}

				$result = add_user_to_blog( $site_id, $user_id, $role );

				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}

				return array(
					'success' => true,
					'message' => sprintf( 'User "%s" added to site "%s" with role "%s".', $user->user_login, get_blog_option( $site_id, 'blogname' ), $role ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 9. Create a new WordPress network user.
 */
function vip_mcp_register_ability_create_network_user(): void {
	wp_register_ability(
		'vip-multisite/create-network-user',
		array(
			'label'       => 'Create Network User',
			'description' => 'Creates a new user account on the WordPress multisite network.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'username', 'email' ),
				'properties' => array(
					'username'          => array( 'type' => 'string', 'description' => 'Login username.' ),
					'email'             => array( 'type' => 'string', 'description' => 'Email address.' ),
					'first_name'        => array( 'type' => 'string', 'description' => 'Optional first name.' ),
					'last_name'         => array( 'type' => 'string', 'description' => 'Optional last name.' ),
					'send_notification' => array( 'type' => 'boolean', 'description' => 'Send welcome email (default true).', 'default' => true ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'user_id' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$username = sanitize_user( $input['username'], true );
				$email    = sanitize_email( $input['email'] );

				if ( empty( $username ) ) {
					return array( 'success' => false, 'user_id' => 0, 'message' => 'Invalid username after sanitization.' );
				}
				if ( empty( $email ) ) {
					return array( 'success' => false, 'user_id' => 0, 'message' => 'Invalid email address.' );
				}

				if ( username_exists( $username ) ) {
					return array( 'success' => false, 'user_id' => 0, 'message' => "Username '{$username}' is already taken." );
				}
				if ( email_exists( $email ) ) {
					return array( 'success' => false, 'user_id' => 0, 'message' => "Email '{$email}' is already registered." );
				}

				$password = wp_generate_password( 24 );
				$user_id  = wpmu_create_user( $username, $password, $email );

				if ( ! $user_id ) {
					return array( 'success' => false, 'user_id' => 0, 'message' => "Failed to create user '{$username}'." );
				}

				$update = array( 'ID' => $user_id );
				if ( ! empty( $input['first_name'] ) ) {
					$update['first_name'] = sanitize_text_field( $input['first_name'] );
				}
				if ( ! empty( $input['last_name'] ) ) {
					$update['last_name'] = sanitize_text_field( $input['last_name'] );
				}
				if ( count( $update ) > 1 ) {
					wp_update_user( $update );
				}

				if ( $input['send_notification'] ?? true ) {
					wp_new_user_notification( $user_id, null, 'user' );
				}

				return array(
					'success' => true,
					'user_id' => (int) $user_id,
					'message' => "User '{$username}' created successfully (ID: {$user_id}).",
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 10. List network-activated plugins.
 */
function vip_mcp_register_ability_list_network_plugins(): void {
	wp_register_ability(
		'vip-multisite/list-network-plugins',
		array(
			'label'       => 'List Network Plugins',
			'description' => 'Returns all plugins that are network-activated across the multisite.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array( 'type' => 'array' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$network_active = get_site_option( 'active_sitewide_plugins', array() );
				$all_plugins    = get_plugins();
				$result         = array();

				foreach ( $network_active as $plugin_file => $timestamp ) {
					$data     = $all_plugins[ $plugin_file ] ?? array();
					$result[] = array(
						'file'        => $plugin_file,
						'name'        => $data['Name'] ?? $plugin_file,
						'version'     => $data['Version'] ?? '',
						'author'      => $data['Author'] ?? '',
						'description' => wp_strip_all_tags( $data['Description'] ?? '' ),
					);
				}

				return array( 'plugins' => $result );
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

// =========================================================================
// CONTENT MANAGEMENT ABILITIES (11–14)
// =========================================================================

/**
 * 11. Create a post or page on a specific sub-site.
 */
function vip_mcp_register_ability_create_post(): void {
	wp_register_ability(
		'vip-multisite/create-post',
		array(
			'label'       => 'Create Post or Page on Site',
			'description' => 'Creates a post or page on a specific network sub-site. Supports setting title, content (raw HTML/blocks), status, and post type.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'title' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the sub-site to create the content on.',
					),
					'title' => array(
						'type'        => 'string',
						'description' => 'The title of the post or page.',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'The body content. Accepts raw HTML or Gutenberg block markup. Leave empty for a blank post.',
						'default'     => '',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'The post type to create (default: "page").',
						'enum'        => array( 'post', 'page' ),
						'default'     => 'page',
					),
					'status' => array(
						'type'        => 'string',
						'description' => 'The publishing status (default: "draft").',
						'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
						'default'     => 'draft',
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => 'Optional short excerpt / summary for the post.',
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'Optional URL slug. Auto-generated from title if omitted.',
					),
					'template' => array(
						'type'        => 'string',
						'description' => 'Optional page template filename (e.g. "templates/full-width.html"). Only applicable to pages.',
					),
					'author_id' => array(
						'type'        => 'integer',
						'description' => 'Optional user ID to set as the post author. Defaults to the currently authenticated user.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'post_id'   => array( 'type' => 'integer' ),
					'url'       => array( 'type' => 'string', 'description' => 'The public permalink.' ),
					'edit_url'  => array( 'type' => 'string', 'description' => 'The wp-admin edit URL.' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id   = (int) $input['site_id'];
				$post_type = in_array( $input['post_type'] ?? 'page', array( 'post', 'page' ), true ) ? $input['post_type'] : 'page';
				$status    = in_array( $input['status'] ?? 'draft', array( 'draft', 'publish', 'pending', 'private' ), true ) ? $input['status'] : 'draft';

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => 0, 'url' => '', 'edit_url' => '', 'message' => "Site ID {$site_id} not found." );
				}

				$postarr = array(
					'post_title'   => wp_strip_all_tags( $input['title'] ),
					'post_content' => vip_mcp_sanitize_content( $input['content'] ?? '' ),
					'post_status'  => $status,
					'post_type'    => $post_type,
				);

				if ( ! empty( $input['excerpt'] ) ) {
					$postarr['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
				}
				if ( ! empty( $input['slug'] ) ) {
					$postarr['post_name'] = sanitize_title( $input['slug'] );
				}
				$post_id  = 0;
				$url      = '';
				$edit_url = '';

				switch_to_blog( $site_id );
				try {
					// Validate author_id membership inside blog context.
					if ( ! empty( $input['author_id'] ) ) {
						$author_id = (int) $input['author_id'];
						if ( ! is_user_member_of_blog( $author_id, $site_id ) ) {
							return array( 'success' => false, 'post_id' => 0, 'url' => '', 'edit_url' => '', 'message' => "User ID {$author_id} is not a member of site {$site_id}." );
						}
						$postarr['post_author'] = $author_id;
					}

					$post_id = wp_insert_post( $postarr, true );

					if ( is_wp_error( $post_id ) ) {
						return array( 'success' => false, 'post_id' => 0, 'url' => '', 'edit_url' => '', 'message' => $post_id->get_error_message() );
					}

					// Set page template if provided and post type is page.
					if ( 'page' === $post_type && ! empty( $input['template'] ) ) {
						update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
					}

					$url      = get_permalink( $post_id );
					$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );

				} finally {
					restore_current_blog();
				}

				return array(
					'success'  => true,
					'post_id'  => (int) $post_id,
					'url'      => $url ?: '',
					'edit_url' => $edit_url,
					'message'  => sprintf( '%s "%s" created on site %d (ID: %d, status: %s).', ucfirst( $post_type ), $input['title'], $site_id, $post_id, $status ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 12. Get a post or page from a specific sub-site.
 */
function vip_mcp_register_ability_get_post(): void {
	wp_register_ability(
		'vip-multisite/get-post',
		array(
			'label'       => 'Get Post or Page from Site',
			'description' => 'Retrieves a post or page from a specific network sub-site by ID, including its content, status, and edit URL.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'post_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'post_id' => array( 'type' => 'integer', 'description' => 'The ID of the post or page to retrieve.' ),
				),
			),
			'output_schema' => array(
				'type' => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'post_id'   => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
					'excerpt'   => array( 'type' => 'string' ),
					'status'    => array( 'type' => 'string' ),
					'post_type' => array( 'type' => 'string' ),
					'slug'      => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string' ),
					'edit_url'  => array( 'type' => 'string' ),
					'template'  => array( 'type' => 'string' ),
					'date'      => array( 'type' => 'string' ),
					'modified'  => array( 'type' => 'string' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$post_id = (int) $input['post_id'];

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}

				$post     = null;
				$url      = '';
				$edit_url = '';
				$template = '';
				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$post = get_post( $post_id );

					if ( ! $post ) {
						$response = array( 'success' => false, 'message' => "Post ID {$post_id} not found on site {$site_id}." );
					} else {
						$url      = get_permalink( $post_id );
						$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );
						$template = get_post_meta( $post_id, '_wp_page_template', true );

						// Build response inside try so $post properties are safe to access.
						$response = array(
							'success'   => true,
							'post_id'   => (int) $post->ID,
							'title'     => $post->post_title,
							'content'   => $post->post_content,
							'excerpt'   => $post->post_excerpt,
							'status'    => $post->post_status,
							'post_type' => $post->post_type,
							'slug'      => $post->post_name,
							'url'       => $url ?: '',
							'edit_url'  => $edit_url,
							'template'  => $template ?: '',
							'date'      => $post->post_date,
							'modified'  => $post->post_modified,
							'message'   => 'Post retrieved successfully.',
						);
					}

				} finally {
					restore_current_blog();
				}

				return $response;
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 13. Update an existing post or page on a specific sub-site.
 */
function vip_mcp_register_ability_update_post(): void {
	wp_register_ability(
		'vip-multisite/update-post',
		array(
			'label'       => 'Update Post or Page on Site',
			'description' => 'Updates the title, content, status, or other fields of an existing post or page on a specific network sub-site. Only include fields you want to change.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'post_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'post_id' => array( 'type' => 'integer', 'description' => 'The ID of the post or page to update.' ),
					'title'   => array( 'type' => 'string', 'description' => 'New title.' ),
					'content' => array( 'type' => 'string', 'description' => 'New body content. Accepts raw HTML or Gutenberg block markup.' ),
					'excerpt' => array( 'type' => 'string', 'description' => 'New excerpt / summary.' ),
					'status'  => array(
						'type'        => 'string',
						'description' => 'New publishing status.',
						'enum'        => array( 'draft', 'publish', 'pending', 'private', 'trash' ),
					),
					'slug' => array( 'type' => 'string', 'description' => 'New URL slug.' ),
					'template' => array( 'type' => 'string', 'description' => 'New page template filename. Pass an empty string to reset to the default template.' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'edit_url' => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$post_id = (int) $input['post_id'];

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => $post_id, 'url' => '', 'edit_url' => '', 'message' => "Site ID {$site_id} not found." );
				}

				$url      = '';
				$edit_url = '';

				switch_to_blog( $site_id );
				try {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array( 'success' => false, 'post_id' => $post_id, 'url' => '', 'edit_url' => '', 'message' => "Post ID {$post_id} not found on site {$site_id}." );
					}

					$postarr = array( 'ID' => $post_id );
					$updated = array();

					if ( isset( $input['title'] ) ) {
						$postarr['post_title'] = wp_strip_all_tags( $input['title'] );
						$updated[] = 'title';
					}
					if ( isset( $input['content'] ) ) {
						$postarr['post_content'] = vip_mcp_sanitize_content( $input['content'] );
						$updated[] = 'content';
					}
					if ( isset( $input['excerpt'] ) ) {
						$postarr['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
						$updated[] = 'excerpt';
					}
					if ( isset( $input['status'] ) ) {
						$allowed_statuses = array( 'draft', 'publish', 'pending', 'private', 'trash' );
						if ( ! in_array( $input['status'], $allowed_statuses, true ) ) {
							return array( 'success' => false, 'post_id' => $post_id, 'url' => '', 'edit_url' => '', 'message' => "Invalid status '{$input['status']}'. Must be one of: " . implode( ', ', $allowed_statuses ) . '.' );
						}
						$postarr['post_status'] = $input['status'];
						$updated[] = 'status';
					}
					if ( isset( $input['slug'] ) ) {
						$postarr['post_name'] = sanitize_title( $input['slug'] );
						$updated[] = 'slug';
					}

					if ( empty( $updated ) && ! isset( $input['template'] ) ) {
						return array( 'success' => false, 'post_id' => $post_id, 'url' => '', 'edit_url' => '', 'message' => 'No fields provided to update.' );
					}

					// Only call wp_update_post() if there are actual post fields to write.
					// Calling it with only ID would touch post_modified unnecessarily.
					if ( count( $postarr ) > 1 ) {
						$result = wp_update_post( $postarr, true );

						if ( is_wp_error( $result ) ) {
							return array( 'success' => false, 'post_id' => $post_id, 'url' => '', 'edit_url' => '', 'message' => $result->get_error_message() );
						}
					}

					// Handle template separately as it is stored in post meta.
					// Only applicable to pages — matches the guard in create-post.
					if ( isset( $input['template'] ) ) {
						if ( 'page' === $post->post_type ) {
							update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
							$updated[] = 'template';
						}
						// Silently skip for non-page post types — template is not meaningful.
					}

					$url      = get_permalink( $post_id );
					$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );

				} finally {
					restore_current_blog();
				}

				return array(
					'success'  => true,
					'post_id'  => (int) $post_id,
					'url'      => $url ?: '',
					'edit_url' => $edit_url,
					'message'  => sprintf( 'Post %d on site %d updated. Fields changed: %s.', $post_id, $site_id, implode( ', ', $updated ) ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 14. List posts or pages on a specific sub-site.
 */
function vip_mcp_register_ability_list_posts(): void {
	wp_register_ability(
		'vip-multisite/list-posts',
		array(
			'label'       => 'List Posts or Pages on Site',
			'description' => 'Returns a paginated list of posts or pages on a specific network sub-site, with optional filtering by status or search term.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site to query.' ),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type to list (default: "page").',
						'enum'        => array( 'post', 'page' ),
						'default'     => 'page',
					),
					'status' => array(
						'type'        => 'string',
						'description' => 'Filter by publishing status. Use "any" for all statuses (default: "any").',
						'enum'        => array( 'any', 'draft', 'publish', 'pending', 'private', 'trash' ),
						'default'     => 'any',
					),
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional keyword to search in post titles and content.',
					),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'posts'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id   = (int) $input['site_id'];
				$post_type = in_array( $input['post_type'] ?? 'page', array( 'post', 'page' ), true ) ? $input['post_type'] : 'page';
				$allowed_statuses = array( 'any', 'draft', 'publish', 'pending', 'private', 'trash' );
				$status = in_array( $input['status'] ?? 'any', $allowed_statuses, true )
					? ( $input['status'] ?? 'any' )
					: 'any';
				$per_page  = min( max( (int) ( $input['per_page'] ?? 20 ), 1 ), 100 );
				$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

				if ( ! get_site( $site_id ) ) {
					return array( 'posts' => array(), 'total' => 0, 'total_pages' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$query_args = array(
					'post_type'      => $post_type,
					'post_status'    => $status,
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'all',
				);

				if ( ! empty( $input['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $input['search'] );
				}

				$result = array();
				$total  = 0;

				switch_to_blog( $site_id );
				try {
					$query  = new WP_Query( $query_args );
					$posts  = $query->posts;
					$total  = (int) $query->found_posts;

					foreach ( $posts as $post ) {
						$result[] = array(
							'post_id'   => (int) $post->ID,
							'title'     => $post->post_title,
							'status'    => $post->post_status,
							'slug'      => $post->post_name,
							'date'      => $post->post_date,
							'modified'  => $post->post_modified,
							'url'       => get_permalink( $post->ID ),
							'edit_url'  => get_admin_url( $site_id, 'post.php?post=' . $post->ID . '&action=edit' ),
						);
					}
				} finally {
					restore_current_blog();
				}

				return array(
					'posts'       => $result,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}


// =========================================================================
// SITE OPTIONS ABILITIES (15–16)
// =========================================================================

/**
 * 15. Get one or more options from a specific sub-site.
 */
function vip_mcp_register_ability_get_site_option(): void {
	wp_register_ability(
		'vip-multisite/get-site-option',
		array(
			'label'       => 'Get Site Option',
			'description' => 'Reads one or more WordPress options (get_option) from a specific network sub-site. Useful for inspecting settings such as the front page mode, assigned pages, site title, and more.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'option_names' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the sub-site to read options from.',
					),
					'option_names' => array(
						'type'        => 'array',
						'description' => 'One or more option keys to retrieve (e.g. ["show_on_front", "page_on_front", "page_for_posts"]).',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'maxItems'    => 20,
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'site_id' => array( 'type' => 'integer' ),
					'options' => array(
						'type'        => 'object',
						'description' => 'Key/value map of the requested option names and their current values.',
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id      = (int) $input['site_id'];
				$option_names = $input['option_names'] ?? array();

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'site_id' => $site_id, 'options' => array(), 'message' => "Site ID {$site_id} not found." );
				}

				if ( empty( $option_names ) || ! is_array( $option_names ) ) {
					return array( 'success' => false, 'site_id' => $site_id, 'options' => array(), 'message' => 'option_names must be a non-empty array.' );
				}

				// Sanitize each key and cap at 20.
				$option_names = array_slice(
					array_map( 'sanitize_key', $option_names ),
					0,
					20
				);

				// Blocklist keys that should never be exposed via MCP — credentials and auth secrets.
				// @todo: wrap with apply_filters( 'vip_mcp_blocked_read_options', $read_blocked )
				// if extensibility is needed. Hardcoded is the safer default for now.
				// Note: auth_key etc. are usually PHP constants, not DB options, but block them defensively.
				$read_blocked = array(
					'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
					'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
					'mailserver_pass', 'mailserver_login', 'mailserver_url', 'mailserver_port',
					'db_password', 'db_user', // defensive — not typically stored as options
				);

				$result = array();
				switch_to_blog( $site_id );
				try {
					foreach ( $option_names as $key ) {
						if ( in_array( $key, $read_blocked, true ) ) {
							$result[ $key ] = '[redacted]';
							continue;
						}
						$value = get_option( $key );
						// Cast scalars; leave arrays/objects as-is so page IDs etc. are readable.
						$result[ $key ] = is_bool( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : $value );
					}
				} finally {
					restore_current_blog();
				}

				return array(
					'success' => true,
					'site_id' => $site_id,
					'options' => $result,
					'message' => sprintf( 'Retrieved %d option(s) from site %d.', count( $result ), $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 16. Update one or more options on a specific sub-site.
 *
 * Includes a built-in allowlist of safe, commonly needed options.
 * Sensitive options (auth keys, salts, db credentials, etc.) are explicitly blocked.
 * The homepage settings — show_on_front, page_on_front, page_for_posts — are
 * fully supported, including resolving pages by title when an ID is not known.
 */
function vip_mcp_register_ability_update_site_option(): void {
	wp_register_ability(
		'vip-multisite/update-site-option',
		array(
			'label'       => 'Update Site Option',
			'description' => 'Writes one or more WordPress options (update_option) on a specific network sub-site. Only allowlisted options may be written — this includes common reading, discussion, permalink, media, and homepage settings (show_on_front, page_on_front, page_for_posts). Any key not on the allowlist is skipped with a reason.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'options' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the sub-site to update options on.',
					),
					'options' => array(
						'type'        => 'object',
						'description' => 'Key/value map of options to set. Values are cast to the appropriate type per option. Common keys: show_on_front ("posts" or "page"), page_on_front (page ID or title), page_for_posts (page ID or title), blogname, blogdescription, posts_per_page, default_comment_status, permalink_structure.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'site_id'  => array( 'type' => 'integer' ),
					'updated'  => array( 'type' => 'array',  'description' => 'Option keys that were successfully updated.' ),
					'skipped'  => array( 'type' => 'array',  'description' => 'Option keys that were skipped (blocked or value unchanged).' ),
					'errors'   => array( 'type' => 'array',  'description' => 'Option keys that failed with a reason.' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$options = $input['options'] ?? array();

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'site_id' => $site_id, 'updated' => array(), 'skipped' => array(), 'errors' => array(), 'message' => "Site ID {$site_id} not found." );
				}

				if ( empty( $options ) || ! is_array( $options ) ) {
					return array( 'success' => false, 'site_id' => $site_id, 'updated' => array(), 'skipped' => array(), 'errors' => array(), 'message' => 'options must be a non-empty key/value object.' );
				}

				// Explicit allowlist — only these options may be written via MCP.
				// @todo: wrap with apply_filters( 'vip_mcp_allowed_write_options', $allowed_options )
				// if client teams need to safely expose additional options.
				// Any option not listed here is silently skipped with a reason.
				// To expose additional options, add them to this list after review.
				$allowed_options = array(
					// Identity.
					'blogname', 'blogdescription',
					// Homepage settings.
					'show_on_front', 'page_on_front', 'page_for_posts',
					// Reading.
					'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public',
					// Writing / formats.
					'default_category', 'default_post_format', 'default_pingback_flag',
					// Discussion.
					'default_comment_status', 'default_ping_status', 'require_name_email',
					'comment_registration', 'close_comments_for_old_posts',
					'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
					'page_comments', 'comments_per_page', 'default_comments_page',
					'comment_order', 'comments_notify', 'moderation_notify',
					'comment_moderation', 'comment_whitelist', 'comment_max_links',
					// Date / time.
					'date_format', 'time_format', 'start_of_week', 'timezone_string', 'gmt_offset',
					// Permalinks.
					'permalink_structure', 'category_base', 'tag_base',
					// Media.
					'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
					'medium_size_w', 'medium_size_h',
					'large_size_w', 'large_size_h',
					'uploads_use_yearmonth_folders',
				);

				// Options that accept a page ID — also support resolution by post title.
				$page_id_options = array( 'page_on_front', 'page_for_posts' );

				$updated = array();
				$skipped = array();
				$errors  = array();

				switch_to_blog( $site_id );
				try {
					foreach ( $options as $key => $value ) {
						$key = sanitize_key( $key );

						if ( empty( $key ) ) {
							$skipped[] = array( 'key' => $key, 'reason' => 'Invalid option key.' );
							continue;
						}

						if ( ! in_array( $key, $allowed_options, true ) ) {
							$skipped[] = array( 'key' => $key, 'reason' => 'Option not in allowlist. Contact a developer to add it after review.' );
							continue;
						}

						// For show_on_front, enforce the two valid WordPress values.
						if ( 'show_on_front' === $key ) {
							if ( ! in_array( $value, array( 'posts', 'page' ), true ) ) {
								$errors[] = array( 'key' => $key, 'reason' => "Invalid value '{$value}'. Must be 'posts' or 'page'." );
								continue;
							}
						}

						// For page ID options, resolve a title string to a post ID if needed.
						if ( in_array( $key, $page_id_options, true ) ) {
							if ( ! is_numeric( $value ) ) {
								// Resolve by exact post title — get_page_by_title() is deprecated since WP 6.2.
								$title_query = new WP_Query( array(
									'post_type'              => 'page',
									'title'                  => sanitize_text_field( $value ),
									'posts_per_page'         => 1,
									'post_status'            => array( 'publish', 'draft', 'private' ),
									'no_found_rows'          => true,
									'update_post_meta_cache' => false,
									'update_post_term_cache' => false,
								) );
								$page = $title_query->posts[0] ?? null;
								if ( ! $page ) {
									$errors[] = array( 'key' => $key, 'reason' => "Could not find a page with the title '{$value}' on site {$site_id}." );
									continue;
								}
								$value = $page->ID;
							} else {
								$value = (int) $value;
								// Validate the page ID exists and is actually a page.
								$page = get_post( $value );
								if ( ! $page || 'page' !== $page->post_type ) {
									$errors[] = array( 'key' => $key, 'reason' => "Post ID {$value} does not exist or is not a page on site {$site_id}." );
									continue;
								}
							}
						}

						$result = update_option( $key, $value );

						if ( false === $result ) {
							// update_option returns false both on DB error AND when value is unchanged.
							if ( get_option( $key ) == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
								$skipped[] = array( 'key' => $key, 'reason' => 'Value unchanged.' );
							} else {
								$errors[] = array( 'key' => $key, 'reason' => 'update_option returned false — possible database error.' );
							}
						} else {
							$updated[] = $key;
						}
					}
				} finally {
					restore_current_blog();
				}

				$success = empty( $errors );
				$parts   = array();
				if ( ! empty( $updated ) ) {
					$parts[] = count( $updated ) . ' updated';
				}
				if ( ! empty( $skipped ) ) {
					$parts[] = count( $skipped ) . ' skipped';
				}
				if ( ! empty( $errors ) ) {
					$parts[] = count( $errors ) . ' failed';
				}

				return array(
					'success' => $success,
					'site_id' => $site_id,
					'updated' => $updated,
					'skipped' => $skipped,
					'errors'  => $errors,
					'message' => sprintf( 'Site %d options: %s.', $site_id, implode( ', ', $parts ) ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}
