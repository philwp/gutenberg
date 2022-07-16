<?php

class WP_Tag_Match {
	private $name;
	private $start_index;

	/**
	 * @param $name
	 * @param $start_index
	 */
	public function __construct( $name, $start_index ) {
		$this->name        = $name;
		$this->start_index = $start_index;
	}

	/**
	 * @return mixed
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @return mixed
	 */
	public function getStartIndex() {
		return $this->start_index;
	}

	public function getTagNameEndIndex() {
		return $this->start_index + 1 + strlen( $this->name );
	}

}

class WP_Attribute_Match {
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

	public function __toString() {
		return "{$this->name}=\"{$this->value}\"";
	}

	/**
	 * @return mixed
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @return mixed
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * @return mixed
	 */
	public function getStartIndex() {
		return $this->start_index;
	}

	/**
	 * @return mixed
	 */
	public function getEndIndex() {
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
	public function getFromIndex() {
		return $this->from_index;
	}

	/**
	 * @return mixed
	 */
	public function getToIndex() {
		return $this->to_index;
	}

	/**
	 * @return mixed
	 */
	public function getSubstitution() {
		return $this->substitution;
	}

}

class WP_HTML_Updater {

	private $html;

	public function __construct( $html ) {
		$this->html                = $html;
		$this->caret               = 0;
		$this->current_tag         = null;
		$this->parsed_attributes   = array();
		$this->diffs               = array();
		$this->modified_attributes = array();
		$this->new_string          = false;
	}

	public function __toString() {
		if ( false === $this->new_string ) {
			if ( ! count( $this->diffs ) ) {
				$this->new_string = '';

				return $this->new_string;
			}
			$sorted_diffs = [];
			foreach ( $this->diffs as $diff ) {
				$sorted_diffs[ $diff->getFromIndex() ] = $diff;
			}
			ksort( $sorted_diffs );
			$sorted_diffs = array_values( $sorted_diffs );
			var_dump( $sorted_diffs );

			$index = 0;
			foreach ( $sorted_diffs as $diff ) {
				$pieces[] = substr( $this->html, $index, $diff->getFromIndex() - $index );
				$pieces[] = $diff->getSubstitution();
				$index    = $diff->getToIndex();
			}
			$pieces[]         = substr( $this->html, $index );
			$this->new_string = implode( ' ', $pieces );
		}

		return $this->new_string;
	}

	protected function eof() {
		$this->setCaret( strlen( $this->html ) );

		return $this;
	}

	protected function advanceCaret( $length ) {
		$this->caret += $length;
	}

	protected function setCaret( $position ) {
		$this->caret = $position;
	}

	public function find_next_tag( $name_spec, $class_name_spec = null, $nth_match = 0 ) {
		$matched = - 1;
		while ( true ) {
			$tag = $this->consume_next_tag();
			if ( ! $tag ) {
				break;
			}
			if ( ! $this->tag_matches( $tag, $name_spec, $class_name_spec ) ) {
				$this->skip_through_attributes();
				continue;
			}
			if ( ++ $matched === $nth_match ) {
				break;
			}
		}

		return $this;
	}

	private function tag_matches( $tag, $name_spec, $class_name_spec ) {
		if ( ! $tag ) {
			return false;
		}
		if ( $name_spec && $tag->getName() !== $name_spec ) {
			return false;
		}
		if ( $class_name_spec ) {
			$classes = $this->get_classes();
			if ( ! in_array( $class_name_spec, $classes, true ) ) {
				return false;
			}
		}

		return true;
	}

	public function consume_next_tag() {
		$this->current_tag         = null;
		$this->parsed_attributes   = array();
		$this->modified_attributes = array();

		try {
			$result = $this->match(
				'~<!--(?>.*?-->)|<!\[CDATA\[(?>.*?>)|<\?(?>.*?)>|<(?P<TAG>[a-z][^\t\x{0A}\x{0C} \/>]*)~mui'
			);
		} catch ( Exception $e ) {
			return;
		}

		$full_match = $result[0][0];

		$this->advanceCaret( strlen( $full_match ) );
		if ( ! isset( $result['TAG'] ) ) {
			return $this->consume_next_tag();
		}
		$this->matches_buffer = array();
		$this->current_tag    = new WP_Tag_Match(
			$result['TAG'][0],
			$result[0][1]
		);

		return $this->current_tag;
	}

	public function add_class( $new_class_name ) {
		$current_classes = $this->get_classes();
		if ( in_array( $new_class_name, $current_classes, true ) ) {
			return $this;
		}

		$current_classes[] = $new_class_name;

		return $this->set_attribute( 'class', implode( ' ', $current_classes ) );
	}

