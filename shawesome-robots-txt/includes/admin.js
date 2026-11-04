/**
 * shawesome robots.txt – script of the plugin page.
 *
 * - Buttons that insert example rules into the editor.
 * - Status: check sites with an outdated status, and on request.
 * - FAQ: open the question named in the URL hash.
 * - Editor: preview after save, live view, summaries and unsaved changes.
 */
( function ( c ) {
	'use strict';

	// Example rules: data-shrt-insert="replace" asks before replacing, "append" adds at the end.
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-shrt-insert]' );
		if ( ! button ) {
			return;
		}
		var field = document.getElementById( button.getAttribute( 'aria-controls' ) ),
			value = field.value.replace( /\s+$/, '' ),
			text = button.getAttribute( 'data-text' );

		if ( 'replace' === button.getAttribute( 'data-shrt-insert' ) ) {
			if ( value && ! window.confirm( button.getAttribute( 'data-confirm' ) ) ) {
				return;
			}
			field.value = text;
		} else {
			field.value = value ? value + '\n\n' + text : text;
		}
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.focus();
	} );

	// Status: three requests at a time.
	function scan( element ) {
		var body = new FormData();
		body.append( 'action', 'shawesome_robots_txt_scan' );
		body.append( '_ajax_nonce', c.nonce );
		body.append( 'site_id', element.getAttribute( 'data-shrt-status' ) );
		body.append( 'format', element.getAttribute( 'data-format' ) || 'lines' );
		element.textContent = c.scanning;

		return fetch( c.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					element.innerHTML = json.data.html; // Escaped on the server.
				} else {
					element.textContent = c.failed;
				}
			} )
			.catch( function () {
				element.textContent = c.failed;
			} );
	}

	function run( elements ) {
		var i = 0;
		function next() {
			return i < elements.length ? scan( elements[ i++ ] ).then( next ) : null;
		}
		return Promise.all( [ next(), next(), next() ] );
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '[data-shrt-scan]' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		run( 'all' === link.getAttribute( 'data-shrt-scan' )
			? [].slice.call( document.querySelectorAll( '[data-shrt-status]' ) )
			: [ link.closest( '[data-shrt-status]' ) ] );
	} );

	run( [].slice.call( document.querySelectorAll( '[data-shrt-status][data-outdated]' ) ) );

	// FAQ: open the question named in the hash, e.g. #faq-not-delivered.
	var target = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;
	if ( target && 'DETAILS' === target.tagName ) {
		target.open = true;
		window.scrollTo( 0, target.getBoundingClientRect().top + window.pageYOffset - 48 );
	}

	// Editor.
	var form = document.querySelector( '.shrt-editor' );
	if ( ! form ) {
		return;
	}

	var data = JSON.parse( form.getAttribute( 'data-shrt' ) ),
		output = form.querySelector( '.shrt-output' ),
		note = form.querySelector( '.shrt-output__note' ),
		state = form.querySelector( '.shrt-bar__state' ),
		discard = form.querySelector( '.shrt-bar__discard' ),
		fields = form.elements,
		view = 'preview',
		initial;

	function format( text, number ) {
		return text.replace( '%d', number ).replace( '%s', number );
	}

	function mode() {
		var checked = form.querySelector( 'input[name="site_mode"]:checked' );
		return checked && checked.value ? checked.value : data.defaultMode;
	}

	function optionLines() {
		var lines = [];
		if ( fields.crawl_delay_on.checked ) {
			lines.push( 'Crawl-delay: ' + Math.min( 60, Math.max( 1, parseInt( fields.crawl_delay.value, 10 ) || 10 ) ) );
		}
		[ 'css_js', 'images', 'pdfs' ].forEach( function ( key ) {
			if ( ! fields[ key ].checked ) {
				lines = lines.concat( data.blocked[ key ] );
			}
		} );
		return lines;
	}

	function block( label, color, text ) {
		var wrap = document.createElement( 'div' ),
			title = document.createElement( 'div' ),
			pre = document.createElement( 'pre' );
		wrap.className = 'shrt-block';
		wrap.style.borderLeftColor = color;
		title.className = 'shrt-block__label';
		title.textContent = label;
		pre.textContent = text;
		wrap.appendChild( title );
		wrap.appendChild( pre );
		return wrap;
	}

	function render() {
		var notes = [];
		output.textContent = '';

		if ( 'live' === view ) {
			output.appendChild( block( c.labels.live, '#8c8f94', null === data.live ? c.labels.liveError : data.live ) );
			notes.push( c.labels.liveNote );
		} else {
			var lines = optionLines(),
				rules = null !== data.file ? data.file : fields.content.value.trim();

			if ( 'base' === mode() && data.seo ) {
				output.appendChild( block( format( c.labels.seo, data.seo ), '#dcdcde', c.labels.seoText ) );
			}
			output.appendChild( block( null !== data.file ? c.labels.file : c.labels.rules, '#2271b1', rules || c.labels.empty ) );
			if ( lines.length && ! data.hidden ) {
				output.appendChild( block( c.labels.options, '#dba617', '# Added by shawesome robots.txt\nUser-agent: *\n' + lines.join( '\n' ) ) );
			}
			if ( data.paths ) {
				output.appendChild( block( c.labels.paths, '#8c8f94', data.paths ) );
			}

			if ( data.physical ) {
				notes.push( c.labels.fileNote );
			} else if ( data.hidden ) {
				notes.push( c.labels.hiddenNote );
			} else {
				notes.push( 'exact' === mode() ? c.labels.exactNote : ( data.seo ? c.labels.baseNote : '' ) );
			}
		}

		note.textContent = notes.join( ' ' ).trim();
		note.hidden = ! note.textContent;
	}

	function summaries() {
		var rate = form.querySelector( '[data-shrt-summary="rate"]' ),
			resources = form.querySelector( '[data-shrt-summary="resources"]' ),
			blocked = [ 'css_js', 'images', 'pdfs' ].filter( function ( key ) {
				return ! fields[ key ].checked;
			} ).length;

		fields.crawl_delay.disabled = ! fields.crawl_delay_on.checked;
		rate.textContent = fields.crawl_delay_on.checked ? format( c.labels.delay, parseInt( fields.crawl_delay.value, 10 ) || 10 ) : c.labels.noLimit;
		rate.classList.toggle( 'is-active', fields.crawl_delay_on.checked );
		resources.textContent = blocked ? format( c.labels.blocked, blocked ) : c.labels.allAllowed;
		resources.classList.toggle( 'is-active', blocked > 0 );
	}

	function snapshot() {
		return new URLSearchParams( new FormData( form ) ).toString();
	}

	function update() {
		summaries();
		var dirty = snapshot() !== initial;
		state.textContent = dirty ? c.labels.dirty : ( data.saved ? c.labels.saved : c.labels.clean );
		state.classList.toggle( 'is-dirty', dirty );
		discard.hidden = ! dirty;
		render();
	}

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );

	discard.addEventListener( 'click', function () {
		form.reset();
		update();
	} );

	form.querySelectorAll( '[data-shrt-view]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			view = button.getAttribute( 'data-shrt-view' );
			form.querySelectorAll( '[data-shrt-view]' ).forEach( function ( other ) {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );
			render();
		} );
	} );

	summaries();
	initial = snapshot();
	update();
}( window.shawesomeRobotsTxt ) );
