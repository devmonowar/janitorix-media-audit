/*
 * Janitorix Media Audit — admin script.
 *
 * The two behaviours the images screen needs. Everything that varies
 * (translated strings, the confirm-actions setting) arrives on the
 * `janitorixMediaAudit` object that Menu::enqueue() prints ahead of this file,
 * so this stays a static, cacheable asset with no PHP in it.
 */
( function () {
	var data = window.janitorixMediaAudit || {};

	// Any form that carries its own prompt. The screens set the attribute
	// instead of an inline onsubmit handler, so no page prints JavaScript.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}

		var message = form.getAttribute( 'data-janitorix-confirm' );
		if ( ! message ) {
			return;
		}

		if ( ! window.confirm( message ) ) {
			e.preventDefault();
			return;
		}

		// Permanent deletion is refused server-side unless this is stamped,
		// so it is only ever set after the person has actually agreed.
		var field = form.getAttribute( 'data-janitorix-confirm-field' );
		if ( field && form.elements[ field ] ) {
			form.elements[ field ].value = '1';
		}
	} );

	// Confirmation states the count, as the UI spec requires.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || 'janitorix-bulk-form' !== form.id ) {
			return;
		}

		var n = form.querySelectorAll( 'input[name="images[]"]:checked' ).length;
		if ( 0 === n ) {
			window.alert( data.selectNothing );
			e.preventDefault();
			return;
		}

		// A decision is not a deletion. Confirming it would train people to
		// click through the dialog that does guard a deletion.
		if ( form.querySelector( 'button[name="decision"]:focus' ) ) {
			return;
		}

		// Confirmation turned off. The empty-selection guard above stays — it
		// prevents a mistake rather than confirming an intention, and the
		// Safety Engine still checks every image on the server.
		if ( ! data.confirmActions ) {
			return;
		}

		if ( ! window.confirm( n + ' ' + data.confirmTrash ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( 'janitorix-check-all' !== e.target.id ) {
			return;
		}
		document.querySelectorAll( 'input[name="images[]"]' ).forEach( function ( box ) {
			box.checked = e.target.checked;
		} );
	} );
}() );