	public function remove_class( $removed_class_name ) {
		$classes = $this->get_classes();
		if ( ! count( $classes ) ) {
			return $this;
		}

		$new_class_names = [];
		foreach ( $this->get_classes() as $class_name ) {
			if ( ! self::equals( $class_name, $removed_class_name ) ) {
				$new_class_names[] = $class_name;
			}
		}

		return $this->update_attribute_if_exists( 'class', implode( ' ', $new_class_names ) );
	}

	public function set_attribute( $name, $value_or_callback ) {
		if ( ! is_callable( $value_or_callback ) ) {
			$value    = $value_or_callback;
			$callback = function () use ( $value ) {
				return $value;
			};
		} else {
			$callback = $value_or_callback;
		}
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			return $this->update_attribute_if_exists( $name, $callback );
		} else {
			return $this->create_attribute( $name, $callback( null ) );
		}
	}

	public function create_attribute( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$from_index        = $this->current_tag->getTagNameEndIndex();
		$to_index          = $this->current_tag->getTagNameEndIndex();
		$new_value         = $value;
		$escaped_new_value = $new_value; //esc_attr( $new_value );
		$substitution      = " {$name}=\"{$escaped_new_value}\"";
		$this->mark_attribute_as_updated( $name );
		$this->diffs[] = new WP_Matcher_Diff( $from_index, $to_index, $substitution );

		return $this;
	}

	public function update_attribute_if_exists( $name, $value_or_callback ) {
		if ( ! is_callable( $value_or_callback ) ) {
			$value    = $value_or_callback;
			$callback = function () use ( $value ) {
				return $value;
			};
		} else {
			$callback = $value_or_callback;
		}
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$from_index        = $attr->getStartIndex();
			$to_index          = $attr->getEndIndex();
			$new_value         = $callback( $attr->getValue() );
			$escaped_new_value = $new_value; //esc_attr( $new_value );
			$substitution      = "{$name}=\"{$escaped_new_value}\"";
			$this->mark_attribute_as_updated( $name );
			$this->diffs[] = new WP_Matcher_Diff( $from_index, $to_index, $substitution );
		}

