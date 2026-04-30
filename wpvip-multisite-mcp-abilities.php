<?php
/**
 * Plugin Name: WPVIP MCP Abilities
 * Plugin URI:  https://wpvip.com
 * Description: Registers WordPress MCP abilities for managing content, Site Editor, options,
 *              and (on Multisite) network-level resources via MCP-connected AI agents.
 *              Works on both single-site and Multisite installations.
 * Version:     1.6.0
 * Author:      Ross Mulcahy
 * Requires PHP: 8.0
 * Requires WP:  6.9
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package wpvip-multisite-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// MULTISITE COMPATIBILITY HELPERS
//
// These thin wrappers allow site-level abilities to work on both single-site
// and Multisite installations. On single-site the blog-switching and site
// validation functions are no-ops; on Multisite they delegate to core.
// =========================================================================

/**
 * Resolve the target site ID from ability input.
 *
 * On Multisite the caller is expected to supply site_id. On single-site
 * the parameter is optional and defaults to the current (only) blog.
 *
 * @param array<string,mixed> $input Ability input array.
 */
function vip_mcp_resolve_site_id( array $input ): int {
	if ( isset( $input['site_id'] ) ) {
		return (int) $input['site_id'];
	}
	return get_current_blog_id();
}

/**
 * Validate that a site ID refers to an existing site.
 *
 * On single-site this always returns true — there is only one blog.
 *
 * @param int $site_id Site ID to validate.
 */
function vip_mcp_validate_site( int $site_id ): bool {
	if ( ! is_multisite() ) {
		return true;
	}
	return (bool) get_site( $site_id );
}

/**
 * Switch to the given blog context (Multisite only).
 *
 * @param int $site_id Site ID to switch to.
 */
function vip_mcp_switch_to_site( int $site_id ): void {
	if ( is_multisite() ) {
		switch_to_blog( $site_id );
	}
}

/**
 * Restore the previous blog context (Multisite only).
 */
function vip_mcp_restore_site(): void {
	if ( is_multisite() ) {
		restore_current_blog();
	}
}

/**
 * Build the `required` array for an ability's input_schema.
 *
 * On Multisite, `site_id` is prepended (it must be explicit).
 * On single-site it is omitted (defaults to the current blog).
 *
 * @param string[] $fields Required field names.
 * @return string[] Required fields with site_id prepended on Multisite.
 */
function vip_mcp_required_with_site_id( array $fields = array() ): array {
	if ( is_multisite() ) {
		array_unshift( $fields, 'site_id' );
	}
	return $fields;
}

/**
 * Sanitize an option key while preserving the core WPLANG option's casing.
 *
 * @param mixed $key Raw option key.
 */
function vip_mcp_sanitize_option_key( $key ): string {
	$key = (string) $key;
	if ( 'WPLANG' === $key ) {
		return 'WPLANG';
	}
	return sanitize_key( $key );
}

/**
 * Gather registered, non-private meta values for an object.
 *
 * @param string $object_type    Meta object type, e.g. post or term.
 * @param string $object_subtype Meta subtype, e.g. post type or taxonomy.
 * @param int    $object_id      Object ID.
 * @return array<string,mixed>
 */
function vip_mcp_get_registered_meta_values( string $object_type, string $object_subtype, int $object_id ): array {
	$meta_values    = array();
	$registered     = get_registered_meta_keys( $object_type, $object_subtype );
	$registered_all = get_registered_meta_keys( $object_type );
	$all_keys       = array_unique( array_merge( array_keys( $registered_all ), array_keys( $registered ) ) );

	foreach ( $all_keys as $meta_key ) {
		if ( str_starts_with( $meta_key, '_' ) ) {
			continue;
		}

		if ( 'post' === $object_type ) {
			$val = get_post_meta( $object_id, $meta_key, true );
		} elseif ( 'term' === $object_type ) {
			$val = get_term_meta( $object_id, $meta_key, true );
		} else {
			continue;
		}

		if ( '' !== $val && false !== $val ) {
			$meta_values[ $meta_key ] = $val;
		}
	}

	return $meta_values;
}

/**
 * Update only registered meta keys for a post or term.
 *
 * @param string              $object_type    Meta object type, e.g. post or term.
 * @param string              $object_subtype Meta subtype, e.g. post type or taxonomy.
 * @param int                 $object_id      Object ID.
 * @param array<string,mixed> $meta Raw key/value meta map.
 * @return array{updated:array<int,string>,skipped:array<int,array<string,mixed>>}
 */
function vip_mcp_update_registered_meta_values( string $object_type, string $object_subtype, int $object_id, array $meta ): array {
	$registered     = get_registered_meta_keys( $object_type, $object_subtype );
	$registered_all = get_registered_meta_keys( $object_type );
	$updated        = array();
	$skipped        = array();

	foreach ( $meta as $meta_key => $meta_value ) {
		$sanitized_key = sanitize_key( $meta_key );
		if ( '' === $sanitized_key ) {
			$skipped[] = array(
				'key'    => (string) $meta_key,
				'reason' => 'Invalid meta key.',
			);
			continue;
		}

		if ( ! isset( $registered[ $sanitized_key ] ) && ! isset( $registered_all[ $sanitized_key ] ) ) {
			$skipped[] = array(
				'key'    => $sanitized_key,
				'reason' => 'Meta key is not registered for this object type.',
			);
			continue;
		}

		if ( 'post' === $object_type ) {
			update_post_meta( $object_id, $sanitized_key, $meta_value );
		} elseif ( 'term' === $object_type ) {
			update_term_meta( $object_id, $sanitized_key, $meta_value );
		} else {
			$skipped[] = array(
				'key'    => $sanitized_key,
				'reason' => 'Unsupported meta object type.',
			);
			continue;
		}

		$updated[] = $sanitized_key;
	}

	return array(
		'updated' => $updated,
		'skipped' => $skipped,
	);
}

/**
 * Format a term response.
 *
 * @param WP_Term $term     Term object.
 * @param string  $taxonomy Taxonomy slug.
 * @param int     $site_id  Site ID.
 * @return array<string,mixed>
 */
function vip_mcp_format_term_response( WP_Term $term, string $taxonomy, int $site_id ): array {
	$link = get_term_link( $term, $taxonomy );

	return array(
		'term_id'     => (int) $term->term_id,
		'taxonomy'    => $taxonomy,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => $term->description,
		'parent'      => (int) $term->parent,
		'count'       => (int) $term->count,
		'url'         => is_wp_error( $link ) ? '' : $link,
		'edit_url'    => get_admin_url( $site_id, 'term.php?taxonomy=' . rawurlencode( $taxonomy ) . '&tag_ID=' . (int) $term->term_id ),
		'meta'        => vip_mcp_get_registered_meta_values( 'term', $taxonomy, (int) $term->term_id ),
	);
}

/**
 * Resolve term IDs from IDs, slugs, names, or simple term descriptor objects.
 *
 * @param string           $taxonomy       Taxonomy slug.
 * @param array<int,mixed> $terms Term inputs.
 * @param bool             $create_missing Whether missing name/slug terms may be created.
 * @return array{term_ids:array<int,int>,created:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
 */
function vip_mcp_resolve_term_ids( string $taxonomy, array $terms, bool $create_missing = false ): array {
	$term_ids = array();
	$created  = array();
	$errors   = array();

	foreach ( $terms as $raw_term ) {
		$term        = null;
		$name        = '';
		$slug        = '';
		$description = '';
		$parent      = 0;

		if ( is_array( $raw_term ) ) {
			if ( isset( $raw_term['id'] ) ) {
				$term = get_term( (int) $raw_term['id'], $taxonomy );
			} elseif ( isset( $raw_term['term_id'] ) ) {
				$term = get_term( (int) $raw_term['term_id'], $taxonomy );
			}

			if ( ! $term && ! empty( $raw_term['slug'] ) ) {
				$slug = sanitize_title( $raw_term['slug'] );
				$term = get_term_by( 'slug', $slug, $taxonomy );
			}

			if ( ! $term && ! empty( $raw_term['name'] ) ) {
				$name = sanitize_text_field( $raw_term['name'] );
				$term = get_term_by( 'name', $name, $taxonomy );
			}

			if ( ! empty( $raw_term['description'] ) ) {
				$description = sanitize_textarea_field( $raw_term['description'] );
			}
			if ( ! empty( $raw_term['parent'] ) ) {
				$parent = max( 0, (int) $raw_term['parent'] );
			}
		} elseif ( is_numeric( $raw_term ) ) {
			$term = get_term( (int) $raw_term, $taxonomy );
		} elseif ( is_string( $raw_term ) && '' !== trim( $raw_term ) ) {
			$slug = sanitize_title( $raw_term );
			$name = sanitize_text_field( $raw_term );
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'name', $name, $taxonomy );
			}
		}

		if ( is_wp_error( $term ) ) {
			$errors[] = array(
				'term'   => $raw_term,
				'reason' => $term->get_error_message(),
			);
			continue;
		}

		if ( ! $term && $create_missing ) {
			if ( '' === $name && '' !== $slug ) {
				$name = $slug;
			}

			if ( '' === $name ) {
				$errors[] = array(
					'term'   => $raw_term,
					'reason' => 'Missing terms can only be created from a non-empty name or slug.',
				);
				continue;
			}

			$args = array();
			if ( '' !== $slug ) {
				$args['slug'] = $slug;
			}
			if ( '' !== $description ) {
				$args['description'] = $description;
			}
			if ( $parent > 0 ) {
				$args['parent'] = $parent;
			}

			$inserted = wp_insert_term( $name, $taxonomy, $args );
			if ( is_wp_error( $inserted ) ) {
				$errors[] = array(
					'term'   => $raw_term,
					'reason' => $inserted->get_error_message(),
				);
				continue;
			}

			$term_id    = (int) $inserted['term_id'];
			$term_ids[] = $term_id;
			$created[]  = array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'name'     => $name,
			);
			continue;
		}

		if ( ! $term ) {
			$errors[] = array(
				'term'   => $raw_term,
				'reason' => 'Term not found.',
			);
			continue;
		}

		$term_ids[] = (int) $term->term_id;
	}

	return array(
		'term_ids' => array_values( array_unique( $term_ids ) ),
		'created'  => $created,
		'errors'   => $errors,
	);
}

/**
 * Apply taxonomy terms to a post.
 *
 * @param int                 $post_id        Post ID.
 * @param string              $post_type      Post type.
 * @param array<string,mixed> $term_map Taxonomy => term input map.
 * @param bool                $append         Whether to append instead of replacing terms.
 * @param bool                $create_missing Whether missing terms may be created.
 * @return array{assigned:array<int,array<string,mixed>>,created:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
 */
function vip_mcp_apply_post_terms( int $post_id, string $post_type, array $term_map, bool $append = false, bool $create_missing = false ): array {
	$assigned = array();
	$created  = array();
	$errors   = array();

	foreach ( $term_map as $taxonomy => $raw_terms ) {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy ) {
			$errors[] = array(
				'taxonomy' => (string) $taxonomy,
				'reason'   => 'Invalid taxonomy slug.',
			);
			continue;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj ) {
			$errors[] = array(
				'taxonomy' => $taxonomy,
				'reason'   => 'Taxonomy is not registered.',
			);
			continue;
		}

		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			$errors[] = array(
				'taxonomy' => $taxonomy,
				'reason'   => "Taxonomy is not assigned to post type '{$post_type}'.",
			);
			continue;
		}

		if ( ! current_user_can( $taxonomy_obj->cap->assign_terms ) ) {
			$errors[] = array(
				'taxonomy' => $taxonomy,
				'reason'   => 'You do not have permission to assign terms in this taxonomy.',
			);
			continue;
		}

		if ( $create_missing && ! current_user_can( $taxonomy_obj->cap->manage_terms ) ) {
			$errors[] = array(
				'taxonomy' => $taxonomy,
				'reason'   => 'You do not have permission to create missing terms in this taxonomy.',
			);
			continue;
		}

		$terms    = is_array( $raw_terms ) ? $raw_terms : array( $raw_terms );
		$resolved = vip_mcp_resolve_term_ids( $taxonomy, $terms, $create_missing );
		$created  = array_merge( $created, $resolved['created'] );

		if ( ! empty( $resolved['errors'] ) ) {
			foreach ( $resolved['errors'] as $error ) {
				$error['taxonomy'] = $taxonomy;
				$errors[]          = $error;
			}
		}

		if ( empty( $resolved['term_ids'] ) && ! empty( $resolved['errors'] ) ) {
			continue;
		}

		$result = wp_set_object_terms( $post_id, $resolved['term_ids'], $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			$errors[] = array(
				'taxonomy' => $taxonomy,
				'reason'   => $result->get_error_message(),
			);
			continue;
		}

		$assigned[] = array(
			'taxonomy' => $taxonomy,
			'term_ids' => array_map( 'intval', $result ),
			'append'   => $append,
		);
	}

	return array(
		'assigned' => $assigned,
		'created'  => $created,
		'errors'   => $errors,
	);
}

/**
 * Gather assigned terms for a post.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return array<string,array<int,array<string,mixed>>>
 */
function vip_mcp_get_post_terms_response( int $post_id, string $post_type ): array {
	$response   = array();
	$taxonomies = get_object_taxonomies( $post_type, 'objects' );

	foreach ( $taxonomies as $taxonomy => $taxonomy_obj ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$response[ $taxonomy ] = array();
			continue;
		}

		$response[ $taxonomy ] = array_map(
			static function ( WP_Term $term ) use ( $taxonomy ): array {
				return array(
					'term_id'  => (int) $term->term_id,
					'taxonomy' => $taxonomy,
					'name'     => $term->name,
					'slug'     => $term->slug,
					'parent'   => (int) $term->parent,
				);
			},
			$terms
		);
	}

	return $response;
}

