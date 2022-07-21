// @ts-nocheck
/**
 * External dependencies
 */
import classnames from 'classnames';
import {
	useFloating,
	flip,
	shift,
	autoUpdate,
	arrow,
	offset as offsetMiddleware,
	limitShift,
	size,
} from '@floating-ui/react-dom';

/**
 * WordPress dependencies
 */
import {
	useRef,
	useLayoutEffect,
	forwardRef,
	createContext,
	useContext,
	useMemo,
} from '@wordpress/element';
import {
	useViewportMatch,
	useMergeRefs,
	__experimentalUseDialog as useDialog,
} from '@wordpress/compose';
import { close } from '@wordpress/icons';
import deprecated from '@wordpress/deprecated';
import { isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Button from '../button';
import ScrollLock from '../scroll-lock';
import { Slot, Fill, useSlot } from '../slot-fill';
import { getAnimateClassName } from '../animate';

/**
 * @typedef {import('../animate').AppearOrigin} AppearOrigin
 * @typedef {import('@floating-ui/react-dom').Placement} FloatingUIPlacement
 */

/**
 * Name of slot in which popover should fill.
 *
 * @type {string}
 */
const SLOT_NAME = 'Popover';

const slotNameContext = createContext();

// Converts the `Popover`'s legacy "position" prop to the
// new "placement" prop (used by `gloating-ui`).
const positionToPlacement = ( position ) => {
	const [ x, y, z ] = position.split( ' ' );

	if ( [ 'top', 'bottom' ].includes( x ) ) {
		let suffix = '';
		if ( ( !! z && z === 'left' ) || y === 'right' ) {
			suffix = '-start';
		} else if ( ( !! z && z === 'right' ) || y === 'left' ) {
			suffix = '-end';
		}
		return x + suffix;
	}

	return y;
};

// Given the floating-ui `placement`, compute the origin used for the entrance
// animation. The origin should be on the "opposite" side from the placement.

/** @type {Object.<FloatingUIPlacement, AppearOrigin>} */
const PLACEMENT_TO_ANIMATION_ORIGIN_MAP = {
	top: 'bottom',
	'top-start': 'bottom right',
	'top-end': 'bottom left',
	right: 'middle left',
	'right-start': 'bottom left',
	'right-end': 'top left',
	bottom: 'top',
	'bottom-start': 'top right',
	'bottom-end': 'top left',
	left: 'middle right',
	'left-start': 'bottom right',
	'left-end': 'top right',
};

/**
 *
 * @param {FloatingUIPlacement} placement A placement string from floating ui
 * @param {boolean}             rtl
 * @return {AppearOrigin} The Animation origin string
 */
const placementToAnimationOrigin = ( placement, rtl ) => {
	/** @type {AppearOrigin} */
	let animationOrigin =
		PLACEMENT_TO_ANIMATION_ORIGIN_MAP[ placement ] ?? 'middle';

	if ( rtl && /left/gi.test( animationOrigin ) ) {
		animationOrigin = animationOrigin.replace( /left/gi, 'right' );
	} else if ( rtl && /right/gi.test( animationOrigin ) ) {
		animationOrigin = animationOrigin.replace( /right/gi, 'left' );
	}

	return animationOrigin;
};

const Popover = (
	{
		range,
		animate = true,
		headerTitle,
		onClose,
		children,
		className,
		noArrow = true,
		isAlternate,
		position,
		placement: placementProp = 'bottom-start',
		offset,
		focusOnMount = 'firstElement',
		anchorRef,
		anchorRect,
		getAnchorRect,
		expandOnMobile,
		onFocusOutside,
		__unstableSlotName = SLOT_NAME,
		__unstableObserveElement,
		__unstableForcePosition = false,
		__unstableShift = false,
		...contentProps
	},
	forwardedRef
) => {
	if ( range ) {
		deprecated( 'range prop in Popover component', {
			since: '6.1',
			version: '6.3',
		} );
	}

	const arrowRef = useRef( null );
	const anchorRefFallback = useRef( null );

	const isMobileViewport = useViewportMatch( 'medium', '<' );
	const isExpanded = expandOnMobile && isMobileViewport;
	const hasArrow = ! isExpanded && ! noArrow;

	const normalizedPlacementProp = position
		? positionToPlacement( position )
		: placementProp;

	const ownerDocument = useMemo( () => {
		if ( anchorRef?.top ) {
			return anchorRef?.top.ownerDocument;
		} else if ( anchorRef?.startContainer ) {
			return anchorRef.startContainer.ownerDocument;
		} else if ( anchorRef?.current ) {
			return anchorRef.current.ownerDocument;
		} else if ( anchorRef ) {
			// This one should be deprecated.
			return anchorRef.ownerDocument;
		} else if ( anchorRect && anchorRect?.ownerDocument ) {
			return anchorRect.ownerDocument;
		} else if ( getAnchorRect ) {
			return (
				getAnchorRect( anchorRefFallback.current )?.ownerDocument ??
				document
			);
		}

		return document;
	}, [ anchorRef, anchorRect, getAnchorRect ] );

	const middleware = [
		offset ? offsetMiddleware( offset ) : undefined,
		__unstableForcePosition ? undefined : flip(),
		__unstableForcePosition
			? undefined
			: size( {
					apply( sizeProps ) {
						const { height } = sizeProps;
						if ( ! refs.floating.current ) return;
						// Reduce the height of the popover to the available space.
						Object.assign( refs.floating.current.firstChild.style, {
							maxHeight: `${ height }px`,
							overflow: 'auto',
						} );
					},
			  } ),
		__unstableShift
			? shift( {
					crossAxis: true,
					limiter: limitShift( {
						offset: ( { floating } ) => {
							// iFrame-specific correction aimed at allowing the floating element
							// to shift fully below the reference element.
							if ( ownerDocument === document ) {
								return 0;
							}
							// TODO: adapt code based on the placement
							// (see https://floating-ui.com/docs/shift#limitshift-offset)
							return {
								mainAxis: 0,
								crossAxis: -floating.height,
							};
						},
					} ),
					padding: 1, // Necessary to avoid flickering at the edge of the viewport.
			  } )
			: undefined,
		hasArrow ? arrow( { element: arrowRef } ) : undefined,
	].filter( ( m ) => !! m );
	const slotName = useContext( slotNameContext ) || __unstableSlotName;
	const slot = useSlot( slotName );

	const onDialogClose = ( type, event ) => {
		// Ideally the popover should have just a single onClose prop and
		// not three props that potentially do the same thing.
		if ( type === 'focus-outside' && onFocusOutside ) {
			onFocusOutside( event );
		} else if ( onClose ) {
			onClose();
		}
	};

	const [ dialogRef, dialogProps ] = useDialog( {
		focusOnMount,
		__unstableOnClose: onDialogClose,
		onClose: onDialogClose,
	} );

	const {
		// Positioning coordinates
		x,
		y,
		// Callback refs *not regular refs) This allows the position to be updated
		// when either elements change.
		reference,
		floating,
		// Object with "regular" refs to both "reference" and "floating"
		refs,
		// Type of CSS position property to use (absolute or fixed)
		strategy,
		update,
		placement: computedPlacement,
		middlewareData: { arrow: arrowData = {} },
	} = useFloating( { placement: normalizedPlacementProp, middleware } );

	const arrowStaticSide = {
		top: 'bottom',
		right: 'left',
		bottom: 'top',
		left: 'right',
	}[ computedPlacement.split( '-' )[ 0 ] ];

	// Update the `reference`'s ref.
	//
	// In floating-ui's terms:
	// - "reference" refers to the popover's anchor element.
	// - "floating" refers the floating popover's element.
	// A floating element can also be positioned relative to a virtual element,
	// instead of a real one — a virtual element is represented by an object
	// with the `getBoundingClientRect()` function (like real elements).
	// See https://floating-ui.com/docs/virtual-elements for more info.
	useLayoutEffect( () => {
		let resultingReferenceRef;

		if ( anchorRef?.top ) {
			resultingReferenceRef = {
				getBoundingClientRect() {
					const topRect = anchorRef.top.getBoundingClientRect();
					const bottomRect = anchorRef.bottom.getBoundingClientRect();
					return new window.DOMRect(
						topRect.x,
						topRect.y,
						topRect.width,
						bottomRect.bottom - topRect.top
					);
				},
			};
		} else if ( anchorRef?.current ) {
			// Use the anchor's reference (real element)
			resultingReferenceRef = anchorRef.current;
		} else if ( anchorRef ) {
			// TODO: Is this for older-style refs?
			resultingReferenceRef = anchorRef;
		} else if ( anchorRect ) {
			// Virtual element
			resultingReferenceRef = {
				getBoundingClientRect() {
					return anchorRect;
				},
			};
		} else if ( getAnchorRect ) {
			// Virtual element
			resultingReferenceRef = {
				getBoundingClientRect() {
					const rect = getAnchorRect( anchorRefFallback.current );
					return new window.DOMRect(
						rect.x ?? rect.left,
						rect.y ?? rect.top,
						rect.width ?? rect.right - rect.left,
						rect.height ?? rect.bottom - rect.top
					);
				},
			};
		} else if ( anchorRefFallback.current ) {
			resultingReferenceRef = anchorRefFallback.current;
		}

		if ( ! resultingReferenceRef ) {
			return;
		}

		reference( resultingReferenceRef );

		if ( ! refs.floating.current ) {
			return;
		}

		return autoUpdate(
			resultingReferenceRef,
			refs.floating.current,
			update
		);
	}, [ anchorRef, anchorRect, getAnchorRect, reference, update ] );

	// This is only needed for a smooth transition when moving blocks.
	useLayoutEffect( () => {
		if ( ! __unstableObserveElement ) {
			return;
		}
		const observer = new window.MutationObserver( update );
		observer.observe( __unstableObserveElement, { attributes: true } );

		return () => {
			observer.disconnect();
		};
	}, [ __unstableObserveElement ] );

	// If the reference element is in a different ownerDocument (e.g. iFrame),
	// we need to manually update the floating's position on scroll.
	useLayoutEffect( () => {
		if ( ownerDocument === document ) {
			return;
		}

		ownerDocument.addEventListener( 'scroll', update );
		return () => ownerDocument.removeEventListener( 'scroll', update );
	}, [ ownerDocument ] );

	/** @type {false | string} */
	const animateClassName =
		!! animate &&
		getAnimateClassName( {
			type: 'appear',
			origin: placementToAnimationOrigin( computedPlacement, isRTL() ),
		} );

	const mergedFloatingRef = useMergeRefs( [
		floating,
		dialogRef,
		forwardedRef,
	] );

	// Disable reason: We care to capture the _bubbled_ events from inputs
	// within popover as inferring close intent.

	let content = (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div
			className={ classnames(
				'components-popover',
				className,
				animateClassName,
				{
					'is-expanded': isExpanded,
					'is-alternate': isAlternate,
				}
			) }
			{ ...contentProps }
			ref={ mergedFloatingRef }
			{ ...dialogProps }
			tabIndex="-1"
			style={
				isExpanded
					? undefined
					: {
							position: strategy,
							left: Number.isNaN( x ) ? 0 : x,
							top: Number.isNaN( y ) ? 0 : y,
					  }
			}
		>
			{ /* Prevents scroll on the document */ }
			{ isExpanded && <ScrollLock /> }
			{ isExpanded && (
				<div className="components-popover__header">
					<span className="components-popover__header-title">
						{ headerTitle }
					</span>
					<Button
						className="components-popover__close"
						icon={ close }
						onClick={ onClose }
					/>
				</div>
			) }
			<div className="components-popover__content">{ children }</div>
			{ hasArrow && (
				<div
					className="components-popover__arrow"
					ref={ arrowRef }
					style={ {
						left:
							! arrowData?.x || Number.isNaN( arrowData?.x )
								? 0
								: arrowData.x,
						top:
							! arrowData?.y || Number.isNaN( arrowData?.y )
								? 0
								: arrowData.y,
						right: undefined,
						bottom: undefined,
						[ arrowStaticSide ]: '-4px',
					} }
				/>
			) }
		</div>
	);

	if ( slot.ref ) {
		content = <Fill name={ slotName }>{ content }</Fill>;
	}

	if ( anchorRef || anchorRect ) {
		return content;
	}

	return <span ref={ anchorRefFallback }>{ content }</span>;
};

const PopoverContainer = forwardRef( Popover );

function PopoverSlot( { name = SLOT_NAME }, ref ) {
	return (
		<Slot
			bubblesVirtually
			name={ name }
			className="popover-slot"
			ref={ ref }
		/>
	);
}

PopoverContainer.Slot = forwardRef( PopoverSlot );
PopoverContainer.__unstableSlotNameProvider = slotNameContext.Provider;

export default PopoverContainer;
