/**
 * "Select all · Select none" controls for checkbox lists, with a live count. A control
 * points to its list with data-hive-select="<element id>"; disabled checkboxes are left alone.
 * When the list (or control) has data-hive-activates="<radio id>", changing the boxes also
 * selects that radio (e.g. "Individual documents" on the Export screen).
 *
 * @package Hive\Compliance
 */
( function () {
	'use strict';

	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : null;
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) {
		return text;
	};

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
				count.textContent = sprintf ? sprintf( __( '%1$d of %2$d selected', 'hive-compliance' ), checked, all.length ) : checked + ' / ' + all.length;
			}
		}

		control.querySelectorAll( '[data-hive-select-action]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var value = 'all' === button.getAttribute( 'data-hive-select-action' );
				boxes( container ).forEach( function ( box ) {
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
} )();
