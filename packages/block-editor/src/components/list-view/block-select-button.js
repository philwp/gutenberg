/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalHStack as HStack,
	__experimentalTruncate as Truncate,
	__experimentalInputControl as InputControl,
} from '@wordpress/components';
import { forwardRef, useRef, useState, useEffect } from '@wordpress/element';
import { Icon, lock } from '@wordpress/icons';
import { SPACE, ENTER } from '@wordpress/keycodes';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import BlockIcon from '../block-icon';
import useBlockDisplayInformation from '../use-block-display-information';
import useBlockDisplayTitle from '../block-title/use-block-display-title';
import ListViewExpander from './expander';
import { useBlockLock } from '../block-lock';
import { store as blockEditorStore } from '../../store';

const SINGLE_CLICK = 1;
const DOUBLE_CLICK = 2;

function ListViewBlockSelectButton(
	{
		className,
		block: { clientId },
		onClick,
		onToggleExpanded,
		tabIndex,
		onFocus,
		onDragStart,
		onDragEnd,
		draggable,
	},
	ref
) {
	const clickHandlerTimer = useRef();
	const inputRef = useRef();
	const [ labelEditingMode, setLabelEditingMode ] = useState( false );

	const blockInformation = useBlockDisplayInformation( clientId );
	const blockTitle = useBlockDisplayTitle( {
		clientId,
		context: 'list-view',
	} );
	const [ inputValue, setInputValue ] = useState( blockTitle );
	const { isLocked } = useBlockLock( clientId );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	// The `href` attribute triggers the browser's native HTML drag operations.
	// When the link is dragged, the element's outerHTML is set in DataTransfer object as text/html.
	// We need to clear any HTML drag data to prevent `pasteHandler` from firing
	// inside the `useOnBlockDrop` hook.
	const onDragStartHandler = ( event ) => {
		event.dataTransfer.clearData();
		onDragStart?.( event );
	};

	function onKeyDownHandler( event ) {
		if ( labelEditingMode && event.keyCode === ENTER ) {
			updateBlockAttributes( clientId, {
				meta: {
					alias: inputValue,
				},
			} );
			setLabelEditingMode( false );
			return;
		}

		if ( event.keyCode === ENTER || event.keyCode === SPACE ) {
			if ( ! labelEditingMode ) {
				onClick( event );
			}
		}
	}

	useEffect( () => {
		if ( labelEditingMode ) {
			inputRef?.current?.focus();
			inputRef?.current?.select();
		}
	}, [ labelEditingMode ] );

	return (
		<>
			<Button
				className={ classnames(
					'block-editor-list-view-block-select-button',
					className
				) }
				onClick={ ( event ) => {
					clearTimeout( clickHandlerTimer.current );

					if ( labelEditingMode ) {
						event.preventDefault();
						event.stopPropagation();
						return;
					}

					if ( event.detail === SINGLE_CLICK ) {
						clickHandlerTimer.current = setTimeout( () => {
							onClick( event );
						}, 200 );
					} else if ( event.detail === DOUBLE_CLICK ) {
						setLabelEditingMode( true );
					}
				} }
				onKeyDown={ onKeyDownHandler }
				ref={ ref }
				tabIndex={ tabIndex }
				onFocus={ onFocus }
				onDragStart={ onDragStartHandler }
				onDragEnd={ onDragEnd }
				draggable={ draggable }
				href={ `#block-${ clientId }` }
				aria-hidden={ true }
			>
				<ListViewExpander onClick={ onToggleExpanded } />
				<BlockIcon icon={ blockInformation?.icon } showColors />
				<HStack
					alignment="center"
					className="block-editor-list-view-block-select-button__label-wrapper"
					justify="flex-start"
					spacing={ 1 }
				>
					<span className="block-editor-list-view-block-select-button__title">
						{ labelEditingMode ? (
							<InputControl
								ref={ inputRef }
								value={ inputValue }
								onChange={ ( nextValue ) => {
									setInputValue( nextValue ?? '' );
								} }
								onBlur={ () => {
									setLabelEditingMode( false );
								} }
							/>
						) : (
							<Truncate ellipsizeMode="auto">
								{ blockTitle }
							</Truncate>
						) }
					</span>
					{ blockInformation?.anchor && (
						<span className="block-editor-list-view-block-select-button__anchor">
							{ blockInformation.anchor }
						</span>
					) }
					{ isLocked && (
						<span className="block-editor-list-view-block-select-button__lock">
							<Icon icon={ lock } />
						</span>
					) }
				</HStack>
			</Button>
		</>
	);
}

export default forwardRef( ListViewBlockSelectButton );
