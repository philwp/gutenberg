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

class WP_HTML_No_Match_Exception extends \Exception {
}

class WP_HTML_Updater_ClassNameBag {
	private $class_names;
	private $is_modified = false;

	public function __construct( $string ) {
		$classes           = preg_split( '~\s+~', $string );
		$classes           = array_map( [ 'WP_HTML_Updater', 'comparable' ], $classes );
		$this->class_names = array_flip( $classes );
	}

	public function count() {
		return count( $this->class_names );
	}

	public function add( $classname ) {
		if ( ! $this->has( $classname ) ) {
			$this->class_names[ $classname ] = true;
			$this->is_modified               = true;
		}
	}

	public function remove( $classname ) {
		if ( $this->has( $classname ) ) {
			unset( $this->class_names[ $classname ] );
			$this->is_modified = true;
		}
	}

	public function has( $classname ) {
		return array_key_exists( $classname, $this->class_names );
	}

	public function is_modified() {
		return $this->is_modified;
	}

	public function __toString() {
		return implode( ' ', array_keys( $this->class_names ) );
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
	private $current_tag_name_ends_at;
	/**
	 * @var array
	 */
	private $current_tag_attributes;
	private $current_tag_class_names;
	/**
	 * @var array
	 */
	private $touched_attr_names;
	/**
	 * @var array
	 */
	public $diffs;
	/**
	 * @var false
	 */
	private $updated_html;

	public function __construct( $html ) {
		$this->html         = $html;
		$this->diffs        = array();
		$this->caret        = 0;
		$this->updated_html = false;
		$this->reset_current_tag_state();
	}

	private function reset_current_tag_state() {
		$this->current_tag              = null;
		$this->current_tag_name_ends_at = null;
		$this->current_tag_attributes   = array();
		$this->current_tag_class_names  = null;
		$this->touched_attr_names       = array();
	}

	public function find_next_tag( $name_spec, $class_name_spec = null, $nth_match = 0 ) {
		$matched = - 1;
		try {
			while ( true ) {
				$tag = $this->consume_next_tag();
				if ( $this->tag_matches( $tag, $name_spec, $class_name_spec ) ) {
					if ( ++ $matched === $nth_match ) {
						break;
					}
				}
				$this->skip_all_attributes();
			}
		} catch ( WP_HTML_No_Match_Exception $e ) {
			$this->finish_parsing();
		}

		return $this;
	}

	private function consume_next_tag() {
		$this->finish_processing_current_tag();
		do {
			$matches = $this->match(
				'~<!--(?>.*?-->)|<!\[CDATA\[(?>.*?>)|<\?(?>.*?)>|<(?P<TAG_NAME>[a-z][^\x{09}\x{0a}\x{0c}\x{0d} \/>]*)~mui'
			);
			$this->move_caret_after( $matches[0] );
		} while ( empty( $matches['TAG_NAME'][0] ) );

		$this->current_tag              = $matches['TAG_NAME'][0];
		$this->current_tag_name_ends_at = $this->index_after( $matches['TAG_NAME'] );

		return $this->current_tag;
	}

	private function finish_processing_current_tag() {
		if ( ! $this->current_tag ) {
			return;
		}
		if ( $this->get_class_name_bag()->is_modified() ) {
			if ( $this->get_class_name_bag()->count() ) {
				$this->set_attribute( 'class', $this->current_tag_class_names . '' );
			} else {
				$this->remove_attribute( 'class' );
			}
		}
		$this->reset_current_tag_state();
	}

	private function tag_matches( $tag, $name_spec, $class_name_spec ) {
		if ( $name_spec && ! self::equals( $tag, $name_spec ) ) {
			return false;
		}
		if ( $class_name_spec ) {
			$classes = $this->get_class_name_bag();
			if ( ! $classes->has( $class_name_spec ) ) {
				return false;
			}
		}

		return true;
	}

	private function skip_all_attributes() {
		do {
			$attr = $this->consume_next_attribute();
		} while ( $attr );
	}

	private function consume_next_attribute() {
		$regexp     = '~
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
			$attribute_value = true;
			$attribute_end   = $this->index_after( $name_match['NAME'] );
		}

		$this->caret = $attribute_end;
		$attr        = new WP_Attribute_Match( $attribute_name, $attribute_value, $attribute_start, $attribute_end );

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
		} while ( $attr && ! self::equals( $attr->get_name(), $name ) );

		return $attr;
	}

	public function add_class( $class_name ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$this->get_class_name_bag()->add( $class_name );

		return $this;
	}

	public function remove_class( $class_name ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$this->get_class_name_bag()->remove( $class_name );

		return $this;
	}

	public function create_attribute( $name, $value ) {
		if ( ! $this->current_tag ) {
			return $this;
		}
		$from_index        = $this->current_tag_name_ends_at;
		$to_index          = $this->current_tag_name_ends_at;
		$new_value         = $value;
		$escaped_new_value = $new_value; //esc_attr( $new_value );
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
			$escaped_new_value = $value; //esc_attr( $new_value );
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
			throw new Exception( 'nonono' );
		}
		$this->touched_attr_names[] = $attribute_name;
		$this->diffs[]              = $diff;
	}

	private function get_class_name_bag() {
		if ( $this->current_tag_class_names === null ) {
			$class_attr                    = $this->find_attribute( 'class' );
			$class_value                   = $class_attr ? $class_attr->get_value() : '';
			$this->current_tag_class_names = new WP_HTML_Updater_ClassNameBag( $class_value );
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
			$this->caret
		);
		if ( 1 !== $result ) {
			throw new WP_HTML_No_Match_Exception();
		}

		return $matches;
	}

	private static function equals( $a, $b ) {
		return self::comparable( $a ) === self::comparable( $b );
	}

	public static function comparable( $value ) {
		return trim( strtolower( $value ) );
	}

	private function finish_parsing() {
		$this->reset_current_tag_state();
		$this->caret = strlen( $this->html );
	}

	private function move_caret_before( $match ) {
		$this->caret = $this->index_before( $match );
	}

	private function move_caret_after( $match ) {
		$this->caret = $this->index_after( $match );
	}

	private function index_before( $match ) {
		return $match[1];
	}

	private function index_after( $match ) {
		return $match[1] + strlen( $match[0] );
	}

	public function __toString() {
		if ( false === $this->updated_html ) {
			$this->finish_processing_current_tag();
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

$html = '
<div attr_3=\'3\' attr_1="1" attr_2 attr4 =abc =test class="class names" /><img test123 class="boat" /><img class="boat 2" /><span />
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
        ->find_next_tag( 'img', null, 1 )
            ->add_class( 'boat2' )
        ->find_next_tag( 'spa2n' )
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


