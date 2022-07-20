<?php

class WP_HTML_Updater {

	private $html;
	/**
	 * @var int
	 */
	private $parsed_characters = 0;
	/**
	 * @var string|null
	 */
	private $current_tag;
	/**
	 * @var number
	 */
	private $current_tag_name_ends_at;
	/**
	 * @var array
	 */
	private $current_tag_attributes = array();
	/**
	 * @var WP_HTML_Updater_Classnames_Set
	 */
	private $current_tag_class_names;
	/**
	 * @var array
	 */
	private $touched_attr_names = array();
	/**
	 * @var array
	 */
	public $diffs = array();
	/**
	 * @var null|string
	 */
	private $updated_html;

	public function __construct( $html ) {
		$this->html = $html;
	}

	public function find_next_tag( $tag_name, $class_name = null, $match_index = 0 ) {
		$matched = - 1;
		try {
			while ( true ) {
				// Skip through all attributes of the current tag.
				if ( $this->current_tag ) {
					do {
						$attr = $this->consume_next_attribute();
					} while ( $attr );
					$this->after_tag();
				}

				// Match the next tag.
				do {
					$matches = $this->match(
						'~<!--(?>.*?-->)|<!\[CDATA\[(?>.*?>)|<\?(?>.*?)>|<(?P<TAG_NAME>[a-z][^\x{09}\x{0a}\x{0c}\x{0d} \/>]*)~mui'
					);
					$this->move_caret_after( $matches[0] );
				} while ( empty( $matches['TAG_NAME'][0] ) );

				$this->current_tag              = $matches['TAG_NAME'][0];
				$this->current_tag_name_ends_at = $this->index_after( $matches['TAG_NAME'] );

				if ( $this->current_tag_matches( $tag_name, $class_name ) ) {
					if ( ++ $matched === $match_index ) {
						break;
					}
				}
			}
		} catch ( WP_HTML_No_Match $e ) {
			$this->finish_parsing();
		}

		return $this;
	}

	private function after_tag() {
		if ( $this->current_tag && $this->get_class_names_bag()->is_modified() ) {
			if ( $this->get_class_names_bag()->count() ) {
				$this->set_attribute( 'class', $this->current_tag_class_names . '' );
			} else {
				$this->remove_attribute( 'class' );
			}
		}
		$this->current_tag              = null;
		$this->current_tag_name_ends_at = null;
		$this->current_tag_attributes   = array();
		$this->current_tag_class_names  = null;
		$this->touched_attr_names       = array();
	}

	private function current_tag_matches( $tag_name, $class_name ) {
		if ( $tag_name && ! WP_HTML_Comparator::equals( $this->current_tag, $tag_name ) ) {
			// For debugging:
			// echo "Tag name {$tag_name} does not match the current tag: {$this->current_tag}\n";
			return false;
		}
		if ( $class_name ) {
			$classes = $this->get_class_names_bag();
			// For debugging:
			// echo "Class name {$class_name} does not match the current classes: {$classes}\n";
			if ( ! $classes->has( $class_name ) ) {
				return false;
			}
		}

		return true;
	}

	private function consume_next_attribute() {
		$regexp     = '~
			[\x{09}\x{0a}\x{0c}\x{0d} ]* # Preceeding whitespace
			(?:
				# Either a tag end, or an attribute:
				(?P<CLOSER>\/?>)
				|
				(?P<NAME>(?:
					# Attribute names starting with an equals sign (yes, this is valid)
					=[^=\/>\x{09}\x{0a}\x{0c}\x{0d} ]*
					|
					# Attribute names starting with anything other than an equals sign:
					[^=\/>\x{09}\x{0a}\x{0c}\x{0d} ]+
				))
				# Optional whitespace
				[\x{09}\x{0a}\x{0c}\x{0d} ]*
				# Whatever terminates the attribute name
				(?P<POST_NAME>
					(?P<EQUALS>=)
					[\x{09}\x{0a}\x{0c}\x{0d} ]*
					(?:\/?>|(?P<FIRST_VALUE_CHAR>(.)))
					|
					\/?>
				)?
			)
			~miux';
		$name_match = $this->match( $regexp );

		// No attribute, just tag closer.
		if ( ! empty( $name_match['CLOSER'][0] ) ) {
			return false;
		}

		$attribute_name  = $name_match['NAME'][0];
		$attribute_start = $this->index_before( $name_match['NAME'] );

		$value_specified = ! empty( $name_match['FIRST_VALUE_CHAR'][0] );
		if ( $value_specified ) {
			$this->move_caret_before( $name_match['FIRST_VALUE_CHAR'] );
			$value_match     = $this->match(
				"~[\x{09}\x{0a}\x{0c}\x{0d} ]*(?:(?P<QUOTE>['\"])(?P<VALUE>.*?)\k<QUOTE>|(?P<VALUE>[^=\/>\x{09}\x{0a}\x{0c}\x{0d} ]*))~miuJ"
			);
			$attribute_value = $value_match['VALUE'][0];
			$attribute_end   = $this->index_after( $value_match[0] );
		} else {
			$attribute_value = 'true';
			$attribute_end   = $this->index_after( $name_match['NAME'] );
		}

		$this->parsed_characters = $attribute_end;
		$attr                    = new WP_HTML_Attribute_Match( $attribute_name, $attribute_value, $attribute_start, $attribute_end );

		$this->current_tag_attributes[ $attribute_name ] = $attr;

		return $attr;
	}

