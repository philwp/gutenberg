<?php
/**
 * Server-side rendering of the `core/heading` block.
 *
 * @package WordPress
 */

/**
 * Adds a wp-block-heading class to the heading blocks without storing
 * it in the serialized $content.
 *
 * @param array $attributes Attributes of the block being rendered.
 * @param string $content Content of the block being rendered.
 *
 * @return string The content of the block being rendered.
 */
function block_core_heading_render( $attributes, $content ) {
	if ( ! $content ) {
		return $content;
	}
	$pattern = '/
        ^(\s*)                               # Any leading whitespace.
        <(?<tag_name>
            h[1-6]                           # The opening tag...
            (?=\s|>)                         # ...followed by a whitespace or >
                                             # ?= means a "lookahead"
        )
        (?<before_class>                     # Any attributes prior to "class"
            (?:                              # ?: is a "non-capturing group"
                (?!\sclass=["\'])[^>]        # Match all characters until ">" except when
                                             # the next character sequence is \sclass="
                                             # ?! is a "negative lookahead"
            )*
        )
	    (?:\s*                               # The class attribute, if any
	        class=(?P<quote>[\'"])           # The quote character
	        (?<class_name>.*?)               # Non-greedy match of any character
	        (\k{quote})                      # Until we find that quote character again
	    )?
        (?<after_class>[^>]*?)               # The rest of the tag
        >                                    # The closing tag
    /xm';

	return preg_replace_callback(
		$pattern,
		function ( $matches ) {
			// Parse the existing class names.
			$current_class_attr = ! empty( $matches['class_name'] ) ? $matches['class_name'] : '';

			// If wp-block-heading is already included, there's no need to add it again.
			$class_to_add = 'wp-block-heading';
			if ( preg_match( "/\b$class_to_add\b/", $current_class_attr ) ) {
				return $matches[0];
			}

			// Otherwise, let's replace the existing opening tag with a new one.
			$new_class_attr = trim( "$current_class_attr $class_to_add" );
			$quote          = ! empty( $matches['quote'] ) ? $matches['quote'] : '"';

			return "<{$matches['tag_name']}{$matches['before_class']} class={$quote}{$new_class_attr}{$quote}{$matches['after_class']}>";
		},
		$content
	);
}

/**
 * Registers the `core/heading` block on server.
 */
function register_block_core_heading() {
	register_block_type_from_metadata(
		__DIR__ . '/heading',
		array(
			'render_callback' => 'block_core_heading_render',
		)
	);
}

add_action( 'init', 'register_block_core_heading' );
