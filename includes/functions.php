<?php
/**
 * A wrapper for Webmention_Sender::send_webmention
 *
 * @param string $source source url
 * @param string $target target url
 *
 * @return array of results including HTTP headers
 */
function send_webmention( $source, $target ) {
	return Webmention_Sender::send_webmention( $source, $target );
}

/**
 * Return the text for a webmention form allowing customization by post_id
 *
 * @param int $post_id Post ID
 *
 */
function get_webmention_form_text( $post_id ) {
	$text = get_option( 'webmention_comment_form_text', '' );
	if ( empty( $text ) ) {
		$text = get_default_webmention_form_text();
	}
	return wp_kses_post( apply_filters( 'webmention_form_text', $text ), $post_id );
}

/**
 * Return the default text for a webmention form
 *
 * @param int $post_id Post ID
 *
 */
function get_default_webmention_form_text() {
	return __( 'To respond on your own website, enter the URL of your response which should contain a link to this post\'s permalink URL. Your response will then appear (possibly after moderation) on this page. Want to update or remove your response? Update or delete your post and re-enter your post\'s URL again. (<a href="http://indieweb.org/webmention">Learn More</a>)', 'webmention' );
}

/**
 * Check the $url to see if it is on the domain whitelist.
 *
 * @param array $author_url
 *
 * @return boolean
 */
function is_webmention_source_whitelisted( $url ) {
	return Webmention_Receiver::is_source_whitelisted( $url );
}

/**
 * Return the Number of Webmentions
 *
 * @param int $post_id The post ID (optional)
 *
 * @return int the number of Webmentions for one Post
 */
function get_webmentions_number( $post_id = 0 ) {
	$post = get_post( $post_id );

	// change this if your theme can't handle the Webmentions comment type
	$comment_type = apply_filters( 'webmention_comment_type', WEBMENTION_COMMENT_TYPE );

	$args = array(
		'post_id' => $post->ID,
		'type'    => $comment_type,
		'count'   => true,
		'status'  => 'approve',
	);

	$comments_query = new WP_Comment_Query();

	return $comments_query->query( $args );
}

/**
 * Return Webmention Endpoint
 *
 * @see https://www.w3.org/TR/webmention/#sender-discovers-receiver-webmention-endpoint
 *
 * @return string the Webmention endpoint
 */
function get_webmention_endpoint() {
	return apply_filters( 'webmention_endpoint', get_rest_url( null, '/webmention/1.0/endpoint' ) );
}

/**
 * Return Webmention process type
 *
 * @see https://www.w3.org/TR/webmention/#receiving-webmentions
 *
 * @return string the Webmention process type
 */
function get_webmention_process_type() {
	return apply_filters( 'webmention_process_type', WEBMENTION_PROCESS_TYPE );
}

/**
 * Return the post_id for a URL filtered for webmentions.
 * Allows redirecting to another id to add linkbacks to the home page or archive
 * page or taxonomy page.
 *
 * @param string $url URL
 * @param int Return 0 if no post ID found or a post ID
 *
 * @uses apply_filters calls "webmention_post_id" on the post_ID
 */
function webmention_url_to_postid( $url ) {
	if ( '/' === wp_make_link_relative( trailingslashit( $url ) ) ) {
		return apply_filters( 'webmention_post_id', get_option( 'webmention_home_mentions' ), $url );
	}

	return apply_filters( 'webmention_post_id', url_to_postid( $url ), $url );
}

function webmention_extract_domain( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	// strip leading www, if any
	return preg_replace( '/^www\./', '', $host );
}

function get_webmention_approve_domains() {
	$whitelist = get_option( 'webmention_approve_domains' );
	$whitelist = trim( $whitelist );
	$whitelist = explode( "\n", $whitelist );

	return $whitelist;
}

/**
 * Finds a Webmention server URI based on the given URL
 *
 * Checks the HTML for the rel="webmention" link and webmention headers. It does
 * a check for the webmention headers first and returns that, if available. The
 * check for the rel="webmention" has more overhead than just the header.
 * Supports backward compatability to webmention.org headers.
 *
 * @see https://www.w3.org/TR/webmention/#sender-discovers-receiver-webmention-endpoint
 *
 * @param string $url URL to ping
 *
 * @return bool|string False on failure, string containing URI on success
 */
function webmention_discover_endpoint( $url ) {
	/** @todo Should use Filter Extension or custom preg_match instead. */
	$parsed_url = wp_parse_url( $url );

	if ( ! isset( $parsed_url['host'] ) ) { // Not an URL. This should never happen.
		return false;
	}

	// do not search for a Webmention server on our own uploads
	$uploads_dir = wp_upload_dir();
	if ( 0 === strpos( $url, $uploads_dir['baseurl'] ) ) {
		return false;
	}

	$wp_version = get_bloginfo( 'version' );

	$user_agent = apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ) );
	$args       = array(
		'timeout'             => 100,
		'limit_response_size' => 1048576,
		'redirection'         => 20,
		'user-agent'          => "$user_agent; finding Webmention endpoint",
	);

	$response = wp_safe_remote_head( $url, $args );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	// check link header
	$links = wp_remote_retrieve_header( $response, 'link' );
	if ( $links ) {
		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(\.org)?\/?[\"\']?/i', $link, $result ) ) {
					return WP_Http::make_absolute_url( $result[1], $url );
				}
			}
		} else {
			if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(\.org)?\/?[\"\']?/i', $links, $result ) ) {
				return WP_Http::make_absolute_url( $result[1], $url );
			}
		}
	}

	// not an (x)html, sgml, or xml page, no use going further
	if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
		return false;
	}

	// now do a GET since we're going to look in the html headers (and we're sure its not a binary file)
	$response = wp_safe_remote_get( $url, $args );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$contents = wp_remote_retrieve_body( $response );

	// unicode to HTML entities
	$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );

	libxml_use_internal_errors( true );

	$doc = new DOMDocument();
	$doc->loadHTML( $contents );

	$xpath = new DOMXPath( $doc );

	// check <link> and <a> elements
	// checks only body>a-links
	foreach ( $xpath->query( '(//link|//a)[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
		return WP_Http::make_absolute_url( $result->value, $url );
	}

	return false;
}

