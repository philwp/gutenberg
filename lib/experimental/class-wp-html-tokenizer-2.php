<?php

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
	/**
	 * @var int
	 */
	private $caret;
	/**
	 * @var null
	 */
	private $current_tag;
	private $current_tag_name_end_index;
	/**
	 * @var array
	 */
	private $parsed_attributes;
	/**
	 * @var array
	 */
	public $diffs;
	/**
	 * @var array
	 */
	private $modified_attributes;
	/**
	 * @var false
	 */
	private $new_string;

	public function __construct( $html ) {
		$this->html                       = $html;
		$this->caret                      = 0;
		$this->current_tag                = null;
		$this->current_tag_name_end_index = null;
		$this->parsed_attributes          = array();
		$this->diffs                      = array();
		$this->modified_attributes        = array();
		$this->new_string                 = false;
	}

	public function __toString() {
		if ( false === $this->new_string ) {
			usort( $this->diffs, function ( $diff1, $diff2 ) {
				return $diff1->getFromIndex() - $diff2->getFromIndex();
			} );

			$index = 0;
			foreach ( $this->diffs as $diff ) {
				$pieces[] = substr( $this->html, $index, $diff->getFromIndex() - $index );
				$pieces[] = $diff->getSubstitution();
				$index    = $diff->getToIndex();
			}
			$pieces[]         = substr( $this->html, $index );
			$this->new_string = implode( '', $pieces );
		}

		return $this->new_string;
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
				$this->skip_all_attributes();
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
		if ( $name_spec && ! self::equals( $tag, $name_spec ) ) {
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

	private function consume_next_tag() {
		$this->current_tag                = null;
		$this->current_tag_name_end_index = null;
		$this->parsed_attributes          = array();
		$this->modified_attributes        = array();

		try {
			$result = $this->match(
				'~<!--(?>.*?-->)|<!\[CDATA\[(?>.*?>)|<\?(?>.*?)>|<(?P<TAG>[a-z][^\x{09}\x{0a}\x{0c}\x{0d} \/>]*)~mui'
			);
		} catch ( Exception $e ) {
			$this->setCaret( strlen( $this->html ) );

			return;
		}

		$full_match = $result[0][0];

		$this->advanceCaret( strlen( $full_match ) );
		if ( ! isset( $result['TAG'] ) ) {
			return $this->consume_next_tag();
		}
		$this->current_tag                = $result['TAG'][0];
		$this->current_tag_name_end_index = $result[0][1] + strlen( $result['TAG'][0] ) + 1;

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

	public function set_attribute( $name, $value ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			return $this->update_attribute_if_exists( $name, $value );
		} else {
			return $this->create_attribute( $name, $value );
		}
	}

	public function create_attribute( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$from_index        = $this->current_tag_name_end_index;
		$to_index          = $this->current_tag_name_end_index;
		$new_value         = $value;
		$escaped_new_value = $new_value; //esc_attr( $new_value );
		$substitution      = " {$name}=\"{$escaped_new_value}\"";
		$this->mark_attribute_as_updated( $name );
		$this->diffs[] = new WP_Matcher_Diff( $from_index, $to_index, $substitution );

		return $this;
	}

	public function update_attribute_if_exists( $name, $new_value ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$from_index        = $attr->getStartIndex();
			$to_index          = $attr->getEndIndex();
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

	private function skip_all_attributes() {
		while ( true ) {
			$attr = $this->consume_next_attribute();
			if ( ! $attr ) {
				break;
			}
		}
	}

	private static function equals( $a, $b ) {
		return self::comparable( $a ) === self::comparable( $b );
	}

	private static function comparable( $value ) {
		return trim( strtolower( $value ) );
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
		try {
			$name_result = $this->match( $regexp );
		} catch ( \Exception $e ) {
			$this->setCaret( strlen( $this->html ) );

			return false;
		}

		// No attribute, just tag closer.
		if ( ! empty( $name_result['CLOSER'][0] ) ) {
			$this->setCaret( $name_result['CLOSER'][1] );

			return false;
		}

		// No closer and no attribute name – this should never ever happen.
		if ( empty( $name_result['NAME'][0] ) ) {
			throw new Exception( 'Something went wrong' );
		}

		$attribute_name = $name_result['NAME'][0];
		if ( empty( $name_result['FIRST_VALUE_CHAR'][0] ) ) {
			// The name is *not* followed by a value – it must be a flag attribute.

			// Move the caret after the attribute name.
			$this->setCaret( $name_result['NAME'][1] + strlen( $name_result['NAME'][0] ) );

			$this->parsed_attributes[ $attribute_name ] = new WP_Attribute_Match(
				$attribute_name,
				true,
				$name_result['NAME'][1],
				$name_result['NAME'][1] + strlen( $name_result['NAME'][0] )
			);
		} else {
			// The name *is* followed by a value – let's consume it.

			// Consume the value.
			$this->setCaret( $name_result['FIRST_VALUE_CHAR'][1] );
			$value_result = $this->match(
				"~[\x{09}\x{0a}\x{0c}\x{0d} ]*(?:(?P<QUOTE>['\"])(?P<VALUE>.*?)\k<QUOTE>|(?P<VALUE>[^=\/>\x{09}\x{0a}\x{0c}\x{0d} ]*))~miuJ"
			);

			$this->advanceCaret( strlen( $value_result[0][0] ) );
			$this->parsed_attributes[ $attribute_name ] = new WP_Attribute_Match(
				$attribute_name,
				$value_result['VALUE'][0],
				$name_result['NAME'][1],
				$value_result[0][1] + strlen( $value_result[0][0] )
			);
		}

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
<div attr_3=\'3\' attr_1="1" attr_2 attr4 =abc =test class="class names" /><img test123 class="boat" /><img class="boat 2" />
';

//$html = '
//<div lippa attr4 ="abc" />
//';

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
        ->set_attribute( 'attr_2', "well well well" )
        ->add_class( 'prego' )
        ->set_attribute( 'attr9', "hey ha" )
        ->find_next_tag( 'img', null, 2 )
        ->add_class( 'boat2' );
//var_dump( $updater->diffs );
var_dump( $updater . '' );
//dump_attrs( $updater->parsed_attributes );
//print_r( $updater->diffs );
die();
//	->setAttribute( 'test123', '123' )x
//	->removeAttribute( 'test123' )
//	->findNext( 'img' )
//	->setAttribute( 'test124', '123' )