		return $this;
	}

	private function mark_attribute_as_updated( $name ) {
		if ( in_array( $name, $this->modified_attributes, true ) ) {
			throw new Exception( 'nonono' );
		}
		$this->modified_attributes[] = $name;
	}

	private function get_classes() {
		$class = $this->find_attribute( 'class' );
		if ( ! $class || ! $class->getValue() ) {
			return [];
		}

		return preg_split( '~\s+~', $class->getValue() );
	}

	public function remove_attribute( $name ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$this->diffs[] = new WP_Matcher_Diff(
				$attr->getStartIndex(),
				$attr->getEndIndex(),
				''
			);
		}

		return $this;
	}

	private function find_attribute( $name ) {
		if ( array_key_exists( $name, $this->parsed_attributes ) ) {
			return $this->parsed_attributes[ $name ];
		}
		while ( true ) {
			$attr = $this->consume_next_attribute();
			if ( ! $attr ) {
				break;
			} elseif ( self::equals( $attr->getName(), $name ) ) {
				return $attr;
			}
		}
	}

	private function skip_through_attributes() {
		while ( true ) {
			$attr = $this->consume_next_attribute();
			if ( ! $attr ) {
				break;
			}
		}
	}

	private static function equals( $a, $b ) {
		// @TODO
		return $a === $b;
	}

	private function consume_next_attribute() {
		$regexp = '~
			[\x{09}\x{0a}\x{0c}\x{0d} ]+ # Preceeding whitespace
			(?:
				# Either a tag end, or an attribute:
				(?P<CLOSER>\/?>)
				|
				(?P<NAME>(?:
					# Attribute names starting with an equals sign (yes, this is valid)
					=[^=\/>\t\x{09}\x{0C} ]*
					|
					# Attribute names starting with anything other than an equals sign:
					[^=\/>\t\x{09}\x{0C} ]+)
				)
				# Whatever terminates the attribute name
				(?P<IGNORED>=|\/?>)?
			)
			~miux';
		try {
			$name_result = $this->match( $regexp );
		} catch ( \Exception $e ) {
			$this->eof();

			return false;
		}

		$full_name_match = $name_result[0][0];

		// No attribute, just tag closer.
		if ( ! empty( $name_result['CLOSER'] ) && $name_result['CLOSER'][1] !== - 1 ) {
			$this->advanceCaret( strlen( $full_name_match ) );

			return false;
		}

		// No closer and no attribute name – this should never ever happen.
		if ( empty( $name_result['NAME'] ) || $name_result['NAME'][1] === - 1 ) {
			throw new Exception( 'Something went wrong' );
		}

		$attribute_name    = $name_result['NAME'][0];
		$is_flag_attribute = false;
		if ( empty( $name_result['IGNORED'] ) || $name_result['IGNORED'][1] === - 1 ) {
			// There's neither an equals sign, nor a tag end after this attribute,
			// it must be therefore a flag attribute followed by another attribute.
			$this->advanceCaret( strlen( $full_name_match ) );
			$is_flag_attribute = true;
		} elseif ( '=' !== $name_result['IGNORED'][0] ) {
			// There is no equal sign after the attribute name – this is a flag attribute
			// followed by a tag closer.
			$this->advanceCaret( strlen( $full_name_match ) - strlen( $name_result['IGNORED'][0] ) );
			$is_flag_attribute = true;
		} else {
			// Matched an attribute name followed by an equals sign, let's scan for the value.
			$this->advanceCaret( strlen( $full_name_match ) - strlen( $name_result['IGNORED'][0] ) );
			$equals_result = $this->match(
				'~(?P<EQUALS>[\x{09}\x{0a}\x{0c}\x{0d} ]*=)[\x{09}\x{0a}\x{0c}\x{0d} ]*(?:(?P<CLOSER>\/?>)|(?P<FIRST_CHAR>(.)))~miu'
			);
			$this->setCaret( $equals_result['EQUALS'][1] + strlen( $equals_result['EQUALS'][0] ) );

			// An equals sign followed by tag closer becomes a flag attribute.
			// For example: <div attr=/> becomes <div attr />
			if ( ! empty( $equals_result['CLOSER'] ) && $equals_result['CLOSER'][1] !== - 1 ) {
				$is_flag_attribute = true;
			}
		}

		if ( $is_flag_attribute ) {
			$this->parsed_attributes[ $attribute_name ] = new WP_Attribute_Match(
				$attribute_name,
				true,
				$name_result['NAME'][1],
				$name_result['NAME'][1] + strlen( $attribute_name )
			);

			return $this->parsed_attributes[ $attribute_name ];
		}

		// At this point we know the equal sign is followed by a value. Let's capture it:
		if ( ! in_array( $equals_result['FIRST_CHAR'][0], [ "'", '"' ], true ) ) {
			$value_result = $this->match(
				"~\s*(?P<VALUE>[^=\/>\t\x{09}\x{0C} ]*)~miu"
			);
		} else {
			$quote_character = $equals_result['FIRST_CHAR'][0];
			$value_result    = $this->match(
				"~\s*{$quote_character}(?P<VALUE>[^{$quote_character}]*){$quote_character}~miu"
			);
		}

		$this->advanceCaret( strlen( $value_result[0][0] ) );
		$this->parsed_attributes[ $attribute_name ] = new WP_Attribute_Match(
			$attribute_name,
			$value_result['VALUE'][0],
			$name_result['NAME'][1],
			$value_result[0][1] + strlen( $value_result[0][0] )
		);

		return $this->parsed_attributes[ $attribute_name ];
	}


	protected function match( $regexp ) {
		$matches = null;
		$result  = preg_match(
			$regexp,
			$this->html,
			$matches,
			PREG_OFFSET_CAPTURE,
			$this->caret
		);
		if ( 1 !== $result ) {
			throw new Exception( 'Something went wrong' );
		}

		return $matches;
	}


}

$html = '
<div attr_3=\'3\' attr_1="1" attr_2 attr4=  abc =test class="class names" /><img test123 class="boat" /><img class="boat 2" />
';

function dump_attrs( $attrs ) {
	$array = [];
	foreach ( $attrs as $k => $v ) {
		$array[ $k ] = $v . '';
	}
	var_dump( $array );
}

$updater = new WP_HTML_Updater( $html );
$updater->find_next_tag( 'div' )
        ->remove_attribute( 'attr4' )
        ->set_attribute( 'attr_2', function ( $old_value ) {
	        return "well well well";
        } )
        ->add_class( 'prego' )
        ->set_attribute( 'attr9', function () {
	        return "hey ha";
        } )
        ->find_next_tag( 'img' )
        ->remove_class( 'boat' );
var_dump( $updater . '' );
//dump_attrs( $updater->parsed_attributes );
//print_r( $updater->diffs );
die();
//	->setAttribute( 'test123', '123' )x
//	->removeAttribute( 'test123' )
//	->findNext( 'img' )
//	->setAttribute( 'test124', '123' )


