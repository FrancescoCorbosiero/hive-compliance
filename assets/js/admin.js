/**
 * Shared behaviour of the admin screens. Plain JS, no build step. Every feature is a
 * progressive enhancement: the markup works without it.
 *
 * - Combobox: a <select class="hive-combobox"> becomes a text field that filters its options.
 * - List filter: an input with data-hive-filter="<id>" hides the items of that list that do not
 *   match, with a live count.
 * - Select all / none: controls with data-hive-select="<id>" tick or untick the checkboxes of
 *   that list, with a live count (see Screen::select_all()).
 * - Tabs: [data-hive-tabs] with buttons [data-hive-tab="<panel id>"].
 * - Repeaters: [data-hive-add-row] clones the last row; [data-hive-remove-row] clears or
 *   removes one.
 * - Drop zone: a file input inside .hive-dropzone accepts drag and drop and shows the file name.
 * - Counters: [data-hive-count-checked="<id>"] shows how many boxes of that list are ticked.
 *
 * @package Hive\Compliance
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return format.replace( /%(\d+\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];
			return String( value );
		} );
	};

	/**
	 * Lower-case text without diacritics, for matching.
	 *
	 * @param {string} text Text.
	 * @return {string} Normalised text.
	 */
	function fold( text ) {
		text = String( text || '' ).toLowerCase();
		try {
			text = text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		} catch ( error ) {
			// Older engines: match accents literally.
		}
		return text.trim();
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	/* ---------------------------------------------------------------------
	 * Combobox
	 * ------------------------------------------------------------------- */

	var comboboxCount = 0;

	function enhanceSelect( select ) {
		if ( select.multiple || select.dataset.hiveComboboxReady ) {
			return;
		}
		select.dataset.hiveComboboxReady = '1';

		var id = select.id || 'hive-combobox-' + ( ++comboboxCount );
		var wrap = element( 'div', 'hive-combobox' + ( select.classList.contains( 'hive-combobox--inline' ) ? ' hive-combobox--inline' : '' ) );
		var input = element( 'input', 'hive-combobox__input' );
		var toggle = element( 'button', 'hive-combobox__toggle' );
		var list = element( 'ul', 'hive-combobox__list' );
		var options = [];
		var active = -1;
		var open = false;

		Array.prototype.forEach.call( select.options, function ( option ) {
			options.push( { value: option.value, label: option.textContent.trim(), folded: fold( option.textContent ), disabled: option.disabled, element: option } );
		} );
		var placeholder = options.filter( function ( option ) {
			return '' === option.value || '0' === option.value;
		} )[ 0 ] || null;

		// The label (for="…") must point to the visible field.
		select.id = id + '-native';
		select.classList.add( 'hive-combobox__native' );
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );

		input.type = 'text';
		input.id = id;
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id + '-listbox' );
		input.setAttribute( 'aria-haspopup', 'listbox' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'spellcheck', 'false' );
		if ( placeholder ) {
			input.placeholder = placeholder.label;
		} else {
			input.placeholder = __( 'Type to search…', 'hive-compliance' );
		}
		if ( select.disabled ) {
			input.disabled = true;
		}
		if ( select.hasAttribute( 'aria-label' ) ) {
			input.setAttribute( 'aria-label', select.getAttribute( 'aria-label' ) );
		}

		toggle.type = 'button';
		toggle.setAttribute( 'tabindex', '-1' );
		toggle.setAttribute( 'aria-label', __( 'Show options', 'hive-compliance' ) );
		toggle.innerHTML = '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';

		list.id = id + '-listbox';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		select.parentNode.insertBefore( wrap, select );
		wrap.appendChild( input );
		wrap.appendChild( toggle );
		wrap.appendChild( list );
		wrap.appendChild( select );

		function current() {
			return options.filter( function ( option ) {
				return option.value === select.value;
			} )[ 0 ] || null;
		}

		function showCurrent() {
			var option = current();
			if ( ! option || option === placeholder ) {
				input.value = '';
				wrap.classList.add( 'is-placeholder' );
			} else {
				input.value = option.label;
				wrap.classList.remove( 'is-placeholder' );
			}
		}

		function visible() {
			return Array.prototype.slice.call( list.querySelectorAll( '[role="option"]:not(.hive-combobox__option--empty)' ) );
		}

		function setActive( index ) {
			var items = visible();
			items.forEach( function ( item ) {
				item.classList.remove( 'is-active' );
			} );
			active = items.length ? Math.max( 0, Math.min( index, items.length - 1 ) ) : -1;
			if ( active >= 0 ) {
				items[ active ].classList.add( 'is-active' );
				input.setAttribute( 'aria-activedescendant', items[ active ].id );
				if ( items[ active ].scrollIntoView ) {
					items[ active ].scrollIntoView( { block: 'nearest' } );
				}
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function render( term ) {
			var folded = fold( term );
			var selected = select.value;
			var count = 0;
			list.textContent = '';
			options.forEach( function ( option, index ) {
				if ( option.disabled || option === placeholder ) {
					return;
				}
				if ( folded && -1 === option.folded.indexOf( folded ) ) {
					return;
				}
				var item = element( 'li', 'hive-combobox__option' );
				item.id = id + '-option-' + index;
				item.setAttribute( 'role', 'option' );
				item.setAttribute( 'aria-selected', option.value === selected ? 'true' : 'false' );
				item.dataset.value = option.value;
				if ( folded ) {
					var at = option.folded.indexOf( folded );
					item.appendChild( document.createTextNode( option.label.slice( 0, at ) ) );
					item.appendChild( element( 'mark', '', option.label.slice( at, at + folded.length ) ) );
					item.appendChild( document.createTextNode( option.label.slice( at + folded.length ) ) );
				} else {
					item.textContent = option.label;
				}
				list.appendChild( item );
				count++;
			} );
			if ( placeholder && ! folded ) {
				var clear = element( 'li', 'hive-combobox__option hive-combobox__option--clear', placeholder.label );
				clear.id = id + '-option-clear';
				clear.setAttribute( 'role', 'option' );
				clear.setAttribute( 'aria-selected', select.value === placeholder.value ? 'true' : 'false' );
				clear.dataset.value = placeholder.value;
				list.insertBefore( clear, list.firstChild );
			}
			if ( ! count ) {
				list.appendChild( element( 'li', 'hive-combobox__option hive-combobox__option--empty', __( 'No matches', 'hive-compliance' ) ) );
			}
			var items = visible();
			var selectedIndex = -1;
			items.forEach( function ( item, index ) {
				if ( 'true' === item.getAttribute( 'aria-selected' ) ) {
					selectedIndex = index;
				}
			} );
			setActive( folded ? 0 : Math.max( selectedIndex, 0 ) );
		}

		function openList( term ) {
			render( term );
			list.hidden = false;
			open = true;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function closeList( restore ) {
			list.hidden = true;
			open = false;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			if ( restore ) {
				showCurrent();
			}
		}

		function choose( value ) {
			if ( select.value !== value ) {
				select.value = value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			closeList( true );
		}

		input.addEventListener( 'focus', function () {
			input.select();
		} );

		input.addEventListener( 'input', function () {
			openList( input.value );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			var items;
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					if ( ! open ) {
						openList( '' );
					} else {
						setActive( active + 1 );
					}
					break;
				case 'ArrowUp':
					event.preventDefault();
					if ( open ) {
						setActive( active - 1 );
					}
					break;
				case 'Enter':
					if ( open ) {
						event.preventDefault();
						items = visible();
						if ( active >= 0 && items[ active ] ) {
							choose( items[ active ].dataset.value );
						}
					}
					break;
				case 'Escape':
					if ( open ) {
						event.preventDefault();
						event.stopPropagation();
						closeList( true );
					}
					break;
				case 'Tab':
					if ( open ) {
						closeList( true );
					}
					break;
				default:
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			// Give a click on an option time to land.
			window.setTimeout( function () {
				if ( open && ! wrap.contains( document.activeElement ) ) {
					closeList( true );
				}
			}, 120 );
		} );

		toggle.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
		} );

		toggle.addEventListener( 'click', function () {
			if ( open ) {
				closeList( true );
			} else {
				openList( '' );
				input.focus();
			}
		} );

		list.addEventListener( 'mousedown', function ( event ) {
			// Keep focus in the input while clicking an option.
			event.preventDefault();
		} );

		list.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '[role="option"]' );
			if ( item && ! item.classList.contains( 'hive-combobox__option--empty' ) ) {
				choose( item.dataset.value );
				input.focus();
			}
		} );

		list.addEventListener( 'mousemove', function ( event ) {
			var item = event.target.closest( '[role="option"]' );
			if ( item ) {
				setActive( visible().indexOf( item ) );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( open && ! wrap.contains( event.target ) ) {
				closeList( true );
			}
		} );

		// Other scripts may change the native select (repeaters, form reset).
		select.addEventListener( 'change', showCurrent );
		if ( select.form ) {
			select.form.addEventListener( 'reset', function () {
				window.setTimeout( showCurrent, 0 );
			} );
		}

		showCurrent();
	}

	document.querySelectorAll( 'select.hive-combobox' ).forEach( enhanceSelect );

	/* ---------------------------------------------------------------------
	 * List filter (long checkbox grids, placeholder picker)
	 * ------------------------------------------------------------------- */

	document.querySelectorAll( '[data-hive-filter]' ).forEach( function ( input ) {
		var container = document.getElementById( input.getAttribute( 'data-hive-filter' ) );
		if ( ! container ) {
			return;
		}
		var selector = input.getAttribute( 'data-hive-filter-items' ) || '.hive-check';
		var countNode = input.parentNode.parentNode.querySelector( '[data-hive-filter-count]' );
		var empty = null;

		function items() {
			return Array.prototype.slice.call( container.querySelectorAll( selector ) );
		}

		function apply() {
			var term = fold( input.value );
			var all = items();
			var shown = 0;
			all.forEach( function ( item ) {
				var text = item.getAttribute( 'data-hive-search' ) || item.textContent;
				var match = ! term || -1 !== fold( text ).indexOf( term );
				item.hidden = ! match;
				if ( match ) {
					shown++;
				}
			} );
			if ( countNode ) {
				/* translators: 1: shown items, 2: all items. */
				countNode.textContent = term ? sprintf( __( '%1$d of %2$d shown', 'hive-compliance' ), shown, all.length ) : '';
			}
			if ( ! shown && term ) {
				if ( ! empty ) {
					empty = element( 'p', 'hive-filter__empty', __( 'No matches.', 'hive-compliance' ) );
				}
				container.appendChild( empty );
			} else if ( empty && empty.parentNode ) {
				empty.parentNode.removeChild( empty );
			}
			// Groups (e.g. <details>) with nothing left are hidden too.
			container.querySelectorAll( '[data-hive-filter-group]' ).forEach( function ( group ) {
				var visible = Array.prototype.some.call( group.querySelectorAll( selector ), function ( item ) {
					return ! item.hidden;
				} );
				group.classList.toggle( 'is-empty', ! visible );
				if ( term && visible && 'DETAILS' === group.tagName ) {
					group.open = true;
				}
			} );
		}

		input.addEventListener( 'input', apply );
		input.addEventListener( 'search', apply );
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
			}
		} );
		if ( input.value ) {
			apply();
		}
	} );

	/* ---------------------------------------------------------------------
	 * Select all / none
	 * ------------------------------------------------------------------- */

	function boxes( container ) {
		return Array.prototype.slice.call( container.querySelectorAll( 'input[type="checkbox"]' ) ).filter( function ( box ) {
			return ! box.disabled;
		} );
	}

	function activate( id ) {
		var radio = id ? document.getElementById( id ) : null;
		if ( radio ) {
			radio.checked = true;
		}
	}

	document.querySelectorAll( '[data-hive-select]' ).forEach( function ( control ) {
		var container = document.getElementById( control.getAttribute( 'data-hive-select' ) );
		if ( ! container || ! boxes( container ).length ) {
			return;
		}
		var count = control.querySelector( '.hive-select-count' );

		function update() {
			var all = boxes( container );
			var checked = all.filter( function ( box ) {
				return box.checked;
			} ).length;
			if ( count ) {
				/* translators: 1: checked boxes, 2: all boxes. */
				count.textContent = sprintf( __( '%1$d of %2$d selected', 'hive-compliance' ), checked, all.length );
			}
		}

		control.querySelectorAll( '[data-hive-select-action]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var value = 'all' === button.getAttribute( 'data-hive-select-action' );
				boxes( container ).forEach( function ( box ) {
					// Only the rows a filter left visible.
					var item = box.closest( '.hive-check, tr, li, .hive-review-item' );
					if ( item && item.hidden ) {
						return;
					}
					if ( box.checked !== value ) {
						box.checked = value;
						box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
				if ( value ) {
					activate( control.getAttribute( 'data-hive-activates' ) );
				}
				update();
			} );
		} );

		// Several controls may share one list (top and bottom of the import review).
		document.addEventListener( 'change', function ( event ) {
			if ( container.contains( event.target ) ) {
				update();
			}
		} );

		control.hidden = false;
		update();
	} );

	document.querySelectorAll( '[data-hive-activates]:not([data-hive-select])' ).forEach( function ( container ) {
		container.addEventListener( 'change', function ( event ) {
			if ( event.target.checked ) {
				activate( container.getAttribute( 'data-hive-activates' ) );
			}
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Checked counters (e.g. "Apply 3 accepted documents")
	 * ------------------------------------------------------------------- */

	document.querySelectorAll( '[data-hive-count-checked]' ).forEach( function ( node ) {
		var container = document.getElementById( node.getAttribute( 'data-hive-count-checked' ) );
		if ( ! container ) {
			return;
		}
		var template = node.getAttribute( 'data-hive-count-template' ) || '%d';
		var button = node.closest( 'button' );

		function update() {
			var checked = boxes( container ).filter( function ( box ) {
				return box.checked;
			} ).length;
			node.textContent = sprintf( template, checked );
			if ( button ) {
				button.disabled = 0 === checked;
			}
		}

		document.addEventListener( 'change', function ( event ) {
			if ( container.contains( event.target ) ) {
				update();
			}
		} );
		update();
	} );

	// Highlight accepted import items.
	document.querySelectorAll( '.hive-review-item input[name="accept[]"]' ).forEach( function ( box ) {
		var item = box.closest( '.hive-review-item' );
		function sync() {
			item.classList.toggle( 'is-accepted', box.checked );
		}
		box.addEventListener( 'change', sync );
		sync();
	} );

	/* ---------------------------------------------------------------------
	 * Tabs
	 * ------------------------------------------------------------------- */

	document.querySelectorAll( '[data-hive-tabs]' ).forEach( function ( tabs ) {
		var buttons = Array.prototype.slice.call( tabs.querySelectorAll( '[data-hive-tab]' ) );
		if ( ! buttons.length ) {
			return;
		}
		var panels = buttons.map( function ( button ) {
			return document.getElementById( button.getAttribute( 'data-hive-tab' ) );
		} );

		function select( index ) {
			buttons.forEach( function ( button, i ) {
				var on = i === index;
				button.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				button.setAttribute( 'tabindex', on ? '0' : '-1' );
				if ( panels[ i ] ) {
					panels[ i ].hidden = ! on;
				}
			} );
		}

		buttons.forEach( function ( button, index ) {
			button.setAttribute( 'role', 'tab' );
			if ( panels[ index ] ) {
				panels[ index ].setAttribute( 'role', 'tabpanel' );
				panels[ index ].setAttribute( 'aria-labelledby', button.id || '' );
			}
			button.addEventListener( 'click', function () {
				select( index );
			} );
			button.addEventListener( 'keydown', function ( event ) {
				var next = index;
				if ( 'ArrowRight' === event.key ) {
					next = ( index + 1 ) % buttons.length;
				} else if ( 'ArrowLeft' === event.key ) {
					next = ( index - 1 + buttons.length ) % buttons.length;
				} else {
					return;
				}
				event.preventDefault();
				select( next );
				buttons[ next ].focus();
			} );
		} );

		var initial = buttons.map( function ( button ) {
			return 'true' === button.getAttribute( 'aria-selected' );
		} ).indexOf( true );
		select( initial >= 0 ? initial : 0 );
		tabs.classList.add( 'hive-tabs--ready' );
	} );

	/* ---------------------------------------------------------------------
	 * Repeaters
	 * ------------------------------------------------------------------- */

	function repeaterBody( name ) {
		var table = document.querySelector( '[data-hive-repeater="' + name + '"]' );
		return table ? table.querySelector( 'tbody' ) : null;
	}

	function renumber( body ) {
		Array.prototype.forEach.call( body.children, function ( row, index ) {
			row.querySelectorAll( 'input, select' ).forEach( function ( field ) {
				field.name = field.name.replace( /\[\d+\]/, '[' + index + ']' );
			} );
		} );
	}

	function clearRow( row ) {
		row.querySelectorAll( 'input' ).forEach( function ( input ) {
			input.value = '';
			input.classList.remove( 'hive-invalid' );
		} );
		row.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.selectedIndex = 0;
		} );
		row.querySelectorAll( '.hive-field-message' ).forEach( function ( message ) {
			message.remove();
		} );
	}

	document.querySelectorAll( '[data-hive-add-row]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var body = repeaterBody( button.getAttribute( 'data-hive-add-row' ) );
			if ( ! body || ! body.lastElementChild ) {
				return;
			}
			var row = body.lastElementChild.cloneNode( true );
			clearRow( row );
			body.appendChild( row );
			renumber( body );
			var first = row.querySelector( 'input, select' );
			if ( first ) {
				first.focus();
			}
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-hive-remove-row]' );
		if ( ! button ) {
			return;
		}
		var row = button.closest( 'tr' );
		var body = row ? row.parentNode : null;
		if ( ! body ) {
			return;
		}
		if ( body.children.length > 1 ) {
			body.removeChild( row );
		} else {
			clearRow( row );
		}
		renumber( body );
	} );

	document.querySelectorAll( '[data-hive-remove-row]' ).forEach( function ( button ) {
		button.hidden = false;
	} );

	/* ---------------------------------------------------------------------
	 * Drop zone
	 * ------------------------------------------------------------------- */

	document.querySelectorAll( '.hive-dropzone' ).forEach( function ( zone ) {
		var input = zone.querySelector( 'input[type="file"]' );
		var name = zone.querySelector( '.hive-dropzone__file' );
		if ( ! input ) {
			return;
		}
		zone.classList.add( 'is-enhanced' );

		function show() {
			var file = input.files && input.files[ 0 ];
			zone.classList.toggle( 'has-file', !! file );
			if ( name ) {
				name.textContent = file ? file.name + ' (' + Math.max( 1, Math.round( file.size / 1024 ) ) + ' KB)' : '';
			}
		}

		zone.addEventListener( 'click', function ( event ) {
			if ( event.target !== input ) {
				input.click();
			}
		} );
		zone.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				input.click();
			}
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				zone.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				zone.classList.remove( 'is-over' );
			} );
		} );
		zone.addEventListener( 'drop', function ( event ) {
			if ( event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length ) {
				try {
					input.files = event.dataTransfer.files;
				} catch ( error ) {
					return;
				}
				show();
			}
		} );
		input.addEventListener( 'change', show );
		show();
	} );

	/* ---------------------------------------------------------------------
	 * Copy buttons
	 * ------------------------------------------------------------------- */

	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		var field = document.createElement( 'textarea' );
		field.value = text;
		field.setAttribute( 'readonly', '' );
		field.style.position = 'absolute';
		field.style.left = '-9999px';
		document.body.appendChild( field );
		field.select();
		document.execCommand( 'copy' );
		document.body.removeChild( field );
		return Promise.resolve();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.hive-copy' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		copy( button.getAttribute( 'data-copy' ) ).then( function () {
			var label = button.textContent;
			button.textContent = __( 'Copied', 'hive-compliance' );
			button.classList.add( 'is-copied' );
			window.setTimeout( function () {
				button.textContent = label;
				button.classList.remove( 'is-copied' );
			}, 1500 );
		} );
	} );
} )();
