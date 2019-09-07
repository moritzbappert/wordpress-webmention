<?php global $comment; ?>
<label><?php esc_html_e( 'Webmention Target', 'webmention' ); ?></label>
<input type="url" class="widefat" disabled value="<?php echo get_comment_meta( $comment->comment_ID, 'webmention_target_url', true ); ?>" />
<br />

<label><?php esc_html_e( 'Webmention Target Fragment', 'webmention' ); ?></label>
<input type="text" class="widefat" disabled value="<?php echo get_comment_meta( $comment->comment_ID, 'webmention_target_fragment', true ); ?>" />
<br />

<label><?php esc_html_e( 'Webmention Source', 'webmention' ); ?></label>
<input type="url" class="widefat" disabled value="<?php echo get_comment_meta( $comment->comment_ID, 'webmention_source_url', true ); ?>" />
<br />

<label><?php esc_html_e( 'Creation Time', 'webmention' ); ?></label>
<input type="url" class="widefat" disabled value="<?php echo get_comment_meta( $comment->comment_ID, 'created', true ); ?>" />
<br />