	public function set_attribute( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			return $this->update_attribute_if_exists( $name, $value );
		} else {
			return $this->create_attribute( $name, $value );
		}
	}

	private function find_attribute( $name ) {
		if ( array_key_exists( $name, $this->current_tag_attributes ) ) {
			return $this->current_tag_attributes[ $name ];
		}
		do {
			$attr = $this->consume_next_attribute();
		} while ( $attr && ! WP_HTML_Comparator::equals( $attr->get_name(), $name ) );

		return $attr;
	}

	public function add_class( $class_name ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$this->get_class_names_bag()->add( $class_name );

		return $this;
	}

	public function remove_class( $class_name ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$this->get_class_names_bag()->remove( $class_name );

		return $this;
	}

	public function create_attribute( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$from_index        = $this->current_tag_name_ends_at;
		$to_index          = $this->current_tag_name_ends_at;
		$escaped_new_value = esc_attr( $value );
		$substitution      = " {$name}=\"{$escaped_new_value}\"";
		$this->add_diff( $name, new WP_Matcher_Diff( $from_index, $to_index, $substitution ) );

		return $this;
	}

	public function update_attribute_if_exists( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$from_index        = $attr->get_start_index();
			$to_index          = $attr->get_end_index();
			$escaped_new_value = esc_attr( $value );
			$substitution      = "{$name}=\"{$escaped_new_value}\"";
			$this->add_diff( $name, new WP_Matcher_Diff( $from_index, $to_index, $substitution ) );
		}

		return $this;
	}

	public function remove_attribute( $name ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$this->add_diff( $name, new WP_Matcher_Diff(
				$attr->get_start_index(),
				$attr->get_end_index(),
				''
			) );
		}

		return $this;
	}

	private function add_diff( $attribute_name, $diff ) {
		if ( in_array( $attribute_name, $this->touched_attr_names, true ) ) {
			throw new WP_HTML_Attribute_Already_Modified( "Only one change per tag attribute is supported and the attribute '{$attribute_name}' was already changed on tag {$this->current_tag}." );
		}
		$this->touched_attr_names[] = $attribute_name;
		$this->diffs[]              = $diff;
	}

	private function get_class_names_bag() {
		if ( $this->current_tag_class_names === null ) {
			$class_attr                    = $this->find_attribute( 'class' );
			$class_value                   = $class_attr ? $class_attr->get_value() : '';
			$this->current_tag_class_names = new WP_HTML_Updater_Classnames_Set( $class_value );
		}

		return $this->current_tag_class_names;
	}

	private function match( $regexp ) {
		$matches = null;
		$result  = preg_match(
			$regexp,
			$this->html,
			$matches,
			PREG_OFFSET_CAPTURE,
			$this->parsed_characters
		);
		if ( 1 !== $result ) {
			throw new WP_HTML_No_Match();
		}

		return $matches;
	}

	private function finish_parsing() {
		$this->after_tag();
		$this->parsed_characters = strlen( $this->html );
	}

	private function move_caret_before( $match ) {
		$this->parsed_characters = $this->index_before( $match );
	}

	private function move_caret_after( $match ) {
		$this->parsed_characters = $this->index_after( $match );
	}

	private function index_before( $match ) {
		return $match[1];
	}

	private function index_after( $match ) {
		return $match[1] + strlen( $match[0] );
	}

	public function __toString() {
		if ( null === $this->updated_html ) {
			$this->after_tag();
			usort( $this->diffs, function ( $diff1, $diff2 ) {
				return $diff1->get_from_index() - $diff2->get_from_index();
			} );

			$index = 0;
			foreach ( $this->diffs as $diff ) {
				$pieces[] = substr( $this->html, $index, $diff->get_from_index() - $index );
				$pieces[] = $diff->get_substitution();
				$index    = $diff->get_to_index();
			}
			$pieces[]           = substr( $this->html, $index );
			$this->updated_html = implode( '', $pieces );
		}

		return $this->updated_html;
	}

}