/**
 * Format an attachment response.
 *
 * @param WP_Post $attachment Attachment post.
 * @param int     $site_id    Site ID.
 * @return array<string,mixed>
 */
function vip_mcp_format_media_response( WP_Post $attachment, int $site_id ): array {
	$metadata = wp_get_attachment_metadata( $attachment->ID );
	$file     = get_attached_file( $attachment->ID );
	$url      = wp_get_attachment_url( $attachment->ID );

	return array(
		'attachment_id' => (int) $attachment->ID,
		'title'         => $attachment->post_title,
		'alt'           => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		'caption'       => $attachment->post_excerpt,
		'description'   => $attachment->post_content,
		'mime_type'     => $attachment->post_mime_type,
		'source_url'    => $url ? $url : '',
		'file_name'     => $file ? wp_basename( $file ) : '',
		'file_size'     => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
		'parent_id'     => (int) $attachment->post_parent,
		'date'          => $attachment->post_date,
		'modified'      => $attachment->post_modified,
		'edit_url'      => get_admin_url( $site_id, 'post.php?post=' . (int) $attachment->ID . '&action=edit' ),
		'metadata'      => is_array( $metadata ) ? $metadata : array(),
	);
}

/**
 * Rewrite exact content strings from an explicit source => target map.
 *
 * @param string                 $content Content to rewrite.
 * @param array<array-key,mixed> $map Source => target map.
 * @return array{content:string,replacements:int,details:array<int,array<string,mixed>>}
 */
function vip_mcp_rewrite_content_with_map( string $content, array $map ): array {
	$normalized = array();
	foreach ( $map as $from => $to ) {
		if ( ! is_scalar( $to ) ) {
			continue;
		}
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || $from === $to ) {
			continue;
		}
		$normalized[ $from ] = $to;
	}

	uksort(
		$normalized,
		static function ( string $a, string $b ): int {
			return strlen( $b ) <=> strlen( $a );
		}
	);

	$total   = 0;
	$details = array();
	foreach ( $normalized as $from => $to ) {
		$count = substr_count( $content, $from );
		if ( 0 === $count ) {
			continue;
		}
		$content   = str_replace( $from, $to, $content );
		$total    += $count;
		$details[] = array(
			'from'  => $from,
			'to'    => $to,
			'count' => $count,
		);
	}

	return array(
		'content'      => $content,
		'replacements' => $total,
		'details'      => $details,
	);
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
 * Register the VIP ability category.
 * Must be called on wp_abilities_api_categories_init — core enforces this with doing_action() check.
 */
function vip_mcp_register_multisite_category(): void {
	wp_register_ability_category(
		'vip-multisite',
		array(
			'label'       => is_multisite() ? 'VIP Multisite' : 'VIP Site Management',
			'description' => is_multisite()
				? 'WordPress multisite network management abilities.'
				: 'WordPress site management abilities.',
		)
	);
}

/**
 * Register all management abilities.
 *
 * Site-level abilities (content, options) register on both single-site and
 * Multisite installations. Network-level abilities (sites, users, themes,
 * plugins) only register when is_multisite() is true.
 *
 * Must be called on wp_abilities_api_init — core enforces this with doing_action() check.
 */
