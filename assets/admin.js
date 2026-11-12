/**
 * Staging Guard screen: copy buttons, in-place confirm steps and spoken results.
 *
 * Everything works without this file: the confirm steps are simply shown, and
 * the copy buttons are hidden (the address is in a field that can be selected).
 */
( function ( wp, document ) {
	'use strict';

	var __ = wp.i18n.__;
	var speak = wp.a11y.speak;

	/**
	 * Applies a filter later scripts can hook, read lazily so they can load after this one.
	 *
	 * @param {string} name  Filter name, without the namespace.
	 * @param {*}      value Value.
	 * @param {...*}   args  Extra arguments.
	 * @return {*} The filtered value.
	 */
	function filter( name, value ) {
		if ( ! wp.hooks || ! wp.hooks.applyFilters ) {
			return value;
		}
		var args = Array.prototype.slice.call( arguments, 2 );
		return wp.hooks.applyFilters.apply( wp.hooks, [ 'axle_staging_guard.' + name, value ].concat( args ) );
	}

	function announce( message ) {
		speak( message, 'polite' );
		if ( wp.hooks && wp.hooks.doAction ) {
			wp.hooks.doAction( 'axle_staging_guard.announced', message );
		}
	}

	// Confirm steps: hidden until asked for, and focus moves into them and back.
	document.querySelectorAll( '.asg-confirm-form' ).forEach( function ( form ) {
		var opener = form.querySelector( '.asg-confirm-open' );
		var step = form.querySelector( '.asg-confirm' );
		var cancel = form.querySelector( '.asg-confirm-cancel' );

		if ( ! opener || ! step || ! cancel ) {
			return;
		}

		function close( focusOpener ) {
			step.hidden = true;
			opener.setAttribute( 'aria-expanded', 'false' );
			if ( focusOpener ) {
				opener.focus();
			}
		}

		opener.hidden = false;
		cancel.hidden = false;
		step.hidden = true;

		opener.addEventListener( 'click', function () {
			step.hidden = false;
			opener.setAttribute( 'aria-expanded', 'true' );
			var first = step.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
			announce( filter( 'confirmMessage', step.textContent.replace( /\s+/g, ' ' ).trim(), step ) );
		} );
		cancel.addEventListener( 'click', function () {
			close( true );
		} );
		step.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close( true );
			}
		} );
	} );

	// Copy buttons.
	document.querySelectorAll( '.asg-copy' ).forEach( function ( button ) {
		var source = document.querySelector( button.getAttribute( 'data-asg-copy' ) );

		if ( ! source ) {
			return;
		}

		button.addEventListener( 'click', function () {
			function done() {
				announce( filter( 'copiedMessage', __( 'Link copied.', 'axle-staging-guard' ), source.value ) );
			}

			function fallback() {
				source.focus();
				source.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( error ) {
					announce( __( 'Could not copy. Select the link and copy it by hand.', 'axle-staging-guard' ) );
				}
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( source.value ).then( done, fallback );
			} else {
				fallback();
			}
		} );
	} );

	// Say what happened after an action, for people who cannot see the notice appear.
	document.querySelectorAll( '.asg-notice' ).forEach( function ( notice ) {
		var text = notice.querySelector( 'p' );
		if ( text ) {
			announce( text.textContent.replace( /\s+/g, ' ' ).trim() );
		}
	} );
}( window.wp, document ) );