class WP_HTML_Attribute_Match {
	private $name;
	private $value;
	private $start_index;
	private $end_index;

	/**
	 * @param $name
	 * @param $value
	 * @param $start_index
	 * @param $end_index
	 */
	public function __construct( $name, $value, $start_index, $end_index ) {
		$this->name        = $name;
		$this->value       = $value;
		$this->start_index = $start_index;
		$this->end_index   = $end_index;
	}

	/**
	 * @return mixed
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * @return mixed
	 */
	public function get_value() {
		return $this->value;
	}

	/**
	 * @return mixed
	 */
	public function get_start_index() {
		return $this->start_index;
	}

	/**
	 * @return mixed
	 */
	public function get_end_index() {
		return $this->end_index;
	}

}

class WP_Matcher_Diff {
	private $from_index;
	private $to_index;
	private $substitution;

	/**
	 * @param $from_index
	 * @param $to_index
	 * @param $substitution
	 */
	public function __construct( $from_index, $to_index, $substitution ) {
		$this->from_index   = $from_index;
		$this->to_index     = $to_index;
		$this->substitution = $substitution;
	}

	/**
	 * @return mixed
	 */
	public function get_from_index() {
		return $this->from_index;
	}

	/**
	 * @return mixed
	 */
	public function get_to_index() {
		return $this->to_index;
	}

	/**
	 * @return mixed
	 */
	public function get_substitution() {
		return $this->substitution;
	}

}

class WP_HTML_No_Match extends \Exception {
}

class WP_HTML_Attribute_Already_Modified extends \Exception {
}

class WP_HTML_Updater_Classnames_Set {
	private $class_names = array();
	private $initial_class_names;

	public function __construct( $string ) {
		$classnames = preg_split( '~\s+~', $string );
		foreach ( $classnames as $classname ) {
			$this->add( $classname );
		}
		$this->initial_class_names = $this->class_names;
	}

	public function count() {
		return count( $this->class_names );
	}

	public function add( $classname ) {
		$key = WP_HTML_Comparator::comparable( $classname );

		$this->class_names[ $key ] = $classname;
	}

	public function remove( $classname ) {
		$key = WP_HTML_Comparator::comparable( $classname );
		unset( $this->class_names[ $key ] );
	}

	public function has( $classname ) {
		$key = WP_HTML_Comparator::comparable( $classname );

		return array_key_exists( $key, $this->class_names );
	}

	public function is_modified() {
		return $this->initial_class_names !== $this->class_names;
	}

	public function __toString() {
		return implode( ' ', array_values( $this->class_names ) );
	}
}

class WP_HTML_Comparator {

	public static function equals( $a, $b ) {
		return self::comparable( $a ) === self::comparable( $b );
	}

	public static function comparable( $value ) {
		return trim( strtolower( $value ) );
	}

}

$html = <<<HTML
<div class="merge-message">
	<div class="select-menu d-inline-block">
		<div class="BtnGroup MixedCaseHTML position-relative">
			<button type="button" class="merge-box-button btn-group-merge rounded-left-2 btn  BtnGroup-item js-details-target hx_create-pr-button" aria-expanded="false" data-details-container=".js-merge-pr" disabled="">
			  Merge pull request
			</button>

			<button type="button" class="merge-box-button btn-group-squash rounded-left-2 btn  BtnGroup-item js-details-target hx_create-pr-button" aria-expanded="false" data-details-container=".js-merge-pr" disabled="">
			  Squash and merge
			</button>

			<button type="button" class="merge-box-button btn-group-rebase rounded-left-2 btn  BtnGroup-item js-details-target hx_create-pr-button" aria-expanded="false" data-details-container=".js-merge-pr" disabled="">
			  Rebase and merge
			</button>

			<button aria-label="Select merge method" disabled="disabled" type="button" data-view-component="true" class="select-menu-button btn BtnGroup-item"></button>
		</div>
	</div>
</div>
HTML;

// Mock escaping to enable developing this code outside of WordPress.
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $attr ) {
		return htmlspecialchars( $attr );
	}
}

$updater = new WP_HTML_Updater( $html );
$updater
	->find_next_tag( 'div' )
		->set_attribute( 'data-details', '{ "key": "value" }' )
		->add_class( 'is-processed' )
	->find_next_tag( 'div', 'BtnGroup' )
		->remove_class( 'BtnGroup' )
		->add_class( 'button-group' )
		->add_class( 'Another-Mixed-Case' )
	->find_next_tag( 'button', 'btn', 2 )
		->remove_attribute( 'class' )
	->find_next_tag( 'this one is missing' )
		->remove_attribute( 'but we still do not error out!' );

var_dump( $updater . '' );
