<?php
/**
 * Site Editor MCP Abilities — Templates, Template Parts & Synced Patterns.
 *
 * Registers MCP abilities that expose the Site Editor's wp_template,
 * wp_template_part, and wp_block (synced patterns) post types for reading
 * and writing via MCP-connected AI agents.
 *
 * Also provides defence-in-depth REST API infrastructure (Sections 1–4)
 * to ensure these post types are accessible and secured when accessed by
 * non-MCP REST clients (e.g. the Gutenberg editor, external integrations).
 * MCP abilities (Section 5) call WordPress APIs directly and are protected
 * by their own `manage_network_options` permission callbacks.
 *
 * Drop this file alongside wpvip-multisite-mcp-abilities.php in your
 * mu-plugins directory (or require it from that file).
 *
 * @package wpvip-multisite-mcp-abilities
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// 1. REST API SUPPORT — ensure post types are exposed via REST.
//
// NOTE: MCP abilities (Section 5) do NOT use the REST API — they call
// get_block_templates(), wp_insert_post(), etc. directly. This section
// exists as defence-in-depth for non-MCP REST clients (Gutenberg editor,
// third-party integrations) that access /wp/v2/templates etc.
// =========================================================================

/**
 * Force wp_template, wp_template_part, and wp_block to have REST API
 * support with sensible rest_base values.
 *
 * Fires on `rest_api_init` to ensure all `init`-priority registrations
 * (including theme-registered post types) have already completed.
 */
add_action( 'rest_api_init', 'vip_mcp_ensure_site_editor_rest_support' );

function vip_mcp_ensure_site_editor_rest_support(): void {
	$post_types = array(
		'wp_template'      => 'templates',
		'wp_template_part' => 'template-parts',
		'wp_block'         => 'blocks',
	);

	foreach ( $post_types as $post_type => $rest_base ) {
		$pto = get_post_type_object( $post_type );
		if ( ! $pto ) {
			continue;
		}

		if ( ! $pto->show_in_rest ) {
			$pto->show_in_rest = true;
		}

		if ( empty( $pto->rest_base ) ) {
			$pto->rest_base = $rest_base;
		}

		if ( empty( $pto->rest_controller_class ) ) {
			if ( in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true )
				&& class_exists( 'WP_REST_Templates_Controller' ) ) {
				$pto->rest_controller_class = 'WP_REST_Templates_Controller';
			} else {
				$pto->rest_controller_class = 'WP_REST_Posts_Controller';
			}
		}
	}
}


// =========================================================================
// 2. PERMISSIONS — grant authenticated users access via REST.
//
// Defence-in-depth for REST API consumers. MCP abilities use their own
// `manage_network_options` permission callback and do not rely on this.
// =========================================================================

/**
 * Allow users with 'edit_theme_options' to perform CRUD on Site Editor
 * post types via the REST API.
 *
 * Uses a static recursion guard to prevent infinite loops: `user_can()`
 * calls `has_cap()` which fires `map_meta_cap` — without the guard,
 * checking `edit_theme_options` inside this filter would recurse.
 *
 * We intentionally do NOT grant 'delete' — templates should be managed
 * through the Site Editor UI to avoid breaking the theme.
 */
add_filter( 'map_meta_cap', 'vip_mcp_map_site_editor_caps', 10, 4 );

function vip_mcp_map_site_editor_caps( array $caps, string $cap, int $user_id, array $args ): array {
	static $checking = false;

	$template_caps = array(
		// wp_template & wp_template_part capabilities.
		'edit_template',
		'read_template',
		'edit_templates',
		'edit_others_templates',
		'create_templates',
		'read_private_templates',
		// wp_block capabilities.
		'edit_block',
		'read_block',
		'edit_blocks',
		'edit_others_blocks',
		'create_blocks',
		'read_private_blocks',
		'publish_blocks',
	);

	if ( ! in_array( $cap, $template_caps, true ) ) {
		return $caps;
	}

	// Recursion guard — user_can() -> has_cap() -> map_meta_cap -> this filter.
	if ( $checking ) {
		return $caps;
	}

	$checking = true;
	$has_cap  = user_can( $user_id, 'edit_theme_options' );
	$checking = false;

	if ( $has_cap ) {
		return array( 'edit_theme_options' );
	}

	return $caps;
}


// =========================================================================
// 3. SECURITY — block unauthenticated access to REST endpoints.
//
// Defence-in-depth for direct REST API requests. MCP abilities do not
// route through REST and are protected by their permission callbacks.
// =========================================================================

