/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Slot, Fill } from '@wordpress/components';

function PinnedItems( { scope, ...props } ) {
	return <Fill name={ `PinnedItems/${ scope }` } { ...props } />;
}

function PinnedItemsSlot( { scope, className, ...props } ) {
	return (
		<Slot name={ `PinnedItems/${ scope }` } { ...props }>
			{ ( fills ) =>
				fills?.length > 0 && (
					<div
						className={ classnames(
							className,
							'interface-pinned-items'
						) }
					>
						{ fills }
					</div>
				)
			}
		</Slot>
	);
}

PinnedItems.Slot = PinnedItemsSlot;

export default PinnedItems;