if ( ! function_exists( 'wp_get_meta_tags' ) ) :
	/**
	 * Parse meta tags from source content
	 * Based on the Press This Meta Parsing Code
	 *
	 * @param string $source_content Source Content
	 *
	 * @return array meta tags
	 */
	function wp_get_meta_tags( $source_content ) {
		$meta_tags = array();

		if ( ! $source_content ) {
			return $meta_tags;
		}

		if ( preg_match_all( '/<meta [^>]+>/', $source_content, $matches ) ) {
			$items = $matches[0];
			foreach ( $items as $value ) {
				if ( preg_match( '/(property|name)="([^"]+)"[^>]+content="([^"]+)"/', $value, $matches ) ) {
					$meta_name  = $matches[2];
					$meta_value = $matches[3];
					// Sanity check. $key is usually things like 'title', 'description', 'keywords', etc.
					if ( strlen( $meta_name ) > 100 ) {
						continue;
					}
					$meta_tags[ $meta_name ] = $meta_value;
				}
			}
		}

		return $meta_tags;
	}
endif;


/* Backward compatibility for function available in version 5.1 and above */
if ( ! function_exists( 'is_avatar_comment_type' ) ) :
	function is_avatar_comment_type( $comment_type ) {
		/**
		 * Filters the list of allowed comment types for retrieving avatars.
		 *
		 * @since 3.0.0
		 *
		 * @param array $types An array of content types. Default only contains 'comment'.
		 */
		$allowed_comment_types = apply_filters( 'get_avatar_comment_types', array( 'comment' ) );

			return in_array( $comment_type, (array) $allowed_comment_types, true );
	}
endif;


/* Inverse of wp_parse_url
 *
 * Slightly modified from p3k-utils (https://github.com/aaronpk/p3k-utils)
 * Copyright 2017 Aaron Parecki, used with permission under MIT License
 *
 * @link http://php.net/parse_url
 * @param  string $parsed_url the parsed URL (wp_parse_url)
 * @return string             the final URL
 */
if ( ! function_exists( 'build_url' ) ) {
	function build_url( $parsed_url ) {
			$scheme   = ! empty( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
			$host     = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : '';
			$port     = ! empty( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
			$user     = ! empty( $parsed_url['user'] ) ? $parsed_url['user'] : '';
			$pass     = ! empty( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
			$pass     = ( $user || $pass ) ? "$pass@" : '';
			$path     = ! empty( $parsed_url['path'] ) ? $parsed_url['path'] : '';
			$query    = ! empty( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
			$fragment = ! empty( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

			return "$scheme$user$pass$host$port$path$query$fragment";
	}
}


if ( ! function_exists( 'normalize_url' ) ) {
	// Adds slash if no path is in the URL, and convert hostname to lowercase
	function normalize_url( $url ) {
			$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
				$parts['path'] = '/';
		}
		if ( isset( $parts['host'] ) ) {
				$parts['host'] = strtolower( $parts['host'] );
				return build_url( $parts );
		}
	}
}

if ( ! function_exists( 'new_linkback' ) ) {
	function new_linkback( $commentdata ) {
		// Does not work on conventional comments
		if ( ! isset( $commentdata['comment_type'] ) || in_array( $commentdata['comment_type'], array( '', 'comment' ), true ) ) {
			return new WP_Error(
				'invalid_linkback_type',
				__( 'Not a Valid Linkback Type', 'webmention' )
			);
		}

		// disable flood control
		remove_filter( 'wp_is_comment_flood', 'wp_check_comment_flood', 10 );

		$return = wp_new_comment( $commentdata, true );

		// re-add flood control
		add_filter( 'wp_is_comment_flood', 'wp_check_comment_flood', 10, 5 );

		if ( is_wp_error( $return ) ) {
			return $return;
		}
		/**
		 * Fires when a webmention is created.
		 *
		 * Mirrors comment_post and pingback_post.
		 *
		 * @param int $comment_ID Comment ID.
		 * @param array $commentdata Comment Array.
		 */
		do_action( '{$comment->comment_type}_post', $commentdata['comment_ID'], $commentdata );
		return $return;
	}
}

if ( ! function_exists( 'update_linkback' ) ) {
	function update_linkback( $commentarr ) {
		$comment_type = isset( $commentarr['comment_type'] ) ? $commentarr['comment_type'] : get_comment_type( $commentarr['comment_ID'] );

		// disable flood control
		remove_filter( 'wp_is_comment_flood', 'wp_check_comment_flood', 10 );

		$return = wp_update_comment( $commentarr );

		// re-add flood control
		add_filter( 'wp_is_comment_flood', 'wp_check_comment_flood', 10, 5 );

		if ( 1 === $return && ! in_array( $comment_type, array( '', 'comment' ), true ) ) {
			/**
			 * Fires after a webmention is updated in the database.
			 *
			 * The hook is needed as the comment_post hook uses filtered data
			 *
			 * @param int   $comment_ID The comment ID.
			 * @param array $commmentdata       Comment data.
			 */
			do_action( 'edit_{$comment_type}', $commentdata['comment_ID'], $commentdata );
		}
		return $return;
	}
}