/**
 * Ensure Site Editor REST endpoints require authentication.
 *
 * Fires before any REST endpoint callback and rejects requests that lack
 * a valid Application Password, cookie + nonce, or other WordPress
 * authentication mechanism.
 */
add_filter( 'rest_pre_dispatch', 'vip_mcp_require_auth_for_site_editor_rest', 10, 3 );

function vip_mcp_require_auth_for_site_editor_rest( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	$route = $request->get_route();
	$gated_prefixes = array(
		'/wp/v2/templates',
		'/wp/v2/template-parts',
		'/wp/v2/blocks',
	);

	$is_gated = false;
	foreach ( $gated_prefixes as $prefix ) {
		if ( str_starts_with( $route, $prefix ) ) {
			$is_gated = true;
			break;
		}
	}

	if ( ! $is_gated ) {
		return $result;
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			'Authentication is required to access Site Editor endpoints.',
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage Site Editor content.',
			array( 'status' => 403 )
		);
	}

	return $result;
}


// =========================================================================
// 4. META REGISTRATION — expose pattern metadata via REST.
// =========================================================================

/**
 * Register custom meta fields for wp_block (synced patterns) so they
 * are readable and writable through the REST API.
 */
add_action( 'init', 'vip_mcp_register_pattern_meta', 99 );

function vip_mcp_register_pattern_meta(): void {
	// wp_pattern_sync_status: '' (fully synced) or 'unsynced'.
	register_post_meta( 'wp_block', 'wp_pattern_sync_status', array(
		'type'              => 'string',
		'description'       => 'Sync status of the pattern. Empty string means fully synced; "unsynced" means it is a standard (non-synced) pattern.',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => static function ( $value ) {
			return in_array( $value, array( '', 'unsynced' ), true ) ? $value : '';
		},
		'auth_callback'     => static function () {
			return current_user_can( 'edit_theme_options' );
		},
	) );
}

/**
 * Ensure the wp_pattern_category taxonomy is exposed in REST.
 */
add_action( 'rest_api_init', 'vip_mcp_ensure_pattern_category_rest' );

function vip_mcp_ensure_pattern_category_rest(): void {
	$taxonomy = get_taxonomy( 'wp_pattern_category' );
	if ( $taxonomy && ! $taxonomy->show_in_rest ) {
		$taxonomy->show_in_rest = true;
		if ( empty( $taxonomy->rest_base ) ) {
			$taxonomy->rest_base = 'pattern-categories';
		}
	}
}


// =========================================================================
// 5. MCP ABILITIES — CRUD operations for templates, parts & patterns.
//
// These call WordPress APIs directly (get_block_templates, wp_insert_post,
// etc.) and do NOT route through the REST API. Each ability has its own
// permission callback requiring `manage_network_options`.
// =========================================================================

/**
 * Sanitize post content for MCP write operations.
 *
 * MCP abilities require `manage_network_options` (super admin). Super admins
 * have `unfiltered_html`, so wp_kses_post() should not strip their content —
 * block markup frequently uses data-* attributes, inline styles, and custom
 * elements that wp_kses_post() would mangle. This mirrors how Gutenberg's
 * own REST controllers handle template content for privileged users.
 *
 * @param string $content Raw content from the MCP input.
 * @return string Sanitized content.
 */
function vip_mcp_sanitize_content( string $content ): string {
	if ( current_user_can( 'unfiltered_html' ) ) {
		return $content;
	}
	return wp_kses_post( $content );
}

/**
 * Resolve pattern category slugs to term IDs, creating missing categories.
 *
 * Must be called within a switch_to_blog() context for the target site.
 *
 * @param string[] $slugs Category slugs (max 20 processed).
 * @return int[] Term IDs.
 */
function vip_mcp_resolve_pattern_categories( array $slugs ): array {
	$slugs    = array_slice( $slugs, 0, 20 );
	$term_ids = array();
	foreach ( $slugs as $slug ) {
		$slug = sanitize_title( $slug );
		if ( empty( $slug ) ) {
			continue;
		}
		$term = get_term_by( 'slug', $slug, 'wp_pattern_category' );
		if ( $term ) {
			$term_ids[] = $term->term_id;
		} else {
			// Create category with a human-readable display name.
			$name     = ucwords( str_replace( '-', ' ', $slug ) );
			$new_term = wp_insert_term( $name, 'wp_pattern_category', array( 'slug' => $slug ) );
			if ( ! is_wp_error( $new_term ) ) {
				$term_ids[] = $new_term['term_id'];
			}
		}
	}
	return $term_ids;
}

add_action( 'wp_abilities_api_init', 'vip_mcp_register_site_editor_abilities' );

