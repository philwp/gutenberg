/**
 * External dependencies
 */
import { has } from 'lodash';

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { hasBlockSupport } from '@wordpress/blocks';

/**
 * Filters registered block settings, extending attributes to include `meta`.
 *
 * @param {Object} settings Original block settings.
 *
 * @return {Object} Filtered block settings.
 */
export function addMetaAttribute( settings ) {
	// Allow blocks to specify their own attribute definition with default values if needed.
	if ( has( settings.attributes, [ 'meta', 'type' ] ) ) {
		return settings;
	}
	if ( hasBlockSupport( settings, 'meta' ) ) {
		settings.attributes = {
			...settings.attributes,
			meta: {
				type: 'object',
			},
		};
	}

	return settings;
}

export function addSaveProps( extraProps, blockType, attributes ) {
	if ( hasBlockSupport( blockType, 'meta' ) ) {
		extraProps.meta = attributes.meta;
	}

	return extraProps;
}

addFilter(
	'blocks.registerBlockType',
	'core/meta/addMetaAttribute',
	addMetaAttribute
);

addFilter(
	'blocks.getSaveContent.extraProps',
	'core/meta/save-props',
	addSaveProps
);
