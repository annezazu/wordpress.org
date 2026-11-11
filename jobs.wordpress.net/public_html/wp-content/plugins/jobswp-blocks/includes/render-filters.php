<?php
/**
 * Render filters that adjust core blocks' output to the job board's wording.
 *
 * @package jobswp-blocks
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The search heading reads "Search Results for: <term> (N)", as the classic
 * theme printed it, where core prints 'Search results for: "term"'.
 *
 * @param string $block_content Rendered heading.
 * @param array  $block         Parsed block.
 * @return string
 */
function jobswp_blocks_search_title( string $block_content, array $block ): string {
	if ( ! is_search() || empty( $block['attrs']['type'] ) || 'search' !== $block['attrs']['type'] ) {
		return $block_content;
	}

	global $wp_query;
	$found = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;

	$text = sprintf(
		/* translators: 1: search term, 2: number of results. */
		esc_html__( 'Search Results for: %1$s %2$s', 'jobswp-blocks' ),
		'<span class="jobswp-search-term">' . esc_html( get_search_query() ) . '</span>',
		'<span class="jobswp-search-count">(' . esc_html( number_format_i18n( $found ) ) . ')</span>'
	);

	return (string) preg_replace_callback(
		'#(<h[1-6][^>]*>).*?(</h[1-6]>)#s',
		static function ( array $matches ) use ( $text ): string {
			return $matches[1] . $text . $matches[2];
		},
		$block_content,
		1
	);
}
add_filter( 'render_block_core/query-title', 'jobswp_blocks_search_title', 10, 2 );

/**
 * Resolves the bound post ID when a button's URL uses a given jobswp/job-field key.
 *
 * @param array    $block          Parsed block.
 * @param WP_Block $block_instance Block instance.
 * @param string   $key            Expected binding field key.
 * @return int Post ID when the button binds that key, or 0 otherwise.
 */
function jobswp_blocks_bound_button_post_id( array $block, WP_Block $block_instance, string $key ): int {
	$binding = $block['attrs']['metadata']['bindings']['url'] ?? null;
	if ( ! is_array( $binding ) || 'jobswp/job-field' !== ( $binding['source'] ?? '' ) ) {
		return 0;
	}

	$bound_key = $binding['args']['key'] ?? '';
	if ( ! is_string( $bound_key ) || $bound_key !== $key ) {
		return 0;
	}

	return (int) ( $block_instance->context['postId'] ?? get_the_ID() );
}

/**
 * Sets attributes on the first anchor inside a rendered button block.
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string,string> $attributes    Attribute name/value pairs.
 * @return string Updated block HTML.
 */
function jobswp_blocks_set_button_anchor_attributes( string $block_content, array $attributes ): string {
	$tags = new WP_HTML_Tag_Processor( $block_content );
	if ( $tags->next_tag( 'a' ) ) {
		foreach ( $attributes as $name => $value ) {
			$tags->set_attribute( $name, $value );
		}
	}

	return $tags->get_updated_html();
}

/**
 * A button bound to a job's web application form opens it in a new tab as
 * user-supplied, unendorsed link; an email address opens the mail client.
 *
 * @param string   $block_content  Rendered button.
 * @param array    $block          Parsed block.
 * @param WP_Block $block_instance Block instance.
 * @return string
 */
function jobswp_blocks_apply_button( string $block_content, array $block, WP_Block $block_instance ): string {
	$post_id = jobswp_blocks_bound_button_post_id( $block, $block_instance, 'apply_url' );
	if ( ! $post_id ) {
		return $block_content;
	}

	if ( '' === jobswp_blocks_job_field( $post_id, 'apply_url' ) ) {
		return '';
	}
	if ( 'web' !== jobswp_blocks_job_field( $post_id, 'howtoapply_method' ) ) {
		return $block_content;
	}

	return jobswp_blocks_set_button_anchor_attributes(
		$block_content,
		array(
			'target' => '_blank',
			'rel'    => 'nofollow ugc noopener',
		)
	);
}
add_filter( 'render_block_core/button', 'jobswp_blocks_apply_button', 10, 3 );

/**
 * Hides the "Report this job" button when a post has no report URL (such as
 * unpublished submission previews or when the contact form is unavailable) and
 * marks live report links as nofollow.
 *
 * @param string   $block_content  Rendered button.
 * @param array    $block          Parsed block.
 * @param WP_Block $block_instance Block instance.
 * @return string
 */
function jobswp_blocks_report_button( string $block_content, array $block, WP_Block $block_instance ): string {
	$post_id = jobswp_blocks_bound_button_post_id( $block, $block_instance, 'report_url' );
	if ( ! $post_id ) {
		return $block_content;
	}

	if ( '' === jobswp_blocks_job_field( $post_id, 'report_url' ) ) {
		return '';
	}

	return jobswp_blocks_set_button_anchor_attributes(
		$block_content,
		array(
			'rel' => 'nofollow',
		)
	);
}
add_filter( 'render_block_core/button', 'jobswp_blocks_report_button', 10, 3 );

/**
 * Drops an empty core/buttons wrapper when its jobswp/job-field bound child
 * buttons all rendered to empty strings so hidden actions leave no layout gap.
 *
 * @param string $block_content Rendered buttons container.
 * @param array  $block         Parsed block.
 * @return string
 */
function jobswp_blocks_hide_empty_bound_buttons( string $block_content, array $block ): string {
	if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
		return $block_content;
	}

	$has_job_field_binding = false;
	foreach ( $block['innerBlocks'] as $inner_block ) {
		$binding = $inner_block['attrs']['metadata']['bindings']['url'] ?? null;
		if ( is_array( $binding ) && 'jobswp/job-field' === ( $binding['source'] ?? '' ) ) {
			$has_job_field_binding = true;
			break;
		}
	}

	if ( ! $has_job_field_binding ) {
		return $block_content;
	}

	$tags = new WP_HTML_Tag_Processor( $block_content );
	if ( ! $tags->next_tag( array( 'class_name' => 'wp-block-button__link' ) ) ) {
		return '';
	}

	return $block_content;
}
add_filter( 'render_block_core/buttons', 'jobswp_blocks_hide_empty_bound_buttons', 10, 2 );
