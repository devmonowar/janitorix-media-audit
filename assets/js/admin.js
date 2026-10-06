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

	// A paid button must not pay twice for one shaky click. Forms carrying
	// the attribute below lose their submit buttons the moment they go —
	// the next deliberate click still works, because the page reloads first.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-janitorix-once' ) ) {
			return;
		}
		form.querySelectorAll( 'input[type="submit"], button[type="submit"]' ).forEach( function ( button ) {
			button.disabled = true;
		} );
	} );

	// AI suggestions without leaving the list. The form still posts normally
	// without JavaScript (the redirect flow); this only intercepts where
	// fetch exists. Every word shown comes from the server, already
	// translated — this file prints no user-facing sentences of its own.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-janitorix-ai' ) ) {
			return;
		}

		var row = form.closest ? form.closest( 'tr' ) : null;
		var box = row ? row.querySelector( 'input[name="janitorix_alt_text"]' ) : null;

		// No textbox in this row (a decorative image has none) — let the
		// normal POST carry it; the redirect flow handles every row shape.
		if ( ! box || ! window.ajaxurl || ! window.fetch || ! window.FormData ) {
			return;
		}

		e.preventDefault();

		var button = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		var note = form.querySelector( '.janitorix-ai-message' );
		var source = row.querySelector( '.janitorix-suggest-source' );

		if ( button ) {
			button.disabled = true;
		}

		var data = new window.FormData( form );
		data.set( 'action', 'janitorix_alt_ai_suggest_ajax' );

		window.fetch( window.ajaxurl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( button ) {
				button.disabled = false;
			}

			if ( payload && payload.success && payload.data && payload.data.text ) {
				box.value = payload.data.text;

				// The box now holds an AI suggestion awaiting decision, so
				// its button reads Apply — the same word the redirect flow
				// shows for a parked suggestion. The word itself travels on
				// the form, translated server-side.
				var save = box.closest ? box.closest( 'form' ) : null;
				var apply = save ? save.querySelector( 'input[type="submit"], button[type="submit"]' ) : null;

				if ( apply && form.getAttribute( 'data-janitorix-apply' ) ) {
					apply.value = form.getAttribute( 'data-janitorix-apply' );
					apply.classList.add( 'button-primary' );
				}

				if ( source && payload.data.source_label ) {
					source.textContent = payload.data.source_label;
				}

				if ( note ) {
					note.textContent = '';
				}

				return;
			}

			if ( note ) {
				note.textContent = payload && payload.data && payload.data.message ? payload.data.message : '';
			}
		} ).catch( function () {
			// The request itself failed — fall back to the normal POST,
			// which retries through the redirect flow instead of stranding
			// a disabled button on an unanswered ask.
			if ( button ) {
				button.disabled = false;
			}
			form.submit();
		} );
	} );
}() );