function vip_mcp_register_multisite_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	// ------------------------------------------------------------------
	// Site-level abilities — work on both single-site and Multisite.
	// ------------------------------------------------------------------

	// Content management abilities.
	vip_mcp_register_ability_inventory_site();
	vip_mcp_register_ability_list_post_types();
	vip_mcp_register_ability_list_taxonomies();
	vip_mcp_register_ability_list_terms();
	vip_mcp_register_ability_get_term();
	vip_mcp_register_ability_create_term();
	vip_mcp_register_ability_update_term();
	vip_mcp_register_ability_assign_post_terms();
	vip_mcp_register_ability_create_post();
	vip_mcp_register_ability_get_post();
	vip_mcp_register_ability_update_post();
	vip_mcp_register_ability_list_posts();
	vip_mcp_register_ability_list_media();
	vip_mcp_register_ability_get_media();
	vip_mcp_register_ability_copy_media_to_site();
	vip_mcp_register_ability_rewrite_content_links();

	// Site options abilities.
	vip_mcp_register_ability_get_site_option();
	vip_mcp_register_ability_update_site_option();

	// ------------------------------------------------------------------
	// Network-level abilities — Multisite only.
	// ------------------------------------------------------------------
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
			'label'               => 'List Network Sites',
			'description'         => 'Returns all sites registered in the WordPress multisite network.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page'        => array(
						'type'        => 'integer',
						'description' => 'Number of sites to return per page (default 50, max 100).',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 100,
					),
					'page'            => array(
						'type'        => 'integer',
						'description' => 'Page number for pagination (default 1).',
						'default'     => 1,
						'minimum'     => 1,
					),
					'search'          => array(
						'type'        => 'string',
						'description' => 'Optional search term to filter sites by domain or path.',
					),
					'include_options' => array(
						'type'        => 'boolean',
						'description' => 'Include blogname, description, and active_theme in results. '
						. 'Each requires a per-site DB query. Set false for lightweight listing on large networks. Default true.',
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sites'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$per_page = min( max( (int) ( $input['per_page'] ?? 50 ), 1 ), 100 );
				$page     = max( (int) ( $input['page'] ?? 1 ), 1 );
				$search   = $input['search'] ?? '';

				$args = array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				);

				if ( $search ) {
					$args['search'] = '*' . sanitize_text_field( $search ) . '*';
				}

				$sites = get_sites( $args );
				$total = get_sites(
					array_merge(
						$args,
						array(
							'number' => 0,
							'count'  => true,
						)
					)
				);

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
						'url'          => get_site_url( (int) $site->blog_id ),
						'registered'   => $site->registered,
						'last_updated' => $site->last_updated,
						'public'       => (bool) $site->public,
						'archived'     => (bool) $site->archived,
						'deleted'      => (bool) $site->deleted,
						'spam'         => (bool) $site->spam,
					);

					if ( $include_options ) {
						$row['name']         = get_blog_option( (int) $site->blog_id, 'blogname' );
						$row['description']  = get_blog_option( (int) $site->blog_id, 'blogdescription' );
						$row['active_theme'] = get_blog_option( (int) $site->blog_id, 'stylesheet' );
					}

					$result[] = $row;
				}

				return array(
					'sites'       => $result,
					'total'       => (int) $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Create Network Site',
			'description'         => 'Creates a new site in the WordPress multisite network.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'domain', 'title', 'admin_email' ),
				'properties' => array(
					'domain'                 => array(
						'type'        => 'string',
						'description' => 'The slug for the new site (e.g. "newsroom"). Used as subdomain or subdirectory depending on network config.',
					),
					'title'                  => array(
						'type'        => 'string',
						'description' => 'The display name / title of the new site.',
					),
					'admin_email'            => array(
						'type'        => 'string',
						'description' => 'Email address of the site administrator. Must match an existing network user unless create_user_if_missing is set to true.',
					),
					'public'                 => array(
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
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'site_id' => array( 'type' => 'integer' ),
					'url'     => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$network = get_network();
				$base    = $network->domain;
				$slug    = sanitize_title( $input['domain'] );

				if ( empty( $slug ) ) {
					return array(
						'success' => false,
						'site_id' => 0,
						'url'     => '',
						'message' => 'Invalid site slug — the domain value produced an empty slug after sanitization.',
					);
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
					return array(
						'success' => false,
						'site_id' => 0,
						'url'     => '',
						'message' => 'Invalid email address.',
					);
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
						return array(
							'success' => false,
							'site_id' => 0,
							'url'     => '',
							'message' => "Could not generate a unique username for '{$admin_email}'. Too many collisions.",
						);
					}
					$user_id = wpmu_create_user( $username, wp_generate_password(), $admin_email );
					if ( ! $user_id ) {
						return array(
							'success' => false,
							'site_id' => 0,
							'url'     => '',
							'message' => "Failed to create user for '{$admin_email}'.",
						);
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
					return array(
						'success' => false,
						'site_id' => 0,
						'url'     => '',
						'message' => $site_id->get_error_message(),
					);
				}

				return array(
					'success' => true,
					'site_id' => (int) $site_id,
					'url'     => get_site_url( $site_id ),
					'message' => sprintf( 'Site "%s" created successfully (ID: %d).', $input['title'], $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Get Site Details',
			'description'         => 'Returns detailed information about a specific site in the network.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the site to retrieve.',
					),
				),
			),
			'output_schema'       => array(
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
			'execute_callback'    => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$site    = get_site( $site_id );

				if ( ! $site ) {
					return array(
						'success' => false,
						'message' => "Site ID {$site_id} not found.",
					);
				}

				$users     = get_users(
					array(
						'blog_id' => $site_id,
						'fields'  => array( 'ID', 'user_login', 'user_email' ),
						'number'  => 20,
					)
				);
				$user_list = array_map(
					static fn( $u ) => array(
						'id'       => $u->ID,
						'username' => $u->user_login,
						'email'    => $u->user_email,
					),
					$users
				);

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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Update Site Settings',
			'description'         => 'Updates settings (name, description, public visibility) for a network site.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'site_id' ),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the site to update.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'New display name for the site.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'New tagline / description.',
					),
					'public'      => array(
						'type'        => 'boolean',
						'description' => 'Set whether the site is publicly visible.',
					),
					'admin_email' => array(
						'type'        => 'string',
						'description' => 'New admin email address.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'message' => "Site ID {$site_id} not found.",
					);
				}

				// --- Validation phase — validate all inputs before writing anything. ---

				if ( ! isset( $input['name'] ) && ! isset( $input['description'] ) &&
					! isset( $input['public'] ) && ! isset( $input['admin_email'] ) ) {
					return array(
						'success' => false,
						'message' => 'No fields provided to update.',
					);
				}

				$validated_email = null;
				if ( isset( $input['admin_email'] ) ) {
					$validated_email = sanitize_email( $input['admin_email'] );
					if ( empty( $validated_email ) ) {
						return array(
							'success' => false,
							'message' => 'Invalid admin email address.',
						);
					}
					if ( ! get_user_by( 'email', $validated_email ) ) {
						return array(
							'success' => false,
							'message' => "Admin email must belong to an existing network user. No user found for '{$validated_email}'.",
						);
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
					update_blog_status( $site_id, 'public', (string) (int) $input['public'] );
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'List Network Themes',
			'description'         => 'Returns all themes installed on the network, including which are network-enabled.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'Optional site ID to check which theme is active on that site.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'themes' => array( 'type' => 'array' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Activate Theme on Site',
			'description'         => 'Activates a theme on a specific network site, network-enabling it first if necessary.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'site_id', 'theme_slug' ),
				'properties' => array(
					'site_id'        => array(
						'type'        => 'integer',
						'description' => 'The ID of the site to activate the theme on.',
					),
					'theme_slug'     => array(
						'type'        => 'string',
						'description' => 'The theme stylesheet slug (directory name) to activate.',
					),
					'network_enable' => array(
						'type'        => 'boolean',
						'description' => 'Whether to also network-enable the theme if not already (default true).',
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
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
			'execute_callback'    => static function ( array $input ): array {
				$site_id    = (int) $input['site_id'];
				$theme_slug = sanitize_text_field( $input['theme_slug'] );

				$site = get_site( $site_id );
				if ( ! $site ) {
					return array(
						'success'    => false,
						'message'    => "Site ID {$site_id} does not exist.",
						'theme_name' => '',
						'site_url'   => '',
					);
				}

				$theme = wp_get_theme( $theme_slug );
				if ( ! $theme->exists() ) {
					return array(
						'success'    => false,
						'message'    => "Theme '{$theme_slug}' is not installed.",
						'theme_name' => '',
						'site_url'   => get_site_url( $site_id ),
					);
				}

				if ( $input['network_enable'] ?? true ) {
					$allowed = get_site_option( 'allowedthemes', array() );
					if ( ! isset( $allowed[ $theme_slug ] ) ) {
						$allowed[ $theme_slug ] = true;
						update_site_option( 'allowedthemes', $allowed );
					}
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					switch_theme( $theme_slug ); // Handles template/stylesheet correctly, fires switch_theme and after_switch_theme hooks.
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'    => true,
					'message'    => sprintf( 'Theme "%s" activated on site "%s" (ID: %d).', $theme->get( 'Name' ), get_blog_option( $site_id, 'blogname' ), $site_id ),
					'theme_name' => $theme->get( 'Name' ),
					'site_url'   => get_site_url( $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'List Network Users',
			'description'         => 'Returns all users registered in the WordPress multisite network.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'maximum' => 100,
						'minimum' => 1,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search by username, email, or display name.',
					),
					'site_id'  => array(
						'type'        => 'integer',
						'description' => 'Filter to users belonging to a specific site.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'users'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Add User to Site',
			'description'         => 'Adds an existing network user to a specific site with a given role.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'site_id', 'user_id', 'role' ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the site.',
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the user to add.',
					),
					'role'    => array(
						'type'        => 'string',
						'description' => 'The role to assign.',
						'enum'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = (int) $input['site_id'];
				$user_id = (int) $input['user_id'];
				$role    = sanitize_text_field( $input['role'] );

				$allowed_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
				if ( ! in_array( $role, $allowed_roles, true ) ) {
					return array(
						'success' => false,
						'message' => "Role '{$role}' is not allowed. Must be one of: " . implode( ', ', $allowed_roles ) . '.',
					);
				}

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'message' => "Site ID {$site_id} not found.",
					);
				}
				$user = get_user_by( 'id', $user_id );
				if ( ! $user ) {
					return array(
						'success' => false,
						'message' => "User ID {$user_id} not found.",
					);
				}

				$result = add_user_to_blog( $site_id, $user_id, $role );

				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				return array(
					'success' => true,
					'message' => sprintf( 'User "%s" added to site "%s" with role "%s".', $user->user_login, get_blog_option( $site_id, 'blogname' ), $role ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Create Network User',
			'description'         => 'Creates a new user account on the WordPress multisite network.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'username', 'email' ),
				'properties' => array(
					'username'          => array(
						'type'        => 'string',
						'description' => 'Login username.',
					),
					'email'             => array(
						'type'        => 'string',
						'description' => 'Email address.',
					),
					'first_name'        => array(
						'type'        => 'string',
						'description' => 'Optional first name.',
					),
					'last_name'         => array(
						'type'        => 'string',
						'description' => 'Optional last name.',
					),
					'send_notification' => array(
						'type'        => 'boolean',
						'description' => 'Send welcome email (default true).',
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
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
			'execute_callback'    => static function ( array $input ): array {
				$username = sanitize_user( $input['username'], true );
				$email    = sanitize_email( $input['email'] );

				if ( empty( $username ) ) {
					return array(
						'success' => false,
						'user_id' => 0,
						'message' => 'Invalid username after sanitization.',
					);
				}
				if ( empty( $email ) ) {
					return array(
						'success' => false,
						'user_id' => 0,
						'message' => 'Invalid email address.',
					);
				}

				if ( username_exists( $username ) ) {
					return array(
						'success' => false,
						'user_id' => 0,
						'message' => "Username '{$username}' is already taken.",
					);
				}
				if ( email_exists( $email ) ) {
					return array(
						'success' => false,
						'user_id' => 0,
						'message' => "Email '{$email}' is already registered.",
					);
				}

				$password = wp_generate_password( 24 );
				$user_id  = wpmu_create_user( $username, $password, $email );

				if ( ! $user_id ) {
					return array(
						'success' => false,
						'user_id' => 0,
						'message' => "Failed to create user '{$username}'.",
					);
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'List Network Plugins',
			'description'         => 'Returns all plugins that are network-activated across the multisite.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array( 'type' => 'array' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_network_options' );
			},
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $input required by abilities API contract.
			'execute_callback'    => static function ( array $input ): array {
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

// =========================================================================
// CONTENT MANAGEMENT ABILITIES (11–15)
// =========================================================================

/**
 * 11. Inventory a site for translation or migration planning.
 */
function vip_mcp_register_ability_inventory_site(): void {
	wp_register_ability(
		'vip-multisite/inventory-site',
		array(
			'label'               => 'Inventory Site',
			'description'         => 'Returns a compact inventory of a site for translation or migration planning, including theme, '
				. 'key options, post type counts, taxonomy counts, media count, and navigation/template signals.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id(),
				'properties' => array(
					'site_id'         => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'include_options' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'include_content' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'include_terms'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'include_theme'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'site_id'    => array( 'type' => 'integer' ),
					'site'       => array( 'type' => 'object' ),
					'theme'      => array( 'type' => 'object' ),
					'options'    => array( 'type' => 'object' ),
					'postTypes'  => array( 'type' => 'array' ),
					'taxonomies' => array( 'type' => 'array' ),
					'media'      => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = vip_mcp_resolve_site_id( $input );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'message' => "Site ID {$site_id} not found.",
					);
				}

				$response = array(
					'success'    => false,
					'site_id'    => $site_id,
					'site'       => array(),
					'theme'      => array(),
					'options'    => array(),
					'postTypes'  => array(),
					'taxonomies' => array(),
					'media'      => array(),
					'message'    => 'An unexpected error occurred.',
				);

				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'read' ) ) {
						$response['message'] = "You do not have permission to inventory site {$site_id}.";
						return $response;
					}

					$response['site'] = array(
						'id'          => $site_id,
						'name'        => get_bloginfo( 'name' ),
						'description' => get_bloginfo( 'description' ),
						'url'         => home_url( '/' ),
						'admin_url'   => admin_url(),
					);

					if ( $input['include_theme'] ?? true ) {
						$theme = wp_get_theme();
						$response['theme'] = array(
							'name'           => $theme->get( 'Name' ),
							'version'        => $theme->get( 'Version' ),
							'template'       => get_template(),
							'stylesheet'     => get_stylesheet(),
							'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
						);
					}

					if ( ( $input['include_options'] ?? true ) && current_user_can( 'manage_options' ) ) {
						$inventory_options = array(
							'blogname',
							'blogdescription',
							'WPLANG',
							'show_on_front',
							'page_on_front',
							'page_for_posts',
							'permalink_structure',
							'category_base',
							'tag_base',
							'timezone_string',
							'date_format',
							'time_format',
							'blog_public',
						);
						foreach ( $inventory_options as $option_key ) {
							$value = get_option( $option_key );
							$response['options'][ $option_key ] = is_bool( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : $value );
						}
					}

					if ( $input['include_content'] ?? true ) {
						$post_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $post_types as $slug => $post_type ) {
							$counts = wp_count_posts( $slug );
							$response['postTypes'][] = array(
								'slug'         => $slug,
								'label'        => $post_type->label,
								'hierarchical' => (bool) $post_type->hierarchical,
								'show_in_rest' => (bool) $post_type->show_in_rest,
								'taxonomies'   => array_values( get_object_taxonomies( $slug ) ),
								'counts'       => (array) $counts,
							);
						}

						$attachment_counts = wp_count_posts( 'attachment' );
						$response['media'] = array(
							'counts' => (array) $attachment_counts,
						);
					}

					if ( $input['include_terms'] ?? true ) {
						$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
						foreach ( $taxonomies as $slug => $taxonomy ) {
							$slug  = (string) $slug;
							$count = get_terms(
								array(
									'taxonomy'   => $slug,
									'hide_empty' => false,
									'fields'     => 'count',
								)
							);
							$response['taxonomies'][] = array(
								'slug'         => $slug,
								'label'        => $taxonomy->label,
								'hierarchical' => (bool) $taxonomy->hierarchical,
								'show_in_rest' => (bool) $taxonomy->show_in_rest,
								'object_types' => array_values( $taxonomy->object_type ),
								'term_count'   => is_wp_error( $count ) ? 0 : (int) $count,
							);
						}
					}

					$response['success'] = true;
					$response['message'] = "Inventory generated for site {$site_id}.";
				} finally {
					vip_mcp_restore_site();
				}

				return $response;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 12. List registered taxonomies on a specific sub-site.
 */
function vip_mcp_register_ability_list_taxonomies(): void {
	wp_register_ability(
		'vip-multisite/list-taxonomies',
		array(
			'label'               => 'List Taxonomies on Site',
			'description'         => 'Returns registered taxonomies on a site, optionally scoped to a post type. Use this before copying or assigning terms.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id(),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'post_type'   => array(
						'type'        => 'string',
						'description' => 'Optional post type slug to return only taxonomies assigned to that post type.',
					),
					'public_only' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'site_id'    => array( 'type' => 'integer' ),
					'taxonomies' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id     = vip_mcp_resolve_site_id( $input );
				$post_type   = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
				$public_only = $input['public_only'] ?? true;

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'    => false,
						'site_id'    => $site_id,
						'taxonomies' => array(),
						'message'    => "Site ID {$site_id} not found.",
					);
				}

				$result = array();
				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'read' ) ) {
						return array(
							'success'    => false,
							'site_id'    => $site_id,
							'taxonomies' => array(),
							'message'    => "You do not have permission to read taxonomies on site {$site_id}.",
						);
					}

					if ( '' !== $post_type && ! get_post_type_object( $post_type ) ) {
						return array(
							'success'    => false,
							'site_id'    => $site_id,
							'taxonomies' => array(),
							'message'    => "Post type '{$post_type}' is not registered on site {$site_id}.",
						);
					}

					$taxonomies = '' !== $post_type
						? get_object_taxonomies( $post_type, 'objects' )
						: get_taxonomies( $public_only ? array( 'public' => true ) : array(), 'objects' );

					foreach ( $taxonomies as $slug => $taxonomy ) {
						if ( $public_only && ! $taxonomy->public ) {
							continue;
						}
						if ( ! $taxonomy->public && ! current_user_can( $taxonomy->cap->manage_terms ) ) {
							continue;
						}

						$result[] = array(
							'slug'         => $slug,
							'label'        => $taxonomy->label,
							'description'  => $taxonomy->description,
							'hierarchical' => (bool) $taxonomy->hierarchical,
							'public'       => (bool) $taxonomy->public,
							'show_in_rest' => (bool) $taxonomy->show_in_rest,
							'rest_base'    => $taxonomy->rest_base ? $taxonomy->rest_base : $slug,
							'builtin'      => (bool) $taxonomy->_builtin,
							'object_types' => array_values( $taxonomy->object_type ),
							'capabilities' => (array) $taxonomy->cap,
						);
					}
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'    => true,
					'site_id'    => $site_id,
					'taxonomies' => $result,
					'message'    => sprintf( 'Found %d taxonomy/taxonomies on site %d.', count( $result ), $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 13. List terms from a taxonomy.
 */
function vip_mcp_register_ability_list_terms(): void {
	wp_register_ability(
		'vip-multisite/list-terms',
		array(
			'label'               => 'List Terms on Site',
			'description'         => 'Returns a paginated list of terms from a taxonomy, including parent IDs and registered term meta.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'taxonomy' ) ),
				'properties' => array(
					'site_id'    => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'taxonomy'   => array(
						'type'        => 'string',
						'description' => 'Taxonomy slug, for example category, post_tag, or a custom taxonomy.',
					),
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'search'     => array( 'type' => 'string' ),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Optional parent term ID. Use 0 to list top-level terms.',
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'site_id'     => array( 'type' => 'integer' ),
					'taxonomy'    => array( 'type' => 'string' ),
					'terms'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id    = vip_mcp_resolve_site_id( $input );
				$taxonomy   = sanitize_key( $input['taxonomy'] ?? '' );
				$per_page   = min( max( (int) ( $input['per_page'] ?? 50 ), 1 ), 100 );
				$page       = max( 1, (int) ( $input['page'] ?? 1 ) );
				$hide_empty = (bool) ( $input['hide_empty'] ?? false );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'     => false,
						'site_id'     => $site_id,
						'taxonomy'    => $taxonomy,
						'terms'       => array(),
						'total'       => 0,
						'total_pages' => 0,
						'message'     => "Site ID {$site_id} not found.",
					);
				}

				$terms = array();
				$total = 0;

				vip_mcp_switch_to_site( $site_id );
				try {
					$taxonomy_obj = get_taxonomy( $taxonomy );
					if ( ! $taxonomy_obj ) {
						return array(
							'success'     => false,
							'site_id'     => $site_id,
							'taxonomy'    => $taxonomy,
							'terms'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => "Taxonomy '{$taxonomy}' is not registered on site {$site_id}.",
						);
					}

					if ( ! $taxonomy_obj->public && ! current_user_can( $taxonomy_obj->cap->manage_terms ) ) {
						return array(
							'success'     => false,
							'site_id'     => $site_id,
							'taxonomy'    => $taxonomy,
							'terms'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => "You do not have permission to list private taxonomy '{$taxonomy}' on site {$site_id}.",
						);
					}

					$args = array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => $hide_empty,
						'number'     => $per_page,
						'offset'     => ( $page - 1 ) * $per_page,
						'orderby'    => 'name',
						'order'      => 'ASC',
					);

					if ( isset( $input['parent'] ) ) {
						$args['parent'] = max( 0, (int) $input['parent'] );
					}
					if ( ! empty( $input['search'] ) ) {
						$args['search'] = sanitize_text_field( $input['search'] );
					}

					$query_terms = get_terms( $args );
					if ( is_wp_error( $query_terms ) ) {
						return array(
							'success'     => false,
							'site_id'     => $site_id,
							'taxonomy'    => $taxonomy,
							'terms'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => $query_terms->get_error_message(),
						);
					}

					foreach ( $query_terms as $term ) {
						$terms[] = vip_mcp_format_term_response( $term, $taxonomy, $site_id );
					}

					$count_args = $args;
					unset( $count_args['number'], $count_args['offset'] );
					$count_args['fields'] = 'count';
					$count = get_terms( $count_args );
					$total = is_wp_error( $count ) ? 0 : (int) $count;
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'     => true,
					'site_id'     => $site_id,
					'taxonomy'    => $taxonomy,
					'terms'       => $terms,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'message'     => sprintf( 'Found %d term(s) in taxonomy %s on site %d.', $total, $taxonomy, $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 14. Get a term.
 */
function vip_mcp_register_ability_get_term(): void {
	wp_register_ability(
		'vip-multisite/get-term',
		array(
			'label'               => 'Get Term from Site',
			'description'         => 'Retrieves a term by ID or slug, including registered term meta.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'taxonomy' ) ),
				'properties' => array(
					'site_id'  => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'taxonomy' => array( 'type' => 'string' ),
					'term_id'  => array( 'type' => 'integer' ),
					'slug'     => array( 'type' => 'string' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'term'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id  = vip_mcp_resolve_site_id( $input );
				$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'term'    => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					$taxonomy_obj = get_taxonomy( $taxonomy );
					if ( ! $taxonomy_obj ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => "Taxonomy '{$taxonomy}' is not registered on site {$site_id}.",
						);
					}
					if ( ! $taxonomy_obj->public && ! current_user_can( $taxonomy_obj->cap->manage_terms ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => "You do not have permission to read private taxonomy '{$taxonomy}' on site {$site_id}.",
						);
					}

					if ( ! empty( $input['term_id'] ) ) {
						$term = get_term( (int) $input['term_id'], $taxonomy );
					} elseif ( ! empty( $input['slug'] ) ) {
						$term = get_term_by( 'slug', sanitize_title( $input['slug'] ), $taxonomy );
					} else {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => 'Provide term_id or slug.',
						);
					}

					if ( is_wp_error( $term ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => $term->get_error_message(),
						);
					}
					if ( ! $term ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => 'Term not found.',
						);
					}

					return array(
						'success' => true,
						'term'    => vip_mcp_format_term_response( $term, $taxonomy, $site_id ),
						'message' => 'Term retrieved successfully.',
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 15. Create a term.
 */
function vip_mcp_register_ability_create_term(): void {
	wp_register_ability(
		'vip-multisite/create-term',
		array(
			'label'               => 'Create Term on Site',
			'description'         => 'Creates a taxonomy term on a site, preserving slug, description, hierarchy, and registered term meta.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'taxonomy', 'name' ) ),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'taxonomy'    => array( 'type' => 'string' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'meta'        => array(
						'type'        => 'object',
						'description' => 'Optional registered term meta key/value map.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'term'         => array( 'type' => 'object' ),
					'updated_meta' => array( 'type' => 'array' ),
					'skipped_meta' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_categories' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id  = vip_mcp_resolve_site_id( $input );
				$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
				$name     = sanitize_text_field( $input['name'] ?? '' );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'term'    => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					$taxonomy_obj = get_taxonomy( $taxonomy );
					if ( ! $taxonomy_obj ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => "Taxonomy '{$taxonomy}' is not registered on site {$site_id}.",
						);
					}
					if ( ! current_user_can( $taxonomy_obj->cap->manage_terms ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => "You do not have permission to create terms in taxonomy '{$taxonomy}' on site {$site_id}.",
						);
					}
					if ( '' === $name ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => 'Term name is required.',
						);
					}

					$args = array();
					if ( ! empty( $input['slug'] ) ) {
						$args['slug'] = sanitize_title( $input['slug'] );
					}
					if ( isset( $input['description'] ) ) {
						$args['description'] = sanitize_textarea_field( $input['description'] );
					}
					if ( ! empty( $input['parent'] ) ) {
						$parent = get_term( (int) $input['parent'], $taxonomy );
						if ( ! $parent || is_wp_error( $parent ) ) {
							return array(
								'success' => false,
								'term'    => array(),
								'message' => "Parent term {$input['parent']} was not found in taxonomy '{$taxonomy}'.",
							);
						}
						$args['parent'] = (int) $input['parent'];
					}

					$inserted = wp_insert_term( $name, $taxonomy, $args );
					if ( is_wp_error( $inserted ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'message' => $inserted->get_error_message(),
						);
					}

					$term_id      = (int) $inserted['term_id'];
					$meta_result  = array(
						'updated' => array(),
						'skipped' => array(),
					);
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$meta_result = vip_mcp_update_registered_meta_values( 'term', $taxonomy, $term_id, $input['meta'] );
					}
					$term = get_term( $term_id, $taxonomy );

					return array(
						'success'      => true,
						'term'         => $term instanceof WP_Term ? vip_mcp_format_term_response( $term, $taxonomy, $site_id ) : array(),
						'updated_meta' => $meta_result['updated'],
						'skipped_meta' => $meta_result['skipped'],
						'message'      => sprintf( 'Created term "%s" in taxonomy %s on site %d.', $name, $taxonomy, $site_id ),
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 16. Update a term.
 */
function vip_mcp_register_ability_update_term(): void {
	wp_register_ability(
		'vip-multisite/update-term',
		array(
			'label'               => 'Update Term on Site',
			'description'         => 'Updates a taxonomy term on a site. Only supplied fields are changed. Registered term meta may also be updated.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'taxonomy', 'term_id' ) ),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'taxonomy'    => array( 'type' => 'string' ),
					'term_id'     => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'meta'        => array(
						'type'        => 'object',
						'description' => 'Optional registered term meta key/value map.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'term'         => array( 'type' => 'object' ),
					'updated'      => array( 'type' => 'array' ),
					'skipped_meta' => array( 'type' => 'array' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_categories' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id  = vip_mcp_resolve_site_id( $input );
				$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
				$term_id  = (int) ( $input['term_id'] ?? 0 );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'term'    => array(),
						'updated' => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					$taxonomy_obj = get_taxonomy( $taxonomy );
					if ( ! $taxonomy_obj ) {
						return array(
							'success' => false,
							'term'    => array(),
							'updated' => array(),
							'message' => "Taxonomy '{$taxonomy}' is not registered on site {$site_id}.",
						);
					}
					if ( ! current_user_can( $taxonomy_obj->cap->edit_terms ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'updated' => array(),
							'message' => "You do not have permission to edit terms in taxonomy '{$taxonomy}' on site {$site_id}.",
						);
					}

					$term = get_term( $term_id, $taxonomy );
					if ( ! $term || is_wp_error( $term ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'updated' => array(),
							'message' => "Term {$term_id} was not found in taxonomy '{$taxonomy}'.",
						);
					}

					$args    = array();
					$updated = array();
					if ( isset( $input['name'] ) ) {
						$args['name'] = sanitize_text_field( $input['name'] );
						$updated[] = 'name';
					}
					if ( isset( $input['slug'] ) ) {
						$args['slug'] = sanitize_title( $input['slug'] );
						$updated[] = 'slug';
					}
					if ( isset( $input['description'] ) ) {
						$args['description'] = sanitize_textarea_field( $input['description'] );
						$updated[] = 'description';
					}
					if ( isset( $input['parent'] ) ) {
						$parent = (int) $input['parent'];
						if ( $parent > 0 ) {
							$parent_term = get_term( $parent, $taxonomy );
							if ( ! $parent_term || is_wp_error( $parent_term ) ) {
								return array(
									'success' => false,
									'term'    => array(),
									'updated' => array(),
									'message' => "Parent term {$parent} was not found in taxonomy '{$taxonomy}'.",
								);
							}
						}
						$args['parent'] = $parent;
						$updated[] = 'parent';
					}

					if ( ! empty( $args ) ) {
						$result = wp_update_term( $term_id, $taxonomy, $args );
						if ( is_wp_error( $result ) ) {
							return array(
								'success' => false,
								'term'    => array(),
								'updated' => array(),
								'message' => $result->get_error_message(),
							);
						}
					}

					$meta_result = array(
						'updated' => array(),
						'skipped' => array(),
					);
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$meta_result = vip_mcp_update_registered_meta_values( 'term', $taxonomy, $term_id, $input['meta'] );
						foreach ( $meta_result['updated'] as $meta_key ) {
							$updated[] = "meta:{$meta_key}";
						}
					}

					if ( empty( $updated ) ) {
						return array(
							'success' => false,
							'term'    => array(),
							'updated' => array(),
							'message' => 'No fields provided to update.',
						);
					}

					$term = get_term( $term_id, $taxonomy );
					return array(
						'success'      => true,
						'term'         => $term instanceof WP_Term ? vip_mcp_format_term_response( $term, $taxonomy, $site_id ) : array(),
						'updated'      => $updated,
						'skipped_meta' => $meta_result['skipped'],
						'message'      => sprintf( 'Updated term %d in taxonomy %s on site %d.', $term_id, $taxonomy, $site_id ),
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 17. Assign terms to a post.
 */
function vip_mcp_register_ability_assign_post_terms(): void {
	wp_register_ability(
		'vip-multisite/assign-post-terms',
		array(
			'label'               => 'Assign Terms to Post',
			'description'         => 'Assigns taxonomy terms to a post, page, or custom post type entry. Terms may be IDs, slugs, names, '
				. 'or objects with id/slug/name. By default assignments replace existing terms per taxonomy.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'post_id', 'terms' ) ),
				'properties' => array(
					'site_id'              => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'post_id'              => array( 'type' => 'integer' ),
					'terms'                => array(
						'type'        => 'object',
						'description' => 'Taxonomy-to-terms map, e.g. {"category":[12,"news"],"post_tag":["featured"]}. Empty arrays clear that taxonomy when append is false.',
					),
					'append'               => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'create_missing_terms' => array(
						'type'        => 'boolean',
						'description' => 'If true, missing string/object terms may be created when the user can manage the taxonomy.',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'assigned' => array( 'type' => 'array' ),
					'created'  => array( 'type' => 'array' ),
					'errors'   => array( 'type' => 'array' ),
					'terms'    => array( 'type' => 'object' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = vip_mcp_resolve_site_id( $input );
				$post_id = (int) ( $input['post_id'] ?? 0 );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'  => false,
						'post_id'  => $post_id,
						'assigned' => array(),
						'created'  => array(),
						'errors'   => array(),
						'terms'    => array(),
						'message'  => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'assigned' => array(),
							'created'  => array(),
							'errors'   => array(),
							'terms'    => array(),
							'message'  => "Post ID {$post_id} not found on site {$site_id}.",
						);
					}
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'assigned' => array(),
							'created'  => array(),
							'errors'   => array(),
							'terms'    => array(),
							'message'  => "You do not have permission to edit post {$post_id} on site {$site_id}.",
						);
					}
					if ( empty( $input['terms'] ) || ! is_array( $input['terms'] ) ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'assigned' => array(),
							'created'  => array(),
							'errors'   => array(),
							'terms'    => array(),
							'message'  => 'terms must be a taxonomy-to-terms object.',
						);
					}

					$result = vip_mcp_apply_post_terms(
						$post_id,
						$post->post_type,
						$input['terms'],
						(bool) ( $input['append'] ?? false ),
						(bool) ( $input['create_missing_terms'] ?? false )
					);

					return array(
						'success'  => empty( $result['errors'] ),
						'post_id'  => $post_id,
						'assigned' => $result['assigned'],
						'created'  => $result['created'],
						'errors'   => $result['errors'],
						'terms'    => vip_mcp_get_post_terms_response( $post_id, $post->post_type ),
						'message'  => empty( $result['errors'] )
							? sprintf( 'Assigned terms for post %d on site %d.', $post_id, $site_id )
							: sprintf( 'Assigned terms for post %d on site %d with %d error(s).', $post_id, $site_id, count( $result['errors'] ) ),
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 11. List registered post types on a specific sub-site.
 */
function vip_mcp_register_ability_list_post_types(): void {
	wp_register_ability(
		'vip-multisite/list-post-types',
		array(
			'label'               => 'List Post Types on Site',
			'description'         => 'Returns all registered post types on a specific network sub-site, including custom post types '
				. 'with their labels, capabilities, and supported features. '
				. 'Use this to discover available post types before creating or listing content.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id(),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'public_only' => array(
						'type'        => 'boolean',
						'description' => 'If true, only return public post types. If false, return all registered post types including internal ones (default: true).',
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'site_id'    => array( 'type' => 'integer' ),
					'post_types' => array( 'type' => 'array' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id     = vip_mcp_resolve_site_id( $input );
				$public_only = $input['public_only'] ?? true;

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'    => false,
						'site_id'    => $site_id,
						'post_types' => array(),
						'message'    => "Site ID {$site_id} not found.",
					);
				}

				$result = array();

				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'read' ) ) {
						return array(
							'success'    => false,
							'site_id'    => $site_id,
							'post_types' => array(),
							'message'    => 'You do not have permission to read content on this site.',
						);
					}

					$args = $public_only ? array( 'public' => true ) : array();
					$post_types = get_post_types( $args, 'objects' );

					foreach ( $post_types as $slug => $post_type ) {
						$supports = array();
						foreach ( array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions', 'author', 'page-attributes', 'comments' ) as $feature ) {
							if ( post_type_supports( $slug, $feature ) ) {
								$supports[] = $feature;
							}
						}

						$row = array(
							'slug'         => $slug,
							'label'        => $post_type->label,
							'labels'       => array(
								'singular' => $post_type->labels->singular_name,
								'add_new'  => $post_type->labels->add_new_item,
							),
							'public'       => (bool) $post_type->public,
							'hierarchical' => (bool) $post_type->hierarchical,
							'has_archive'  => (bool) $post_type->has_archive,
							'show_in_rest' => (bool) $post_type->show_in_rest,
							'rest_base'    => $post_type->rest_base ? $post_type->rest_base : $slug,
							'supports'     => $supports,
							'builtin'      => $post_type->_builtin,
						);

						// Include taxonomy connections.
						$taxonomies = get_object_taxonomies( $slug, 'objects' );
						$row['taxonomies'] = array();
						foreach ( $taxonomies as $tax_slug => $taxonomy ) {
							$row['taxonomies'][] = array(
								'slug'  => $tax_slug,
								'label' => $taxonomy->label,
							);
						}

						$result[] = $row;
					}
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'    => true,
					'site_id'    => $site_id,
					'post_types' => $result,
					'message'    => sprintf( 'Found %d post type(s) on site %d.', count( $result ), $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 12. Create a post, page, or custom post type entry on a specific sub-site.
 */
function vip_mcp_register_ability_create_post(): void {
	wp_register_ability(
		'vip-multisite/create-post',
		array(
			'label'               => 'Create Post on Site',
			'description'         => 'Creates a post, page, or custom post type entry on a specific network sub-site. '
				. 'Use list-post-types to discover available post types. '
				. 'Supports setting title, content (raw HTML/blocks), status, and post type.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'title' ) ),
				'properties' => array(
					'site_id'              => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'title'                => array(
						'type'        => 'string',
						'description' => 'The title of the post or page.',
					),
					'content'              => array(
						'type'        => 'string',
						'description' => 'The body content. Accepts raw HTML or Gutenberg block markup. Leave empty for a blank post.',
						'default'     => '',
					),
					'post_type'            => array(
						'type'        => 'string',
						'description' => 'The post type to create. Use "post", "page", or any registered custom post type slug (default: "page"). Use list-post-types to discover available types.',
						'default'     => 'page',
					),
					'status'               => array(
						'type'        => 'string',
						'description' => 'The publishing status (default: "draft").',
						'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
						'default'     => 'draft',
					),
					'excerpt'              => array(
						'type'        => 'string',
						'description' => 'Optional short excerpt / summary for the post.',
					),
					'slug'                 => array(
						'type'        => 'string',
						'description' => 'Optional URL slug. Auto-generated from title if omitted.',
					),
					'template'             => array(
						'type'        => 'string',
						'description' => 'Optional page template filename (e.g. "templates/full-width.html"). Only applicable to hierarchical post types (pages).',
					),
					'author_id'            => array(
						'type'        => 'integer',
						'description' => 'Optional user ID to set as the post author. Defaults to the currently authenticated user.',
					),
					'parent_id'            => array(
						'type'        => 'integer',
						'description' => 'Optional parent post/page ID. Only valid for hierarchical post types.',
					),
					'menu_order'           => array(
						'type'        => 'integer',
						'description' => 'Optional menu order value for hierarchical content.',
					),
					'comment_status'       => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Optional comment status.',
					),
					'ping_status'          => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'Optional ping status.',
					),
					'featured_media_id'    => array(
						'type'        => 'integer',
						'description' => 'Optional attachment ID to set as the featured image. Use copy-media-to-site first when copying from another site.',
					),
					'terms'                => array(
						'type'        => 'object',
						'description' => 'Optional taxonomy-to-terms map to assign after creation, e.g. {"category":[12,"news"],"post_tag":["featured"]}.',
					),
					'create_missing_terms' => array(
						'type'        => 'boolean',
						'description' => 'If true, missing string/object terms may be created while assigning terms.',
						'default'     => false,
					),
					'meta'                 => array(
						'type'        => 'object',
						'description' => 'Optional key/value map of custom field values to set on the post. Keys must be registered with register_post_meta() and have show_in_rest enabled.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'url'      => array(
						'type'        => 'string',
						'description' => 'The public permalink.',
					),
					'edit_url' => array(
						'type'        => 'string',
						'description' => 'The wp-admin edit URL.',
					),
					'terms'    => array( 'type' => 'object' ),
					'warnings' => array( 'type' => 'array' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id   = vip_mcp_resolve_site_id( $input );
				$post_type = sanitize_key( $input['post_type'] ?? 'page' );
				$status    = in_array( $input['status'] ?? 'draft', array( 'draft', 'publish', 'pending', 'private' ), true ) ? $input['status'] : 'draft';

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'  => false,
						'post_id'  => 0,
						'url'      => '',
						'edit_url' => '',
						'message'  => "Site ID {$site_id} not found.",
					);
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
				$warnings = array();
				$assigned_terms = array();

				vip_mcp_switch_to_site( $site_id );
				try {
					// Validate the post type is registered on this site.
					$post_type_obj = get_post_type_object( $post_type );
					if ( ! $post_type_obj ) {
						return array(
							'success'  => false,
							'post_id'  => 0,
							'url'      => '',
							'edit_url' => '',
							'message'  => "Post type '{$post_type}' is not registered on site {$site_id}.",
						);
					}

					// Check per-site capability for this post type.
					if ( ! current_user_can( $post_type_obj->cap->edit_posts ) ) {
						return array(
							'success'  => false,
							'post_id'  => 0,
							'url'      => '',
							'edit_url' => '',
							'message'  => "You do not have permission to create {$post_type} posts on site {$site_id}.",
						);
					}
					if ( 'publish' === $status && ! current_user_can( $post_type_obj->cap->publish_posts ) ) {
						return array(
							'success'  => false,
							'post_id'  => 0,
							'url'      => '',
							'edit_url' => '',
							'message'  => "You do not have permission to publish {$post_type} posts on site {$site_id}. Try creating as draft instead.",
						);
					}

					// Validate author_id membership inside blog context.
					if ( ! empty( $input['author_id'] ) ) {
						$author_id = (int) $input['author_id'];
						if ( ! is_user_member_of_blog( $author_id, $site_id ) ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => "User ID {$author_id} is not a member of site {$site_id}.",
							);
						}
						$postarr['post_author'] = $author_id;
					}

					if ( isset( $input['parent_id'] ) ) {
						$parent_id = max( 0, (int) $input['parent_id'] );
						if ( $parent_id > 0 ) {
							if ( ! $post_type_obj->hierarchical ) {
								return array(
									'success'  => false,
									'post_id'  => 0,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Post type '{$post_type}' does not support parent relationships.",
								);
							}
							$parent_post = get_post( $parent_id );
							if ( ! $parent_post || $parent_post->post_type !== $post_type ) {
								return array(
									'success'  => false,
									'post_id'  => 0,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Parent ID {$parent_id} is not a {$post_type} on site {$site_id}.",
								);
							}
						}
						$postarr['post_parent'] = $parent_id;
					}

					if ( isset( $input['menu_order'] ) ) {
						$postarr['menu_order'] = (int) $input['menu_order'];
					}
					if ( isset( $input['comment_status'] ) ) {
						if ( ! in_array( $input['comment_status'], array( 'open', 'closed' ), true ) ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => 'comment_status must be open or closed.',
							);
						}
						$postarr['comment_status'] = $input['comment_status'];
					}
					if ( isset( $input['ping_status'] ) ) {
						if ( ! in_array( $input['ping_status'], array( 'open', 'closed' ), true ) ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => 'ping_status must be open or closed.',
							);
						}
						$postarr['ping_status'] = $input['ping_status'];
					}

					if ( ! empty( $input['featured_media_id'] ) ) {
						$featured_media_id = (int) $input['featured_media_id'];
						$attachment = get_post( $featured_media_id );
						if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => "Featured media ID {$featured_media_id} is not an attachment on site {$site_id}.",
							);
						}
						if ( ! current_user_can( 'read_post', $featured_media_id ) ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => "You do not have permission to use attachment {$featured_media_id} on site {$site_id}.",
							);
						}
						if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
							return array(
								'success'  => false,
								'post_id'  => 0,
								'url'      => '',
								'edit_url' => '',
								'message'  => "Post type '{$post_type}' does not support featured images.",
							);
						}
					}

					// Set meta_input for any provided custom fields.
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$postarr['meta_input'] = array();
						foreach ( $input['meta'] as $meta_key => $meta_value ) {
							$sanitized_key = sanitize_key( $meta_key );
							if ( '' === $sanitized_key ) {
								continue;
							}
							// Only allow meta keys registered with show_in_rest (defence-in-depth).
							$registered = get_registered_meta_keys( 'post', $post_type );
							if ( ! isset( $registered[ $sanitized_key ] ) ) {
								$registered_all = get_registered_meta_keys( 'post' );
								if ( ! isset( $registered_all[ $sanitized_key ] ) ) {
									continue;
								}
							}
							$postarr['meta_input'][ $sanitized_key ] = $meta_value;
						}
					}

					$post_id = wp_insert_post( $postarr, true );

					if ( is_wp_error( $post_id ) ) {
						return array(
							'success'  => false,
							'post_id'  => 0,
							'url'      => '',
							'edit_url' => '',
							'message'  => $post_id->get_error_message(),
						);
					}

					// Set page template if provided and post type is hierarchical (pages, etc.).
					if ( $post_type_obj->hierarchical && ! empty( $input['template'] ) ) {
						update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
					}

					if ( ! empty( $input['featured_media_id'] ) ) {
						set_post_thumbnail( $post_id, (int) $input['featured_media_id'] );
					}

					if ( ! empty( $input['terms'] ) && is_array( $input['terms'] ) ) {
						$term_result = vip_mcp_apply_post_terms(
							(int) $post_id,
							$post_type,
							$input['terms'],
							false,
							(bool) ( $input['create_missing_terms'] ?? false )
						);
						$assigned_terms = $term_result['assigned'];
						if ( ! empty( $term_result['created'] ) ) {
							$warnings[] = array(
								'type'    => 'terms_created',
								'details' => $term_result['created'],
							);
						}
						if ( ! empty( $term_result['errors'] ) ) {
							$warnings[] = array(
								'type'    => 'term_assignment_errors',
								'details' => $term_result['errors'],
							);
						}
					}

					$url      = get_permalink( $post_id );
					$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );

				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'  => true,
					'post_id'  => (int) $post_id,
					'url'      => $url ? $url : '',
					'edit_url' => $edit_url,
					'terms'    => $assigned_terms,
					'warnings' => $warnings,
					'message'  => sprintf( '%s "%s" created on site %d (ID: %d, status: %s).', ucfirst( $post_type ), $input['title'], $site_id, $post_id, $status ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Get Post from Site',
			'description'         => 'Retrieves a post, page, or custom post type entry from a specific network sub-site by ID, including its content, status, custom fields, and edit URL.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'post_id' ) ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the post or page to retrieve.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'post_id'            => array( 'type' => 'integer' ),
					'title'              => array( 'type' => 'string' ),
					'content'            => array( 'type' => 'string' ),
					'excerpt'            => array( 'type' => 'string' ),
					'status'             => array( 'type' => 'string' ),
					'post_type'          => array( 'type' => 'string' ),
					'author_id'          => array( 'type' => 'integer' ),
					'parent_id'          => array( 'type' => 'integer' ),
					'menu_order'         => array( 'type' => 'integer' ),
					'comment_status'     => array( 'type' => 'string' ),
					'ping_status'        => array( 'type' => 'string' ),
					'slug'               => array( 'type' => 'string' ),
					'url'                => array( 'type' => 'string' ),
					'edit_url'           => array( 'type' => 'string' ),
					'template'           => array( 'type' => 'string' ),
					'featured_media_id'  => array( 'type' => 'integer' ),
					'featured_media_url' => array( 'type' => 'string' ),
					'terms'              => array( 'type' => 'object' ),
					'date'               => array( 'type' => 'string' ),
					'modified'           => array( 'type' => 'string' ),
					'meta'               => array(
						'type'        => 'object',
						'description' => 'Registered custom field values (keys registered with register_post_meta).',
					),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = vip_mcp_resolve_site_id( $input );
				$post_id = (int) $input['post_id'];

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'message' => "Site ID {$site_id} not found.",
					);
				}

				$post     = null;
				$url      = '';
				$edit_url = '';
				$template = '';
				$response = array(
					'success' => false,
					'message' => 'An unexpected error occurred.',
				);

				vip_mcp_switch_to_site( $site_id );
				try {
					$post = get_post( $post_id );

					if ( ! $post ) {
						$response = array(
							'success' => false,
							'message' => "Post ID {$post_id} not found on site {$site_id}.",
						);
					} elseif ( ! current_user_can( 'read_post', $post_id ) ) {
						$response = array(
							'success' => false,
							'message' => "You do not have permission to read post {$post_id} on site {$site_id}.",
						);
					} else {
						$url      = get_permalink( $post_id );
						$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );
						$template = get_post_meta( $post_id, '_wp_page_template', true );
						$featured_media_id = get_post_thumbnail_id( $post_id );
						$featured_media_url = $featured_media_id ? wp_get_attachment_url( $featured_media_id ) : '';
						$meta_values = vip_mcp_get_registered_meta_values( 'post', $post->post_type, $post_id );

						// Build response inside try so $post properties are safe to access.
						$response = array(
							'success'            => true,
							'post_id'            => (int) $post->ID,
							'title'              => $post->post_title,
							'content'            => $post->post_content,
							'excerpt'            => $post->post_excerpt,
							'status'             => $post->post_status,
							'post_type'          => $post->post_type,
							'author_id'          => (int) $post->post_author,
							'parent_id'          => (int) $post->post_parent,
							'menu_order'         => (int) $post->menu_order,
							'comment_status'     => $post->comment_status,
							'ping_status'        => $post->ping_status,
							'slug'               => $post->post_name,
							'url'                => $url ? $url : '',
							'edit_url'           => $edit_url,
							'template'           => $template ? $template : '',
							'featured_media_id'  => $featured_media_id ? (int) $featured_media_id : 0,
							'featured_media_url' => $featured_media_url ? $featured_media_url : '',
							'terms'              => vip_mcp_get_post_terms_response( $post_id, $post->post_type ),
							'date'               => $post->post_date,
							'modified'           => $post->post_modified,
							'meta'               => $meta_values,
							'message'            => 'Post retrieved successfully.',
						);
					}
				} finally {
					vip_mcp_restore_site();
				}

				return $response;
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Update Post on Site',
			'description'         => 'Updates the title, content, status, custom fields, or other fields of an existing post, page, '
				. 'or custom post type entry on a specific network sub-site. Only include fields you want to change.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'post_id' ) ),
				'properties' => array(
					'site_id'              => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'post_id'              => array(
						'type'        => 'integer',
						'description' => 'The ID of the post or page to update.',
					),
					'title'                => array(
						'type'        => 'string',
						'description' => 'New title.',
					),
					'content'              => array(
						'type'        => 'string',
						'description' => 'New body content. Accepts raw HTML or Gutenberg block markup.',
					),
					'excerpt'              => array(
						'type'        => 'string',
						'description' => 'New excerpt / summary.',
					),
					'status'               => array(
						'type'        => 'string',
						'description' => 'New publishing status.',
						'enum'        => array( 'draft', 'publish', 'pending', 'private', 'trash' ),
					),
					'slug'                 => array(
						'type'        => 'string',
						'description' => 'New URL slug.',
					),
					'author_id'            => array(
						'type'        => 'integer',
						'description' => 'New author user ID. User must be a member of the site.',
					),
					'parent_id'            => array(
						'type'        => 'integer',
						'description' => 'New parent post/page ID. Use 0 to clear. Only valid for hierarchical post types.',
					),
					'menu_order'           => array(
						'type'        => 'integer',
						'description' => 'New menu order value.',
					),
					'comment_status'       => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'New comment status.',
					),
					'ping_status'          => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => 'New ping status.',
					),
					'template'             => array(
						'type'        => 'string',
						'description' => 'New page template filename. Pass an empty string to reset to the default template. Only applicable to hierarchical post types.',
					),
					'featured_media_id'    => array(
						'type'        => 'integer',
						'description' => 'Attachment ID to set as featured image. Use 0 to clear.',
					),
					'terms'                => array(
						'type'        => 'object',
						'description' => 'Optional taxonomy-to-terms map to assign, e.g. {"category":[12,"news"],"post_tag":["featured"]}.',
					),
					'append_terms'         => array(
						'type'        => 'boolean',
						'description' => 'If true, terms are appended instead of replacing existing assignments.',
						'default'     => false,
					),
					'create_missing_terms' => array(
						'type'        => 'boolean',
						'description' => 'If true, missing string/object terms may be created while assigning terms.',
						'default'     => false,
					),
					'meta'                 => array(
						'type'        => 'object',
						'description' => 'Key/value map of custom field values to update. Keys must be registered with register_post_meta() and have show_in_rest enabled.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'url'      => array( 'type' => 'string' ),
					'edit_url' => array( 'type' => 'string' ),
					'terms'    => array( 'type' => 'object' ),
					'warnings' => array( 'type' => 'array' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = vip_mcp_resolve_site_id( $input );
				$post_id = (int) $input['post_id'];

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'  => false,
						'post_id'  => $post_id,
						'url'      => '',
						'edit_url' => '',
						'message'  => "Site ID {$site_id} not found.",
					);
				}

				$url      = '';
				$edit_url = '';

				vip_mcp_switch_to_site( $site_id );
				try {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'url'      => '',
							'edit_url' => '',
							'message'  => "Post ID {$post_id} not found on site {$site_id}.",
						);
					}

					// Check per-site capability for this specific post.
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'url'      => '',
							'edit_url' => '',
							'message'  => "You do not have permission to edit post {$post_id} on site {$site_id}.",
						);
					}

					$post_type_obj = get_post_type_object( $post->post_type );
					if ( ! $post_type_obj ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'url'      => '',
							'edit_url' => '',
							'message'  => "Post type '{$post->post_type}' is not registered on site {$site_id}.",
						);
					}

					// Check publish capability if changing status to publish.
					if ( isset( $input['status'] ) && 'publish' === $input['status'] && 'publish' !== $post->post_status ) {
						if ( ! current_user_can( $post_type_obj->cap->publish_posts ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => "You do not have permission to publish this {$post->post_type} on site {$site_id}.",
							);
						}
					}

					$postarr = array( 'ID' => $post_id );
					$updated = array();
					$warnings = array();

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
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => "Invalid status '{$input['status']}'. Must be one of: " . implode( ', ', $allowed_statuses ) . '.',
							);
						}
						$postarr['post_status'] = $input['status'];
						$updated[] = 'status';
					}
					if ( isset( $input['slug'] ) ) {
						$postarr['post_name'] = sanitize_title( $input['slug'] );
						$updated[] = 'slug';
					}
					if ( isset( $input['author_id'] ) ) {
						$author_id = (int) $input['author_id'];
						if ( ! is_user_member_of_blog( $author_id, $site_id ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => "User ID {$author_id} is not a member of site {$site_id}.",
							);
						}
						if ( (int) $post->post_author !== $author_id && ! current_user_can( $post_type_obj->cap->edit_others_posts ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => "You do not have permission to change the author of this {$post->post_type}.",
							);
						}
						$postarr['post_author'] = $author_id;
						$updated[] = 'author';
					}
					if ( isset( $input['parent_id'] ) ) {
						$parent_id = max( 0, (int) $input['parent_id'] );
						if ( $parent_id > 0 ) {
							if ( ! $post_type_obj->hierarchical ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Post type '{$post->post_type}' does not support parent relationships.",
								);
							}
							if ( $parent_id === $post_id ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => 'A post cannot be its own parent.',
								);
							}
							$parent_post = get_post( $parent_id );
							if ( ! $parent_post || $parent_post->post_type !== $post->post_type ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Parent ID {$parent_id} is not a {$post->post_type} on site {$site_id}.",
								);
							}
						}
						$postarr['post_parent'] = $parent_id;
						$updated[] = 'parent';
					}
					if ( isset( $input['menu_order'] ) ) {
						$postarr['menu_order'] = (int) $input['menu_order'];
						$updated[] = 'menu_order';
					}
					if ( isset( $input['comment_status'] ) ) {
						if ( ! in_array( $input['comment_status'], array( 'open', 'closed' ), true ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => 'comment_status must be open or closed.',
							);
						}
						$postarr['comment_status'] = $input['comment_status'];
						$updated[] = 'comment_status';
					}
					if ( isset( $input['ping_status'] ) ) {
						if ( ! in_array( $input['ping_status'], array( 'open', 'closed' ), true ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => 'ping_status must be open or closed.',
							);
						}
						$postarr['ping_status'] = $input['ping_status'];
						$updated[] = 'ping_status';
					}
					if ( isset( $input['featured_media_id'] ) ) {
						$featured_media_id = (int) $input['featured_media_id'];
						if ( $featured_media_id > 0 ) {
							$attachment = get_post( $featured_media_id );
							if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Featured media ID {$featured_media_id} is not an attachment on site {$site_id}.",
								);
							}
							if ( ! current_user_can( 'read_post', $featured_media_id ) ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => "You do not have permission to use attachment {$featured_media_id} on site {$site_id}.",
								);
							}
							if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
								return array(
									'success'  => false,
									'post_id'  => $post_id,
									'url'      => '',
									'edit_url' => '',
									'message'  => "Post type '{$post->post_type}' does not support featured images.",
								);
							}
						}
						$updated[] = 'featured_media';
					}

					if ( empty( $updated ) && ! isset( $input['template'] ) && empty( $input['meta'] ) && ! isset( $input['terms'] ) ) {
						return array(
							'success'  => false,
							'post_id'  => $post_id,
							'url'      => '',
							'edit_url' => '',
							'message'  => 'No fields provided to update.',
						);
					}

					// Only call wp_update_post() if there are actual post fields to write.
					// Calling it with only ID would touch post_modified unnecessarily.
					if ( count( $postarr ) > 1 ) {
						$result = wp_update_post( $postarr, true );

						if ( is_wp_error( $result ) ) {
							return array(
								'success'  => false,
								'post_id'  => $post_id,
								'url'      => '',
								'edit_url' => '',
								'message'  => $result->get_error_message(),
							);
						}
					}

					// Handle template separately as it is stored in post meta.
					// Only applicable to hierarchical post types — matches the guard in create-post.
					if ( isset( $input['template'] ) ) {
						if ( $post_type_obj->hierarchical ) {
							update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $input['template'] ) );
							$updated[] = 'template';
						}
					}

					if ( isset( $input['featured_media_id'] ) ) {
						$featured_media_id = (int) $input['featured_media_id'];
						if ( $featured_media_id > 0 ) {
							set_post_thumbnail( $post_id, $featured_media_id );
						} else {
							delete_post_thumbnail( $post_id );
						}
					}

					// Handle custom field updates.
					if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
						$meta_result = vip_mcp_update_registered_meta_values( 'post', $post->post_type, $post_id, $input['meta'] );
						foreach ( $meta_result['updated'] as $meta_key ) {
							$updated[] = "meta:{$meta_key}";
						}
						if ( ! empty( $meta_result['skipped'] ) ) {
							$warnings[] = array(
								'type'    => 'meta_skipped',
								'details' => $meta_result['skipped'],
							);
						}
					}

					if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
						$term_result = vip_mcp_apply_post_terms(
							$post_id,
							$post->post_type,
							$input['terms'],
							(bool) ( $input['append_terms'] ?? false ),
							(bool) ( $input['create_missing_terms'] ?? false )
						);
						if ( ! empty( $term_result['assigned'] ) ) {
							$updated[] = 'terms';
						}
						if ( ! empty( $term_result['created'] ) ) {
							$warnings[] = array(
								'type'    => 'terms_created',
								'details' => $term_result['created'],
							);
						}
						if ( ! empty( $term_result['errors'] ) ) {
							$warnings[] = array(
								'type'    => 'term_assignment_errors',
								'details' => $term_result['errors'],
							);
						}
					}

					$url      = get_permalink( $post_id );
					$edit_url = get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' );

				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'  => true,
					'post_id'  => (int) $post_id,
					'url'      => $url ? $url : '',
					'edit_url' => $edit_url,
					'terms'    => vip_mcp_get_post_terms_response( $post_id, $post->post_type ),
					'warnings' => $warnings,
					'message'  => sprintf( 'Post %d on site %d updated. Fields changed: %s.', $post_id, $site_id, implode( ', ', $updated ) ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'List Posts on Site',
			'description'         => 'Returns a paginated list of posts, pages, or custom post type entries on a specific network '
				. 'sub-site, with optional filtering by status or search term. '
				. 'Use list-post-types to discover available post types.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id(),
				'properties' => array(
					'site_id'       => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'post_type'     => array(
						'type'        => 'string',
						'description' => 'Post type to list. Use "post", "page", or any registered custom post type slug (default: "page"). Use list-post-types to discover available types.',
						'default'     => 'page',
					),
					'status'        => array(
						'type'        => 'string',
						'description' => 'Filter by publishing status. Use "any" for all statuses (default: "any").',
						'enum'        => array( 'any', 'draft', 'publish', 'pending', 'private', 'trash' ),
						'default'     => 'any',
					),
					'search'        => array(
						'type'        => 'string',
						'description' => 'Optional keyword to search in post titles and content.',
					),
					'author_id'     => array(
						'type'        => 'integer',
						'description' => 'Optional author user ID filter.',
					),
					'parent_id'     => array(
						'type'        => 'integer',
						'description' => 'Optional parent post/page ID filter. Use 0 for top-level hierarchical content.',
					),
					'taxonomy'      => array(
						'type'        => 'string',
						'description' => 'Optional taxonomy slug for term filtering.',
					),
					'term_id'       => array(
						'type'        => 'integer',
						'description' => 'Optional term ID filter. Requires taxonomy.',
					),
					'term_slug'     => array(
						'type'        => 'string',
						'description' => 'Optional term slug filter. Requires taxonomy.',
					),
					'include_terms' => array(
						'type'        => 'boolean',
						'description' => 'If true, include assigned terms for each returned post.',
						'default'     => false,
					),
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'          => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'read' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id   = vip_mcp_resolve_site_id( $input );
				$post_type = sanitize_key( $input['post_type'] ?? 'page' );
				$allowed_statuses = array( 'any', 'draft', 'publish', 'pending', 'private', 'trash' );
				$status = in_array( $input['status'] ?? 'any', $allowed_statuses, true )
					? ( $input['status'] ?? 'any' )
					: 'any';
				$per_page  = min( max( (int) ( $input['per_page'] ?? 20 ), 1 ), 100 );
				$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'posts'       => array(),
						'total'       => 0,
						'total_pages' => 0,
						'message'     => "Site ID {$site_id} not found.",
					);
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
				if ( ! empty( $input['author_id'] ) ) {
					$query_args['author'] = (int) $input['author_id'];
				}
				if ( isset( $input['parent_id'] ) ) {
					$query_args['post_parent'] = max( 0, (int) $input['parent_id'] );
				}

				$result = array();
				$total  = 0;

				vip_mcp_switch_to_site( $site_id );
				try {
					// Validate the post type is registered on this site.
					$post_type_obj = get_post_type_object( $post_type );
					if ( ! $post_type_obj ) {
						return array(
							'posts'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => "Post type '{$post_type}' is not registered on site {$site_id}.",
						);
					}

					// Check per-site read capability for this post type.
					if ( ! current_user_can( $post_type_obj->cap->read ) ) {
						return array(
							'posts'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => "You do not have permission to list {$post_type} posts on site {$site_id}.",
						);
					}

					if ( ! empty( $input['taxonomy'] ) ) {
						$taxonomy = sanitize_key( $input['taxonomy'] );
						$taxonomy_obj = get_taxonomy( $taxonomy );
						if ( ! $taxonomy_obj ) {
							return array(
								'posts'       => array(),
								'total'       => 0,
								'total_pages' => 0,
								'message'     => "Taxonomy '{$taxonomy}' is not registered on site {$site_id}.",
							);
						}
						if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
							return array(
								'posts'       => array(),
								'total'       => 0,
								'total_pages' => 0,
								'message'     => "Taxonomy '{$taxonomy}' is not assigned to post type '{$post_type}'.",
							);
						}

						$tax_query = array(
							'taxonomy' => $taxonomy,
						);
						if ( ! empty( $input['term_id'] ) ) {
							$tax_query['field'] = 'term_id';
							$tax_query['terms'] = array( (int) $input['term_id'] );
						} elseif ( ! empty( $input['term_slug'] ) ) {
							$tax_query['field'] = 'slug';
							$tax_query['terms'] = array( sanitize_title( $input['term_slug'] ) );
						} else {
							return array(
								'posts'       => array(),
								'total'       => 0,
								'total_pages' => 0,
								'message'     => 'Provide term_id or term_slug when filtering by taxonomy.',
							);
						}
						$query_args['tax_query'] = array( $tax_query );
					}

					$query  = new WP_Query( $query_args );
					$posts  = $query->posts;
					$total  = (int) $query->found_posts;

					foreach ( $posts as $post ) {
						$row = array(
							'post_id'           => (int) $post->ID,
							'title'             => $post->post_title,
							'post_type'         => $post->post_type,
							'status'            => $post->post_status,
							'slug'              => $post->post_name,
							'author_id'         => (int) $post->post_author,
							'parent_id'         => (int) $post->post_parent,
							'menu_order'        => (int) $post->menu_order,
							'featured_media_id' => (int) get_post_thumbnail_id( $post->ID ),
							'date'              => $post->post_date,
							'modified'          => $post->post_modified,
							'url'               => get_permalink( $post->ID ),
							'edit_url'          => get_admin_url( $site_id, 'post.php?post=' . $post->ID . '&action=edit' ),
						);
						if ( $input['include_terms'] ?? false ) {
							$row['terms'] = vip_mcp_get_post_terms_response( (int) $post->ID, $post->post_type );
						}
						$result[] = $row;
					}
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'posts'       => $result,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 15. List media attachments on a site.
 */
function vip_mcp_register_ability_list_media(): void {
	wp_register_ability(
		'vip-multisite/list-media',
		array(
			'label'               => 'List Media on Site',
			'description'         => 'Returns a paginated list of media attachments on a site, including URLs, alt text, captions, descriptions, metadata, and parent post IDs.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id(),
				'properties' => array(
					'site_id'   => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => 'Optional keyword to search media titles and content.',
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => 'Optional MIME type filter, e.g. image/jpeg or image.',
					),
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'Optional parent post ID filter.',
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'site_id'     => array( 'type' => 'integer' ),
					'media'       => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'upload_files' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id  = vip_mcp_resolve_site_id( $input );
				$per_page = min( max( (int) ( $input['per_page'] ?? 20 ), 1 ), 100 );
				$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'     => false,
						'site_id'     => $site_id,
						'media'       => array(),
						'total'       => 0,
						'total_pages' => 0,
						'message'     => "Site ID {$site_id} not found.",
					);
				}

				$query_args = array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'date',
					'order'          => 'DESC',
				);

				if ( ! empty( $input['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $input['search'] );
				}
				if ( ! empty( $input['mime_type'] ) ) {
					$query_args['post_mime_type'] = sanitize_mime_type( $input['mime_type'] );
				}
				if ( isset( $input['parent_id'] ) ) {
					$query_args['post_parent'] = max( 0, (int) $input['parent_id'] );
				}

				$media = array();
				$total = 0;

				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'upload_files' ) ) {
						return array(
							'success'     => false,
							'site_id'     => $site_id,
							'media'       => array(),
							'total'       => 0,
							'total_pages' => 0,
							'message'     => "You do not have permission to list media on site {$site_id}.",
						);
					}

					$query = new WP_Query( $query_args );
					$total = (int) $query->found_posts;
					foreach ( $query->posts as $attachment ) {
						$media[] = vip_mcp_format_media_response( $attachment, $site_id );
					}
				} finally {
					vip_mcp_restore_site();
				}

				return array(
					'success'     => true,
					'site_id'     => $site_id,
					'media'       => $media,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'message'     => sprintf( 'Found %d media item(s) on site %d.', $total, $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 16. Get a media attachment.
 */
function vip_mcp_register_ability_get_media(): void {
	wp_register_ability(
		'vip-multisite/get-media',
		array(
			'label'               => 'Get Media from Site',
			'description'         => 'Retrieves a media attachment by ID, including source URL, alt text, caption, description, and metadata.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'attachment_id' ) ),
				'properties' => array(
					'site_id'       => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'attachment_id' => array( 'type' => 'integer' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'site_id' => array( 'type' => 'integer' ),
					'media'   => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'upload_files' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id       = vip_mcp_resolve_site_id( $input );
				$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'media'   => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'upload_files' ) ) {
						return array(
							'success' => false,
							'site_id' => $site_id,
							'media'   => array(),
							'message' => "You do not have permission to read media on site {$site_id}.",
						);
					}

					$attachment = get_post( $attachment_id );
					if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
						return array(
							'success' => false,
							'site_id' => $site_id,
							'media'   => array(),
							'message' => "Attachment ID {$attachment_id} not found on site {$site_id}.",
						);
					}
					if ( ! current_user_can( 'read_post', $attachment_id ) ) {
						return array(
							'success' => false,
							'site_id' => $site_id,
							'media'   => array(),
							'message' => "You do not have permission to read attachment {$attachment_id} on site {$site_id}.",
						);
					}

					return array(
						'success' => true,
						'site_id' => $site_id,
						'media'   => vip_mcp_format_media_response( $attachment, $site_id ),
						'message' => 'Media item retrieved successfully.',
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 17. Copy one media attachment from a source site to a target site.
 */
function vip_mcp_register_ability_copy_media_to_site(): void {
	wp_register_ability(
		'vip-multisite/copy-media-to-site',
		array(
			'label'               => 'Copy Media to Site',
			'description'         => 'Copies a media attachment from one site to another, preserving title, alt text, caption, '
				. 'description, MIME type, and generated image metadata. Use the returned source_url/target_url map when rewriting copied content.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => is_multisite() ? array( 'source_site_id', 'target_site_id', 'attachment_id' ) : array( 'attachment_id' ),
				'properties' => array(
					'source_site_id' => array(
						'type'        => 'integer',
						'description' => 'Source site ID. Required on Multisite; defaults to current site on single-site.',
					),
					'target_site_id' => array(
						'type'        => 'integer',
						'description' => 'Target site ID. Required on Multisite; defaults to current site on single-site.',
					),
					'attachment_id'  => array( 'type' => 'integer' ),
					'parent_post_id' => array(
						'type'        => 'integer',
						'description' => 'Optional target-site parent post ID for the copied attachment.',
					),
					'title'          => array(
						'type'        => 'string',
						'description' => 'Optional replacement title for the copied attachment.',
					),
					'alt'            => array(
						'type'        => 'string',
						'description' => 'Optional replacement alt text.',
					),
					'caption'        => array(
						'type'        => 'string',
						'description' => 'Optional replacement caption.',
					),
					'description'    => array(
						'type'        => 'string',
						'description' => 'Optional replacement description.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'              => array( 'type' => 'boolean' ),
					'source_site_id'       => array( 'type' => 'integer' ),
					'target_site_id'       => array( 'type' => 'integer' ),
					'source_attachment_id' => array( 'type' => 'integer' ),
					'target_attachment_id' => array( 'type' => 'integer' ),
					'source_url'           => array( 'type' => 'string' ),
					'target_url'           => array( 'type' => 'string' ),
					'url_map'              => array( 'type' => 'object' ),
					'media'                => array( 'type' => 'object' ),
					'message'              => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'upload_files' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$source_site_id = isset( $input['source_site_id'] ) ? (int) $input['source_site_id'] : get_current_blog_id();
				$target_site_id = isset( $input['target_site_id'] ) ? (int) $input['target_site_id'] : get_current_blog_id();
				$attachment_id  = (int) ( $input['attachment_id'] ?? 0 );

				$empty = array(
					'success'              => false,
					'source_site_id'       => $source_site_id,
					'target_site_id'       => $target_site_id,
					'source_attachment_id' => $attachment_id,
					'target_attachment_id' => 0,
					'source_url'           => '',
					'target_url'           => '',
					'url_map'              => array(),
					'media'                => array(),
					'message'              => '',
				);

				if ( ! vip_mcp_validate_site( $source_site_id ) ) {
					$empty['message'] = "Source site ID {$source_site_id} not found.";
					return $empty;
				}
				if ( ! vip_mcp_validate_site( $target_site_id ) ) {
					$empty['message'] = "Target site ID {$target_site_id} not found.";
					return $empty;
				}

				$source = array();
				vip_mcp_switch_to_site( $source_site_id );
				try {
					$attachment = get_post( $attachment_id );
					if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
						$empty['message'] = "Attachment ID {$attachment_id} not found on source site {$source_site_id}.";
						return $empty;
					}
					if ( ! current_user_can( 'read_post', $attachment_id ) ) {
						$empty['message'] = "You do not have permission to read attachment {$attachment_id} on source site {$source_site_id}.";
						return $empty;
					}

					$source_file = get_attached_file( $attachment_id );
					if ( ! $source_file || ! is_readable( $source_file ) ) {
						$empty['message'] = "Source file for attachment {$attachment_id} is not readable.";
						return $empty;
					}

					$source_url = wp_get_attachment_url( $attachment_id );
					$source     = array(
						'file'        => $source_file,
						'url'         => $source_url ? $source_url : '',
						'title'       => $attachment->post_title,
						'caption'     => $attachment->post_excerpt,
						'description' => $attachment->post_content,
						'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
						'mime_type'   => $attachment->post_mime_type,
					);
				} finally {
					vip_mcp_restore_site();
				}

				vip_mcp_switch_to_site( $target_site_id );
				try {
					if ( ! current_user_can( 'upload_files' ) ) {
						$empty['message'] = "You do not have permission to upload media on target site {$target_site_id}.";
						return $empty;
					}

					$parent_post_id = ! empty( $input['parent_post_id'] ) ? (int) $input['parent_post_id'] : 0;
					if ( $parent_post_id > 0 ) {
						$parent_post = get_post( $parent_post_id );
						if ( ! $parent_post ) {
							$empty['message'] = "Parent post {$parent_post_id} was not found on target site {$target_site_id}.";
							return $empty;
						}
						if ( ! current_user_can( 'edit_post', $parent_post_id ) ) {
							$empty['message'] = "You do not have permission to attach media to post {$parent_post_id} on target site {$target_site_id}.";
							return $empty;
						}
					}

					if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
						require_once ABSPATH . 'wp-admin/includes/image.php';
					}
					if ( ! function_exists( 'wp_mkdir_p' ) ) {
						require_once ABSPATH . 'wp-admin/includes/file.php';
					}

					$upload_dir = wp_upload_dir();
					if ( ! empty( $upload_dir['error'] ) ) {
						$empty['message'] = $upload_dir['error'];
						return $empty;
					}

					if ( ! wp_mkdir_p( $upload_dir['path'] ) ) {
						$empty['message'] = 'Could not create target upload directory.';
						return $empty;
					}

					$filename    = wp_unique_filename( $upload_dir['path'], sanitize_file_name( wp_basename( $source['file'] ) ) );
					$target_file = trailingslashit( $upload_dir['path'] ) . $filename;
					if ( ! copy( $source['file'], $target_file ) ) {
						$empty['message'] = 'Could not copy source file to target uploads directory.';
						return $empty;
					}

					$filetype = wp_check_filetype( $filename, null );
					$title    = isset( $input['title'] ) ? wp_strip_all_tags( $input['title'] ) : $source['title'];
					$caption  = isset( $input['caption'] ) ? sanitize_textarea_field( $input['caption'] ) : $source['caption'];
					$description = isset( $input['description'] ) ? vip_mcp_sanitize_content( $input['description'] ) : $source['description'];
					$alt      = isset( $input['alt'] ) ? sanitize_text_field( $input['alt'] ) : $source['alt'];

					$target_attachment_id = wp_insert_attachment(
						array(
							'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
							'post_mime_type' => $filetype['type'] ? $filetype['type'] : $source['mime_type'],
							'post_title'     => $title,
							'post_content'   => $description,
							'post_excerpt'   => $caption,
							'post_status'    => 'inherit',
						),
						$target_file,
						$parent_post_id,
						true
					);

					if ( is_wp_error( $target_attachment_id ) ) {
						wp_delete_file( $target_file );
						$empty['message'] = $target_attachment_id->get_error_message();
						return $empty;
					}

					$metadata = wp_generate_attachment_metadata( $target_attachment_id, $target_file );
					if ( ! empty( $metadata ) ) {
						wp_update_attachment_metadata( $target_attachment_id, $metadata );
					}
					if ( '' !== $alt ) {
						update_post_meta( $target_attachment_id, '_wp_attachment_image_alt', $alt );
					}

					$target_attachment = get_post( $target_attachment_id );
					$target_url        = wp_get_attachment_url( $target_attachment_id );
					$target_url        = $target_url ? $target_url : '';

					return array(
						'success'              => true,
						'source_site_id'       => $source_site_id,
						'target_site_id'       => $target_site_id,
						'source_attachment_id' => $attachment_id,
						'target_attachment_id' => (int) $target_attachment_id,
						'source_url'           => $source['url'],
						'target_url'           => $target_url,
						'url_map'              => $source['url'] && $target_url ? array( $source['url'] => $target_url ) : array(),
						'media'                => $target_attachment instanceof WP_Post ? vip_mcp_format_media_response( $target_attachment, $target_site_id ) : array(),
						'message'              => sprintf( 'Copied attachment %d from site %d to site %d as attachment %d.', $attachment_id, $source_site_id, $target_site_id, $target_attachment_id ),
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}

/**
 * 18. Rewrite copied content links using an explicit source => target map.
 */
function vip_mcp_register_ability_rewrite_content_links(): void {
	wp_register_ability(
		'vip-multisite/rewrite-content-links',
		array(
			'label'               => 'Rewrite Content Links',
			'description'         => 'Rewrites exact URLs or strings in supplied content or in an existing post using an explicit '
				. 'source-to-target map. Use this after copying media and posts to repair image URLs, internal links, and block attributes.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'url_map' ),
				'properties' => array(
					'site_id'     => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Defaults to current site on single-site; recommended on Multisite.',
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'Optional post ID whose content should be read and optionally updated.',
					),
					'content'     => array(
						'type'        => 'string',
						'description' => 'Optional raw content to rewrite. If omitted and post_id is provided, the post content is used.',
					),
					'url_map'     => array(
						'type'        => 'object',
						'description' => 'Exact source-to-target replacement map, e.g. {"https://source/uploads/a.jpg":"https://target/uploads/a.jpg"}.',
					),
					'update_post' => array(
						'type'        => 'boolean',
						'description' => 'If true, write the rewritten content back to post_id.',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'site_id'      => array( 'type' => 'integer' ),
					'post_id'      => array( 'type' => 'integer' ),
					'content'      => array( 'type' => 'string' ),
					'replacements' => array( 'type' => 'integer' ),
					'details'      => array( 'type' => 'array' ),
					'updated_post' => array( 'type' => 'boolean' ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id     = vip_mcp_resolve_site_id( $input );
				$post_id     = ! empty( $input['post_id'] ) ? (int) $input['post_id'] : 0;
				$update_post = (bool) ( $input['update_post'] ?? false );
				$url_map     = $input['url_map'] ?? array();

				if ( ! is_array( $url_map ) || empty( $url_map ) ) {
					return array(
						'success'      => false,
						'site_id'      => $site_id,
						'post_id'      => $post_id,
						'content'      => '',
						'replacements' => 0,
						'details'      => array(),
						'updated_post' => false,
						'message'      => 'url_map must be a non-empty object.',
					);
				}
				if ( $update_post && $post_id <= 0 ) {
					return array(
						'success'      => false,
						'site_id'      => $site_id,
						'post_id'      => 0,
						'content'      => '',
						'replacements' => 0,
						'details'      => array(),
						'updated_post' => false,
						'message'      => 'post_id is required when update_post is true.',
					);
				}
				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success'      => false,
						'site_id'      => $site_id,
						'post_id'      => $post_id,
						'content'      => '',
						'replacements' => 0,
						'details'      => array(),
						'updated_post' => false,
						'message'      => "Site ID {$site_id} not found.",
					);
				}

				vip_mcp_switch_to_site( $site_id );
				try {
					$content = isset( $input['content'] ) ? (string) $input['content'] : '';
					$post    = null;

					if ( $post_id > 0 ) {
						$post = get_post( $post_id );
						if ( ! $post ) {
							return array(
								'success'      => false,
								'site_id'      => $site_id,
								'post_id'      => $post_id,
								'content'      => '',
								'replacements' => 0,
								'details'      => array(),
								'updated_post' => false,
								'message'      => "Post ID {$post_id} not found on site {$site_id}.",
							);
						}
						if ( ! current_user_can( $update_post ? 'edit_post' : 'read_post', $post_id ) ) {
							return array(
								'success'      => false,
								'site_id'      => $site_id,
								'post_id'      => $post_id,
								'content'      => '',
								'replacements' => 0,
								'details'      => array(),
								'updated_post' => false,
								'message'      => "You do not have permission to access post {$post_id} on site {$site_id}.",
							);
						}
						if ( ! isset( $input['content'] ) ) {
							$content = $post->post_content;
						}
					}

					if ( '' === $content ) {
						return array(
							'success'      => false,
							'site_id'      => $site_id,
							'post_id'      => $post_id,
							'content'      => '',
							'replacements' => 0,
							'details'      => array(),
							'updated_post' => false,
							'message'      => 'Provide content or post_id.',
						);
					}

					$result       = vip_mcp_rewrite_content_with_map( $content, $url_map );
					$updated_post = false;

					if ( $update_post && $post instanceof WP_Post ) {
						$update_result = wp_update_post(
							array(
								'ID'           => $post_id,
								'post_content' => vip_mcp_sanitize_content( $result['content'] ),
							),
							true
						);
						if ( is_wp_error( $update_result ) ) {
							return array(
								'success'      => false,
								'site_id'      => $site_id,
								'post_id'      => $post_id,
								'content'      => $result['content'],
								'replacements' => $result['replacements'],
								'details'      => $result['details'],
								'updated_post' => false,
								'message'      => $update_result->get_error_message(),
							);
						}
						$updated_post = true;
					}

					return array(
						'success'      => true,
						'site_id'      => $site_id,
						'post_id'      => $post_id,
						'content'      => $result['content'],
						'replacements' => $result['replacements'],
						'details'      => $result['details'],
						'updated_post' => $updated_post,
						'message'      => sprintf( 'Applied %d replacement(s)%s.', $result['replacements'], $updated_post ? " and updated post {$post_id}" : '' ),
					);
				} finally {
					vip_mcp_restore_site();
				}
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Get Site Option',
			'description'         => 'Reads one or more WordPress options (get_option) from a specific network sub-site. '
				. 'Useful for inspecting settings such as the front page mode, assigned pages, site title, and more.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'option_names' ) ),
				'properties' => array(
					'site_id'      => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
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
			'output_schema'       => array(
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
				return current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id      = vip_mcp_resolve_site_id( $input );
				$option_names = $input['option_names'] ?? array();

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'options' => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				if ( empty( $option_names ) || ! is_array( $option_names ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'options' => array(),
						'message' => 'option_names must be a non-empty array.',
					);
				}

				// Sanitize each key and cap at 20.
				$option_names = array_slice(
					array_map( 'vip_mcp_sanitize_option_key', $option_names ),
					0,
					20
				);

				// Blocklist keys that should never be exposed via MCP — credentials and auth secrets.
				// @todo: wrap with apply_filters( 'vip_mcp_blocked_read_options', $read_blocked )
				// if extensibility is needed. Hardcoded is the safer default for now.
				// Note: auth_key etc. are usually PHP constants, not DB options, but block them defensively.
				$read_blocked = array(
					'auth_key',
					'secure_auth_key',
					'logged_in_key',
					'nonce_key',
					'auth_salt',
					'secure_auth_salt',
					'logged_in_salt',
					'nonce_salt',
					'mailserver_pass',
					'mailserver_login',
					'mailserver_url',
					'mailserver_port',
					'db_password',
					'db_user', // Defensive — not typically stored as options.
				);

				$result = array();
				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'manage_options' ) ) {
						return array(
							'success' => false,
							'site_id' => $site_id,
							'options' => array(),
							'message' => "You do not have permission to read options on site {$site_id}.",
						);
					}

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
					vip_mcp_restore_site();
				}

				return array(
					'success' => true,
					'site_id' => $site_id,
					'options' => $result,
					'message' => sprintf( 'Retrieved %d option(s) from site %d.', count( $result ), $site_id ),
				);
			},
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
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
			'label'               => 'Update Site Option',
			'description'         => 'Writes one or more WordPress options (update_option) on a specific network sub-site. '
				. 'Only allowlisted options may be written — this includes common reading, discussion, permalink, '
				. 'media, and homepage settings (show_on_front, page_on_front, page_for_posts). '
				. 'Any key not on the allowlist is skipped with a reason.',
			'category'            => 'vip-multisite',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => vip_mcp_required_with_site_id( array( 'options' ) ),
				'properties' => array(
					'site_id' => array(
						'type'        => 'integer',
						'description' => 'The ID of the target site. Required on Multisite; defaults to current site on single-site.',
					),
					'options' => array(
						'type'        => 'object',
						'description' => 'Key/value map of options to set. Values are cast to the appropriate type per option. '
							. 'Common keys: show_on_front ("posts" or "page"), page_on_front (page ID or title), '
							. 'page_for_posts (page ID or title), blogname, blogdescription, posts_per_page, '
							. 'default_comment_status, permalink_structure.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'site_id' => array( 'type' => 'integer' ),
					'updated' => array(
						'type'        => 'array',
						'description' => 'Option keys that were successfully updated.',
					),
					'skipped' => array(
						'type'        => 'array',
						'description' => 'Option keys that were skipped (blocked or value unchanged).',
					),
					'errors'  => array(
						'type'        => 'array',
						'description' => 'Option keys that failed with a reason.',
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				$site_id = vip_mcp_resolve_site_id( $input );
				$options = $input['options'] ?? array();

				if ( ! vip_mcp_validate_site( $site_id ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'updated' => array(),
						'skipped' => array(),
						'errors'  => array(),
						'message' => "Site ID {$site_id} not found.",
					);
				}

				if ( empty( $options ) || ! is_array( $options ) ) {
					return array(
						'success' => false,
						'site_id' => $site_id,
						'updated' => array(),
						'skipped' => array(),
						'errors'  => array(),
						'message' => 'options must be a non-empty key/value object.',
					);
				}

				// Explicit allowlist — only these options may be written via MCP.
				// @todo: wrap with apply_filters( 'vip_mcp_allowed_write_options', $allowed_options )
				// if client teams need to safely expose additional options.
				// Any option not listed here is silently skipped with a reason.
				// To expose additional options, add them to this list after review.
				$allowed_options = array(
					// Identity.
					'blogname',
					'blogdescription',
					'WPLANG',
					// Homepage settings.
					'show_on_front',
					'page_on_front',
					'page_for_posts',
					// Reading.
					'posts_per_page',
					'posts_per_rss',
					'rss_use_excerpt',
					'blog_public',
					// Writing / formats.
					'default_category',
					'default_post_format',
					'default_pingback_flag',
					// Discussion.
					'default_comment_status',
					'default_ping_status',
					'require_name_email',
					'comment_registration',
					'close_comments_for_old_posts',
					'close_comments_days_old',
					'thread_comments',
					'thread_comments_depth',
					'page_comments',
					'comments_per_page',
					'default_comments_page',
					'comment_order',
					'comments_notify',
					'moderation_notify',
					'comment_moderation',
					'comment_whitelist',
					'comment_max_links',
					// Date / time.
					'date_format',
					'time_format',
					'start_of_week',
					'timezone_string',
					'gmt_offset',
					// Permalinks.
					'permalink_structure',
					'category_base',
					'tag_base',
					// Media.
					'thumbnail_size_w',
					'thumbnail_size_h',
					'thumbnail_crop',
					'medium_size_w',
					'medium_size_h',
					'large_size_w',
					'large_size_h',
					'uploads_use_yearmonth_folders',
				);

				// Options that accept a page ID — also support resolution by post title.
				$page_id_options = array( 'page_on_front', 'page_for_posts' );

				$updated = array();
				$skipped = array();
				$errors  = array();

				vip_mcp_switch_to_site( $site_id );
				try {
					if ( ! current_user_can( 'manage_options' ) ) {
						return array(
							'success' => false,
							'site_id' => $site_id,
							'updated' => array(),
							'skipped' => array(),
							'errors'  => array(),
							'message' => "You do not have permission to update options on site {$site_id}.",
						);
					}

					foreach ( $options as $key => $value ) {
						$key = vip_mcp_sanitize_option_key( $key );

						if ( empty( $key ) ) {
							$skipped[] = array(
								'key'    => $key,
								'reason' => 'Invalid option key.',
							);
							continue;
						}

						if ( ! in_array( $key, $allowed_options, true ) ) {
							$skipped[] = array(
								'key'    => $key,
								'reason' => 'Option not in allowlist. Contact a developer to add it after review.',
							);
							continue;
						}

						// For show_on_front, enforce the two valid WordPress values.
						if ( 'show_on_front' === $key ) {
							if ( ! in_array( $value, array( 'posts', 'page' ), true ) ) {
								$errors[] = array(
									'key'    => $key,
									'reason' => "Invalid value '{$value}'. Must be 'posts' or 'page'.",
								);
								continue;
							}
						}
						if ( 'WPLANG' === $key ) {
							$value = sanitize_text_field( $value );
						}

						// For page ID options, resolve a title string to a post ID if needed.
						if ( in_array( $key, $page_id_options, true ) ) {
							if ( ! is_numeric( $value ) ) {
								// Resolve by exact post title — get_page_by_title() is deprecated since WP 6.2.
								$title_query = new WP_Query(
									array(
										'post_type'      => 'page',
										'title'          => sanitize_text_field( $value ),
										'posts_per_page' => 1,
										'post_status'    => array( 'publish', 'draft', 'private' ),
										'no_found_rows'  => true,
										'update_post_meta_cache' => false,
										'update_post_term_cache' => false,
									)
								);
								$page = $title_query->posts[0] ?? null;
								if ( ! $page ) {
									$errors[] = array(
										'key'    => $key,
										'reason' => "Could not find a page with the title '{$value}' on site {$site_id}.",
									);
									continue;
								}
								$value = $page->ID;
							} else {
								$value = (int) $value;
								// Validate the page ID exists and is actually a page.
								$page = get_post( $value );
								if ( ! $page || 'page' !== $page->post_type ) {
									$errors[] = array(
										'key'    => $key,
										'reason' => "Post ID {$value} does not exist or is not a page on site {$site_id}.",
									);
									continue;
								}
							}
						}

						$result = update_option( $key, $value );

						if ( false === $result ) {
							// update_option returns false both on DB error AND when value is unchanged.
							if ( get_option( $key ) === $value ) {
								$skipped[] = array(
									'key'    => $key,
									'reason' => 'Value unchanged.',
								);
							} else {
								$errors[] = array(
									'key'    => $key,
									'reason' => 'update_option returned false — possible database error.',
								);
							}
						} else {
							$updated[] = $key;
						}
					}
				} finally {
					vip_mcp_restore_site();
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
			'meta'                => array( 'mcp' => array( 'public' => true ) ),
		)
	);
}
