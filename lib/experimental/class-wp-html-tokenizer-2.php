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
	private $new_classnames;
	private $classnames_modified;
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
		$this->html       = $html;
		$this->diffs      = array();
		$this->caret      = 0;
		$this->new_string = false;
		$this->reset_current_tag_state();
	}

	public function __toString() {
		if ( false === $this->new_string ) {
			$this->finish_processing_current_tag();
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

	public function find_next_tag( $name_spec, $class_name_spec = null, $nth_match = 0 ) {
		$this->finish_processing_current_tag();
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

	protected function finish_processing_current_tag() {
		if ( ! $this->current_tag ) {
			return;
		}
		if ( $this->classnames_modified ) {
			if ( count( $this->new_classnames ) ) {
				$this->set_attribute( 'class', implode( ' ', array_keys( $this->new_classnames ) ) );
			} else {
				$this->remove_attribute( 'class' );
			}
		}
		$this->reset_current_tag_state();
	}

	protected function reset_current_tag_state() {
		$this->current_tag                = null;
		$this->current_tag_name_end_index = null;
		$this->parsed_attributes          = array();
		$this->modified_attributes        = array();
		$this->new_classnames             = null;
		$this->classnames_modified        = false;
	}

	public function set_attribute( $name, $value ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			return $this->update_attribute_if_exists( $name, $value );
		} else {
			return $this->create_attribute( $name, $value );
		}
	}

	public function add_class( $class_name ) {
		$this->get_classes();
		if ( array_key_exists( $class_name, $this->new_classnames ) ) {
			return $this;
		}

		$this->new_classnames[ $class_name ] = true;
		$this->classnames_modified           = true;

		return $this;
	}

	public function remove_class( $class_name ) {
		$this->get_classes();
		unset( $this->new_classnames[ $class_name ] );
		$this->classnames_modified = true;

		return $this;
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
		$this->addDiff( $name, new WP_Matcher_Diff( $from_index, $to_index, $substitution ) );

		return $this;
	}

	public function update_attribute_if_exists( $name, $value ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$from_index        = $attr->getStartIndex();
			$to_index          = $attr->getEndIndex();
			$escaped_new_value = $value; //esc_attr( $new_value );
			$substitution      = "{$name}=\"{$escaped_new_value}\"";
			$this->addDiff( $name, new WP_Matcher_Diff( $from_index, $to_index, $substitution ) );
		}

		return $this;
	}

	public function remove_attribute( $name ) {
		$attr = $this->find_attribute( $name );
		if ( $attr ) {
			$this->addDiff( $name, new WP_Matcher_Diff(
				$attr->getStartIndex(),
				$attr->getEndIndex(),
				''
			) );
		}

		return $this;
	}

	private function addDiff( $attribute_name, $diff ) {
		if ( in_array( $attribute_name, $this->modified_attributes, true ) ) {
			throw new Exception( 'nonono' );
		}
		$this->modified_attributes[] = $attribute_name;
		$this->diffs[]               = $diff;
	}

	private function get_classes() {
		if ( $this->new_classnames === null ) {
			$class = $this->find_attribute( 'class' );
			if ( ! $class || ! $class->getValue() ) {
				$this->new_classnames = [];
			} else {
				$classes              = preg_split( '~\s+~', $class->getValue() );
				$classes              = array_map( [ $this, 'comparable' ], $classes );
				$this->new_classnames = array_flip( $classes );
			}
		}

		return $this->new_classnames;
	}

	private function find_attribute( $name ) {
		if ( array_key_exists( $name, $this->parsed_attributes ) ) {
			return $this->parsed_attributes[ $name ];
		}
		do {
			$attr = $this->consume_next_attribute();
			if ( $attr && self::equals( $attr->getName(), $name ) ) {
				return $attr;
			}
		} while ( $attr );
	}

	private function skip_all_attributes() {
		do {
			$attr = $this->consume_next_attribute();
		} while ( $attr );
	}

	private function consume_next_tag() {
		$result = $this->match(
			'~<!--(?>.*?-->)|<!\[CDATA\[(?>.*?>)|<\?(?>.*?)>|<(?P<TAG>[a-z][^\x{09}\x{0a}\x{0c}\x{0d} \/>]*)~mui'
		);
		if ( ! $result ) {
			$this->finish_parsing();

			return false;
		}

		$this->moveCaretAfter( $result[0] );
		if ( ! isset( $result['TAG'] ) ) {
			return $this->consume_next_tag();
		}
		$this->current_tag                = $result['TAG'][0];
		$this->current_tag_name_end_index = $result[0][1] + strlen( $result['TAG'][0] ) + 1;

		return $this->current_tag;
	}

	private function consume_next_attribute() {
		$regexp      = '~
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
		$name_result = $this->match( $regexp );
		if ( ! $name_result ) {
			$this->finish_parsing();

			return false;
		}

		// No attribute, just tag closer.
		if ( ! empty( $name_result['CLOSER'][0] ) ) {
			return false;
		}

		$value_specified = ! empty( $name_result['FIRST_VALUE_CHAR'][0] );

		$match = $value_specified
			? $this->consume_value_attribute( $name_result )
			: $this->consume_flag_attribute( $name_result );

		if ( ! $match ) {
			$this->finish_parsing();

			return false;
		}

		$this->parsed_attributes[ $match->getName() ] = $match;

		return $match;
	}

	private function consume_value_attribute( $name_match ) {
		$this->moveCaretBefore( $name_match['FIRST_VALUE_CHAR'] );
		$value_result = $this->match(
			"~[\x{09}\x{0a}\x{0c}\x{0d} ]*(?:(?P<QUOTE>['\"])(?P<VALUE>.*?)\k<QUOTE>|(?P<VALUE>[^=\/>\x{09}\x{0a}\x{0c}\x{0d} ]*))~miuJ"
		);
		if ( empty( $value_result['VALUE'][0] ) ) {
			$this->finish_parsing();

			return false;
		}

		$this->moveCaretAfter( $value_result[0] );

		return new WP_Attribute_Match(
			$name_match['NAME'][0],
			$value_result['VALUE'][0],
			$this->indexBefore( $name_match['NAME'] ),
			$this->indexAfter( $value_result[0] )
		);
	}

	private function consume_flag_attribute( $name_match ) {
		$this->moveCaretAfter( $name_match['NAME'] );

		return new WP_Attribute_Match(
			$name_match['NAME'][0],
			true,
			$this->indexBefore( $name_match['NAME'] ),
			$this->indexAfter( $name_match['NAME'] )
		);
	}

	protected function match( $regexp ) {
		$matches = null;
		preg_match(
			$regexp,
			$this->html,
			$matches,
			PREG_OFFSET_CAPTURE,
			$this->caret
		);

		return $matches;
	}

	private static function equals( $a, $b ) {
		return self::comparable( $a ) === self::comparable( $b );
	}

	private static function comparable( $value ) {
		return trim( strtolower( $value ) );
	}

	protected function finish_parsing() {
		$this->caret = strlen( $this->html );
	}

	protected function moveCaretBefore( $match ) {
		$this->caret = $this->indexBefore( $match );
	}

	protected function moveCaretAfter( $match ) {
		$this->caret = $this->indexAfter( $match );
	}

	protected function indexBefore( $match ) {
		return $match[1];
	}

	protected function indexAfter( $match ) {
		return $match[1] + strlen( $match[0] );
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
        ->find_next_tag( 'img', null, 0 )
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