function vip_mcp_register_site_editor_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	if ( ! is_multisite() ) {
		return;
	}

	vip_mcp_register_ability_list_templates();
	vip_mcp_register_ability_get_template();
	vip_mcp_register_ability_update_template();
	vip_mcp_register_ability_list_template_parts();
	vip_mcp_register_ability_get_template_part();
	vip_mcp_register_ability_update_template_part();
	vip_mcp_register_ability_list_patterns();
	vip_mcp_register_ability_get_pattern();
	vip_mcp_register_ability_create_pattern();
	vip_mcp_register_ability_update_pattern();
}


// -------------------------------------------------------------------------
// TEMPLATES (wp_template)
// -------------------------------------------------------------------------

/**
 * List all templates on a sub-site.
 *
 * Returns both theme-defined and user-customised templates. Theme-defined
 * templates that have not been customised exist only on disk and have no
 * post ID; customised copies are stored as wp_template posts.
 */
function vip_mcp_register_ability_list_templates(): void {
	wp_register_ability(
		'vip-multisite/list-templates',
		array(
			'label'       => 'List Site Editor Templates',
			'description' => 'Returns all templates (wp_template) for a specific network sub-site. Includes both theme-supplied and user-customised templates.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the sub-site.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'templates' => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'templates' => array(), 'total' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$result = array();

				switch_to_blog( $site_id );
				try {
					$templates = get_block_templates( array(), 'wp_template' );

					foreach ( $templates as $template ) {
						$result[] = array(
							'id'          => $template->id,           // e.g. theme-slug//index.
							'slug'        => $template->slug,
							'title'       => is_string( $template->title ) ? $template->title : ( $template->title['rendered'] ?? $template->slug ),
							'description' => $template->description ?? '',
							'theme'       => $template->theme,
							'source'      => $template->source,       // 'theme' or 'custom'.
							'type'        => $template->type,         // 'wp_template'.
							'has_post'    => ! empty( $template->wp_id ), // true if user-customised.
							'post_id'     => $template->wp_id ?: null,
							// Theme-sourced templates (from files) do not have a modified
							// date — only customised posts stored in the DB do.
							'modified'    => $template->modified ?? null,
						);
					}
				} finally {
					restore_current_blog();
				}

				return array(
					'success'   => true,
					'templates' => $result,
					'total'     => count( $result ),
					'message'   => sprintf( 'Retrieved %d template(s) from site %d.', count( $result ), $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}


/**
 * Get a single template by its ID (theme-slug//slug format).
 */
function vip_mcp_register_ability_get_template(): void {
	wp_register_ability(
		'vip-multisite/get-template',
		array(
			'label'       => 'Get Site Editor Template',
			'description' => 'Retrieves a specific template (wp_template) from a sub-site by its ID (e.g. "theme-slug//index"), including its full block markup content.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'template_id' ),
				'properties' => array(
					'site_id'     => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'template_id' => array( 'type' => 'string', 'description' => 'The template ID in theme-slug//slug format (e.g. "theme-slug//index").' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
					'theme'       => array( 'type' => 'string' ),
					'source'      => array( 'type' => 'string' ),
					'has_post'    => array( 'type' => 'boolean' ),
					'post_id'     => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id     = (int) $input['site_id'];
				$template_id = sanitize_text_field( $input['template_id'] );

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}

				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$template = get_block_template( $template_id, 'wp_template' );

					if ( ! $template ) {
						$response = array( 'success' => false, 'message' => "Template '{$template_id}' not found on site {$site_id}." );
					} else {
						$response = array(
							'success'     => true,
							'id'          => $template->id,
							'slug'        => $template->slug,
							'title'       => is_string( $template->title ) ? $template->title : ( $template->title['rendered'] ?? $template->slug ),
							'description' => $template->description ?? '',
							'content'     => $template->content,
							'theme'       => $template->theme,
							'source'      => $template->source,
							'has_post'    => ! empty( $template->wp_id ),
							'post_id'     => $template->wp_id ?: 0,
							'message'     => 'Template retrieved successfully.',
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
 * Update (or create the custom override for) a template.
 *
 * When a theme-only template is "updated", WordPress creates a wp_template
 * post that overrides the file-based template. Subsequent updates modify
 * that post. All fields are optional — only include what you want to change.
 */
function vip_mcp_register_ability_update_template(): void {
	wp_register_ability(
		'vip-multisite/update-template',
		array(
			'label'       => 'Update Site Editor Template',
			'description' => 'Updates a template on a sub-site. All fields except site_id and template_id are optional — only include fields you want to change. If the template has not been customised yet, this creates the custom override post. Accepts the template ID in theme-slug//slug format.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'template_id' ),
				'properties' => array(
					'site_id'     => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'template_id' => array( 'type' => 'string', 'description' => 'The template ID in theme-slug//slug format.' ),
					'content'     => array( 'type' => 'string', 'description' => 'New block markup content for the template.' ),
					'title'       => array( 'type' => 'string', 'description' => 'Optional new title for the template.' ),
					'description' => array( 'type' => 'string', 'description' => 'Optional new description.' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id     = (int) $input['site_id'];
				$template_id = sanitize_text_field( $input['template_id'] );

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$template = get_block_template( $template_id, 'wp_template' );

					if ( ! $template ) {
						$response = array( 'success' => false, 'post_id' => 0, 'message' => "Template '{$template_id}' not found on site {$site_id}." );
					} else {
						// Build changes array — only include fields that were provided.
						$changes = array();
						$updated = array();

						if ( isset( $input['content'] ) ) {
							$changes['post_content'] = vip_mcp_sanitize_content( $input['content'] );
							$updated[] = 'content';
						}
						if ( isset( $input['title'] ) ) {
							$changes['post_title'] = wp_strip_all_tags( $input['title'] );
							$updated[] = 'title';
						}
						if ( isset( $input['description'] ) ) {
							$changes['post_excerpt'] = sanitize_textarea_field( $input['description'] );
							$updated[] = 'description';
						}

						if ( empty( $changes ) ) {
							$response = array( 'success' => false, 'post_id' => 0, 'message' => 'No fields provided to update.' );
						} else {
							if ( ! empty( $template->wp_id ) ) {
								// Update existing custom override post.
								$changes['ID'] = $template->wp_id;
								$new_post_id = wp_update_post( $changes, true );
							} else {
								// Create a new wp_template post to override the theme file.
								$changes['post_type']   = 'wp_template';
								$changes['post_status'] = 'publish';
								$changes['post_name']   = $template->slug;

								// Ensure title is set when creating a new override.
								if ( ! isset( $changes['post_title'] ) ) {
									$changes['post_title'] = is_string( $template->title )
										? $template->title
										: ( $template->title['rendered'] ?? $template->slug );
								}

								// Ensure content is set when creating a new override.
								if ( ! isset( $changes['post_content'] ) ) {
									$changes['post_content'] = $template->content;
								}

								// Preserve description when creating a new override.
								if ( ! isset( $changes['post_excerpt'] ) && ! empty( $template->description ) ) {
									$changes['post_excerpt'] = $template->description;
								}

								$new_post_id = wp_insert_post( $changes, true );

								// wp_insert_post does not always handle tax_input for
								// custom taxonomies, so set the theme term explicitly.
								if ( ! is_wp_error( $new_post_id ) ) {
									wp_set_object_terms( $new_post_id, $template->theme, 'wp_theme' );
								}
							}

							if ( is_wp_error( $new_post_id ) ) {
								$response = array( 'success' => false, 'post_id' => 0, 'message' => $new_post_id->get_error_message() );
							} else {
								$response = array(
									'success' => true,
									'post_id' => (int) $new_post_id,
									'message' => sprintf( 'Template "%s" updated on site %d (post ID: %d). Fields changed: %s.', $template_id, $site_id, $new_post_id, implode( ', ', $updated ) ),
								);
							}
						}
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


// -------------------------------------------------------------------------
// TEMPLATE PARTS (wp_template_part)
// -------------------------------------------------------------------------

/**
 * List all template parts on a sub-site.
 */
function vip_mcp_register_ability_list_template_parts(): void {
	wp_register_ability(
		'vip-multisite/list-template-parts',
		array(
			'label'       => 'List Site Editor Template Parts',
			'description' => 'Returns all template parts (wp_template_part) for a specific sub-site, such as headers, footers, and sidebars.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'area'    => array(
						'type'        => 'string',
						'description' => 'Optional: filter by template part area. Standard values: "header", "footer", "sidebar", "uncategorized".',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array( 'type' => 'boolean' ),
					'template_parts' => array( 'type' => 'array' ),
					'total'          => array( 'type' => 'integer' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'template_parts' => array(), 'total' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$query_args = array();
				if ( ! empty( $input['area'] ) ) {
					$query_args['area'] = sanitize_text_field( $input['area'] );
				}

				$result = array();

				switch_to_blog( $site_id );
				try {
					$parts = get_block_templates( $query_args, 'wp_template_part' );

					foreach ( $parts as $part ) {
						$result[] = array(
							'id'          => $part->id,
							'slug'        => $part->slug,
							'title'       => is_string( $part->title ) ? $part->title : ( $part->title['rendered'] ?? $part->slug ),
							'description' => $part->description ?? '',
							'area'        => $part->area,
							'theme'       => $part->theme,
							'source'      => $part->source,
							'has_post'    => ! empty( $part->wp_id ),
							'post_id'     => $part->wp_id ?: null,
							// Theme-sourced template parts (from files) do not have a
							// modified date — only customised posts stored in the DB do.
							'modified'    => $part->modified ?? null,
						);
					}
				} finally {
					restore_current_blog();
				}

				return array(
					'success'        => true,
					'template_parts' => $result,
					'total'          => count( $result ),
					'message'        => sprintf( 'Retrieved %d template part(s) from site %d.', count( $result ), $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}


/**
 * Get a single template part.
 */
function vip_mcp_register_ability_get_template_part(): void {
	wp_register_ability(
		'vip-multisite/get-template-part',
		array(
			'label'       => 'Get Site Editor Template Part',
			'description' => 'Retrieves a specific template part from a sub-site by its ID (e.g. "theme-slug//header"), including full block markup content.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'template_part_id' ),
				'properties' => array(
					'site_id'          => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'template_part_id' => array( 'type' => 'string', 'description' => 'The template part ID in theme-slug//slug format (e.g. "theme-slug//header").' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'id'          => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
					'area'        => array( 'type' => 'string' ),
					'theme'       => array( 'type' => 'string' ),
					'source'      => array( 'type' => 'string' ),
					'has_post'    => array( 'type' => 'boolean' ),
					'post_id'     => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$part_id = sanitize_text_field( $input['template_part_id'] );

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'message' => "Site ID {$site_id} not found." );
				}

				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$part = get_block_template( $part_id, 'wp_template_part' );

					if ( ! $part ) {
						$response = array( 'success' => false, 'message' => "Template part '{$part_id}' not found on site {$site_id}." );
					} else {
						$response = array(
							'success'     => true,
							'id'          => $part->id,
							'slug'        => $part->slug,
							'title'       => is_string( $part->title ) ? $part->title : ( $part->title['rendered'] ?? $part->slug ),
							'description' => $part->description ?? '',
							'content'     => $part->content,
							'area'        => $part->area,
							'theme'       => $part->theme,
							'source'      => $part->source,
							'has_post'    => ! empty( $part->wp_id ),
							'post_id'     => $part->wp_id ?: 0,
							'message'     => 'Template part retrieved successfully.',
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
 * Update (or create the custom override for) a template part.
 * All fields are optional — only include what you want to change.
 */
function vip_mcp_register_ability_update_template_part(): void {
	wp_register_ability(
		'vip-multisite/update-template-part',
		array(
			'label'       => 'Update Site Editor Template Part',
			'description' => 'Updates a template part on a sub-site. All fields except site_id and template_part_id are optional — only include fields you want to change. Creates a custom override post if the part has not yet been customised.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'template_part_id' ),
				'properties' => array(
					'site_id'          => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'template_part_id' => array( 'type' => 'string', 'description' => 'The template part ID in theme-slug//slug format.' ),
					'content'          => array( 'type' => 'string', 'description' => 'New block markup content.' ),
					'title'            => array( 'type' => 'string', 'description' => 'Optional new title.' ),
					'description'      => array( 'type' => 'string', 'description' => 'Optional new description for the template part.' ),
					'area'             => array(
						'type'        => 'string',
						'description' => 'Optional new area assignment. Must be one of: "header", "footer", "sidebar", "uncategorized".',
						'enum'        => array( 'header', 'footer', 'sidebar', 'uncategorized' ),
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$part_id = sanitize_text_field( $input['template_part_id'] );

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				// Valid template part areas.
				$allowed_areas = array( 'header', 'footer', 'sidebar', 'uncategorized' );

				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$part = get_block_template( $part_id, 'wp_template_part' );

					if ( ! $part ) {
						$response = array( 'success' => false, 'post_id' => 0, 'message' => "Template part '{$part_id}' not found on site {$site_id}." );
					} else {
						// Build changes — only include fields that were provided.
						$changes = array();
						$updated = array();

						if ( isset( $input['content'] ) ) {
							$changes['post_content'] = vip_mcp_sanitize_content( $input['content'] );
							$updated[] = 'content';
						}
						if ( isset( $input['title'] ) ) {
							$changes['post_title'] = wp_strip_all_tags( $input['title'] );
							$updated[] = 'title';
						}
						if ( isset( $input['description'] ) ) {
							$changes['post_excerpt'] = sanitize_textarea_field( $input['description'] );
							$updated[] = 'description';
						}

						// Validate area before including it.
						$area_to_set = null;
						$has_error   = false;
						if ( isset( $input['area'] ) ) {
							$area = sanitize_text_field( $input['area'] );
							if ( ! in_array( $area, $allowed_areas, true ) ) {
								$response  = array( 'success' => false, 'post_id' => 0, 'message' => "Invalid area '{$area}'. Must be one of: " . implode( ', ', $allowed_areas ) . '.' );
								$has_error = true;
							} else {
								$area_to_set = $area;
								$updated[] = 'area';
							}
						}

						// Only proceed if validation passed.
						if ( ! $has_error ) {
							if ( empty( $changes ) && null === $area_to_set ) {
								$response = array( 'success' => false, 'post_id' => 0, 'message' => 'No fields provided to update.' );
							} else {
								if ( ! empty( $part->wp_id ) ) {
									// Update existing custom override post.
									if ( ! empty( $changes ) ) {
										$changes['ID'] = $part->wp_id;
										$new_post_id = wp_update_post( $changes, true );
									} else {
										$new_post_id = $part->wp_id;
									}
								} else {
									// Create a new wp_template_part post to override the theme file.
									$changes['post_type']   = 'wp_template_part';
									$changes['post_status'] = 'publish';
									$changes['post_name']   = $part->slug;

									if ( ! isset( $changes['post_title'] ) ) {
										$changes['post_title'] = is_string( $part->title )
											? $part->title
											: ( $part->title['rendered'] ?? $part->slug );
									}

									if ( ! isset( $changes['post_content'] ) ) {
										$changes['post_content'] = $part->content;
									}

									// Preserve description when creating a new override.
									if ( ! isset( $changes['post_excerpt'] ) && ! empty( $part->description ) ) {
										$changes['post_excerpt'] = $part->description;
									}

									$new_post_id = wp_insert_post( $changes, true );

									if ( ! is_wp_error( $new_post_id ) ) {
										wp_set_object_terms( $new_post_id, $part->theme, 'wp_theme' );
										// Preserve the original area from the theme file
										// when creating a new override (if not being changed).
										if ( null === $area_to_set && ! empty( $part->area ) ) {
											update_post_meta( $new_post_id, 'wp_template_part_area', $part->area );
										}
									}
								}

								if ( is_wp_error( $new_post_id ) ) {
									$response = array( 'success' => false, 'post_id' => 0, 'message' => $new_post_id->get_error_message() );
								} else {
									// Set area via post meta if explicitly provided.
									if ( null !== $area_to_set ) {
										update_post_meta( (int) $new_post_id, 'wp_template_part_area', $area_to_set );
									}

									$response = array(
										'success' => true,
										'post_id' => (int) $new_post_id,
										'message' => sprintf( 'Template part "%s" updated on site %d (post ID: %d). Fields changed: %s.', $part_id, $site_id, $new_post_id, implode( ', ', $updated ) ),
									);
								}
							}
						}
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


// -------------------------------------------------------------------------
// SYNCED PATTERNS (wp_block)
// -------------------------------------------------------------------------

/**
 * List synced patterns on a sub-site.
 */
function vip_mcp_register_ability_list_patterns(): void {
	wp_register_ability(
		'vip-multisite/list-patterns',
		array(
			'label'       => 'List Synced Patterns',
			'description' => 'Returns all synced patterns (wp_block posts) on a specific sub-site, with optional filtering by category or sync status.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'category' => array(
						'type'        => 'string',
						'description' => 'Optional: filter by wp_pattern_category slug.',
					),
					'sync_status' => array(
						'type'        => 'string',
						'description' => 'Optional: filter by sync status. "synced" for fully synced, "unsynced" for standard patterns.',
						'enum'        => array( 'synced', 'unsynced' ),
					),
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional keyword to search pattern titles.',
					),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'patterns'    => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id  = (int) $input['site_id'];
				$per_page = min( max( (int) ( $input['per_page'] ?? 50 ), 1 ), 100 );
				$page     = max( (int) ( $input['page'] ?? 1 ), 1 );

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'patterns' => array(), 'total' => 0, 'total_pages' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$query_args = array(
					'post_type'      => 'wp_block',
					'post_status'    => 'publish',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'title',
					'order'          => 'ASC',
				);

				if ( ! empty( $input['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $input['search'] );
				}

				if ( ! empty( $input['category'] ) ) {
					$query_args['tax_query'] = array(
						'relation' => 'AND',
						array(
							'taxonomy' => 'wp_pattern_category',
							'field'    => 'slug',
							'terms'    => sanitize_text_field( $input['category'] ),
						),
					);
				}

				// Filter by sync status meta.
				if ( isset( $input['sync_status'] ) ) {
					if ( 'unsynced' === $input['sync_status'] ) {
						$query_args['meta_query'] = array(
							array(
								'key'   => 'wp_pattern_sync_status',
								'value' => 'unsynced',
							),
						);
					} else {
						// "synced" = meta is empty or not set.
						$query_args['meta_query'] = array(
							'relation' => 'OR',
							array(
								'key'     => 'wp_pattern_sync_status',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'   => 'wp_pattern_sync_status',
								'value' => '',
							),
						);
					}
				}

				$result = array();
				$total  = 0;

				switch_to_blog( $site_id );
				try {
					$query = new WP_Query( $query_args );
					$total = (int) $query->found_posts;

					foreach ( $query->posts as $post ) {
						$sync_status = get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
						$categories  = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'slugs' ) );

						$result[] = array(
							'post_id'     => (int) $post->ID,
							'title'       => $post->post_title,
							'slug'        => $post->post_name,
							'sync_status' => $sync_status ?: 'synced',
							'categories'  => is_wp_error( $categories ) ? array() : $categories,
							'date'        => $post->post_date,
							'modified'    => $post->post_modified,
						);
					}
				} finally {
					restore_current_blog();
				}

				return array(
					'success'     => true,
					'patterns'    => $result,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'message'     => sprintf( 'Retrieved %d pattern(s) from site %d.', count( $result ), $site_id ),
				);
			},
			'meta' => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}


/**
 * Get a single synced pattern.
 */
function vip_mcp_register_ability_get_pattern(): void {
	wp_register_ability(
		'vip-multisite/get-pattern',
		array(
			'label'       => 'Get Synced Pattern',
			'description' => 'Retrieves a synced pattern (wp_block) from a sub-site by its post ID, including full block markup content, categories, and sync status.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'post_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'post_id' => array( 'type' => 'integer', 'description' => 'The post ID of the synced pattern.' ),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'post_id'     => array( 'type' => 'integer' ),
					'title'       => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'sync_status' => array( 'type' => 'string' ),
					'categories'  => array( 'type' => 'array' ),
					'date'        => array( 'type' => 'string' ),
					'modified'    => array( 'type' => 'string' ),
					'message'     => array( 'type' => 'string' ),
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

				$response = array( 'success' => false, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$post = get_post( $post_id );

					if ( ! $post || 'wp_block' !== $post->post_type ) {
						$response = array( 'success' => false, 'message' => "Pattern (wp_block) with ID {$post_id} not found on site {$site_id}." );
					} else {
						$sync_status = get_post_meta( $post_id, 'wp_pattern_sync_status', true );
						$categories  = wp_get_object_terms( $post_id, 'wp_pattern_category', array( 'fields' => 'slugs' ) );

						$response = array(
							'success'     => true,
							'post_id'     => (int) $post->ID,
							'title'       => $post->post_title,
							'content'     => $post->post_content,
							'slug'        => $post->post_name,
							'sync_status' => $sync_status ?: 'synced',
							'categories'  => is_wp_error( $categories ) ? array() : $categories,
							'date'        => $post->post_date,
							'modified'    => $post->post_modified,
							'message'     => 'Pattern retrieved successfully.',
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
 * Create a new synced pattern.
 */
function vip_mcp_register_ability_create_pattern(): void {
	wp_register_ability(
		'vip-multisite/create-pattern',
		array(
			'label'       => 'Create Synced Pattern',
			'description' => 'Creates a new synced pattern (wp_block) on a sub-site. The pattern can be fully synced (edits propagate everywhere it is used) or unsynced (a standard reusable pattern).',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'title', 'content' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'title'   => array( 'type' => 'string', 'description' => 'The title of the pattern.' ),
					'content' => array( 'type' => 'string', 'description' => 'Block markup content for the pattern.' ),
					'sync_status' => array(
						'type'        => 'string',
						'description' => 'Sync behaviour. "synced" (default) means changes propagate to all instances; "unsynced" makes it a standard pattern.',
						'enum'        => array( 'synced', 'unsynced' ),
						'default'     => 'synced',
					),
					'categories' => array(
						'type'        => 'array',
						'description' => 'Optional array of wp_pattern_category slugs to assign (max 20). Categories are created if they do not exist.',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 20,
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => 0, 'message' => "Site ID {$site_id} not found." );
				}

				$response = array( 'success' => false, 'post_id' => 0, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$post_id = wp_insert_post( array(
						'post_type'    => 'wp_block',
						'post_title'   => wp_strip_all_tags( $input['title'] ),
						'post_content' => vip_mcp_sanitize_content( $input['content'] ),
						'post_status'  => 'publish',
					), true );

					if ( is_wp_error( $post_id ) ) {
						$response = array( 'success' => false, 'post_id' => 0, 'message' => $post_id->get_error_message() );
					} else {
						// Set sync status meta.
						$sync = ( $input['sync_status'] ?? 'synced' ) === 'unsynced' ? 'unsynced' : '';
						update_post_meta( $post_id, 'wp_pattern_sync_status', $sync );

						// Assign categories (capped at 20 to prevent abuse).
						if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
							$term_ids = vip_mcp_resolve_pattern_categories( $input['categories'] );
							if ( ! empty( $term_ids ) ) {
								wp_set_object_terms( $post_id, $term_ids, 'wp_pattern_category' );
							}
						}

						$response = array(
							'success' => true,
							'post_id' => (int) $post_id,
							'message' => sprintf( 'Pattern "%s" created on site %d (ID: %d).', $input['title'], $site_id, $post_id ),
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
 * Update an existing synced pattern.
 */
function vip_mcp_register_ability_update_pattern(): void {
	wp_register_ability(
		'vip-multisite/update-pattern',
		array(
			'label'       => 'Update Synced Pattern',
			'description' => 'Updates an existing synced pattern (wp_block) on a sub-site. You can change its title, content, sync status, or categories. Only include fields you want to change.',
			'category'    => 'vip-multisite',
			'input_schema' => array(
				'type'     => 'object',
				'required' => array( 'site_id', 'post_id' ),
				'properties' => array(
					'site_id' => array( 'type' => 'integer', 'description' => 'The ID of the sub-site.' ),
					'post_id' => array( 'type' => 'integer', 'description' => 'The post ID of the pattern to update.' ),
					'title'   => array( 'type' => 'string', 'description' => 'New title.' ),
					'content' => array( 'type' => 'string', 'description' => 'New block markup content.' ),
					'sync_status' => array(
						'type'        => 'string',
						'description' => 'New sync status: "synced" or "unsynced".',
						'enum'        => array( 'synced', 'unsynced' ),
					),
					'categories' => array(
						'type'        => 'array',
						'description' => 'Replace all categories with this list of wp_pattern_category slugs (max 20). Missing categories will be created.',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 20,
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback' => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$post_id = (int) $input['post_id'];

				if ( ! get_site( $site_id ) ) {
					return array( 'success' => false, 'post_id' => $post_id, 'message' => "Site ID {$site_id} not found." );
				}

				$response = array( 'success' => false, 'post_id' => $post_id, 'message' => 'An unexpected error occurred.' );

				switch_to_blog( $site_id );
				try {
					$post = get_post( $post_id );

					if ( ! $post || 'wp_block' !== $post->post_type ) {
						$response = array( 'success' => false, 'post_id' => $post_id, 'message' => "Pattern (wp_block) with ID {$post_id} not found on site {$site_id}." );
					} else {
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

						$update_failed = false;
						if ( count( $postarr ) > 1 ) {
							$result = wp_update_post( $postarr, true );
							if ( is_wp_error( $result ) ) {
								$response = array( 'success' => false, 'post_id' => $post_id, 'message' => $result->get_error_message() );
								$update_failed = true;
							}
						}

						if ( ! $update_failed ) {
							// Update sync status.
							if ( isset( $input['sync_status'] ) ) {
								$sync = 'unsynced' === $input['sync_status'] ? 'unsynced' : '';
								update_post_meta( $post_id, 'wp_pattern_sync_status', $sync );
								$updated[] = 'sync_status';
							}

							// Replace categories (capped at 20 to prevent abuse).
							if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
								$term_ids = vip_mcp_resolve_pattern_categories( $input['categories'] );
								wp_set_object_terms( $post_id, $term_ids, 'wp_pattern_category' );
								$updated[] = 'categories';
							}

							if ( empty( $updated ) ) {
								$response = array( 'success' => false, 'post_id' => $post_id, 'message' => 'No fields provided to update.' );
							} else {
								$response = array(
									'success' => true,
									'post_id' => (int) $post_id,
									'message' => sprintf( 'Pattern %d on site %d updated. Fields changed: %s.', $post_id, $site_id, implode( ', ', $updated ) ),
								);
							}
						}
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