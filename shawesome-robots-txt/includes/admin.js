/**
 * shawesome robots.txt – script of the plugin page.
 *
 * - Example rules button in the editor.
 * - Status: check sites with an outdated status, and on request.
 * - Overview: filter by status, search, file override rows, notice.
 * - FAQ: open the question named in the URL hash.
 * - Forms with a save bar: unsaved changes, discard, leave warning.
 * - Editor: preview after save, live view and summaries of the options.
 */
( function ( c ) {
	'use strict';

	var all = function ( selector, root ) {
		return [].slice.call( ( root || document ).querySelectorAll( selector ) );
	};

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

	// Overview: counts and visibility of the rows.
	var filter = 'all',
		search = document.getElementById( 'shrt-search' );

	function applyFilter() {
		var query = search ? search.value.trim().toLowerCase() : '',
			shown = 0,
			counts = { all: 0, error: 0, warn: 0, ok: 0 };

		all( '.shrt-site' ).forEach( function ( row ) {
			var level = row.getAttribute( 'data-level' ),
				match = ( 'all' === filter || level === filter ) && -1 !== row.getAttribute( 'data-search' ).toLowerCase().indexOf( query );
			counts.all++;
			counts[ level ]++;
			row.hidden = ! match;
			shown += match ? 1 : 0;
		} );
		all( '[data-shrt-count]' ).forEach( function ( count ) {
			count.textContent = counts[ count.getAttribute( 'data-shrt-count' ) ];
		} );
		all( '.shrt-empty' ).forEach( function ( empty ) {
			empty.hidden = shown > 0;
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var chip = e.target.closest( '[data-shrt-filter]' ),
			toggle = e.target.closest( '.shrt-site__actions [aria-controls]' ),
			dismiss = e.target.closest( '[data-shrt-dismiss]' );

		if ( chip ) {
			filter = chip.getAttribute( 'data-shrt-filter' );
			all( '[data-shrt-filter]' ).forEach( function ( other ) {
				other.setAttribute( 'aria-pressed', other === chip ? 'true' : 'false' );
			} );
			applyFilter();
		}
		if ( toggle ) {
			var target = document.getElementById( toggle.getAttribute( 'aria-controls' ) ),
				open = 'true' !== toggle.getAttribute( 'aria-expanded' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			target.hidden = ! open;
		}
		if ( dismiss ) {
			var body = new FormData();
			body.append( 'action', 'shawesome_robots_txt_dismiss' );
			body.append( '_ajax_nonce', c.dismiss );
			fetch( c.url, { method: 'POST', body: body, credentials: 'same-origin' } );
			dismiss.closest( '.shrt-notice' ).remove();
		}
	} );

	if ( search ) {
		search.addEventListener( 'input', applyFilter );
	}

	// Status: three requests at a time.
	function scan( element ) {
		var body = new FormData();
		body.append( 'action', 'shawesome_robots_txt_scan' );
		body.append( '_ajax_nonce', c.nonce );
		body.append( 'site_id', element.getAttribute( 'data-shrt-status' ) );
		body.append( 'format', element.getAttribute( 'data-format' ) || 'lines' );
		element.textContent = c.scanning;
		element.setAttribute( 'aria-busy', 'true' );

		return fetch( c.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error();
				}
				element.innerHTML = json.data.html; // Escaped on the server.
				var row = element.closest( '.shrt-site' );
				if ( row ) {
					row.className = 'shrt-site is-' + json.data.level;
					row.setAttribute( 'data-level', json.data.level );
					applyFilter();
				}
			} )
			.catch( function () {
				element.textContent = c.failed;
			} )
			.then( function () {
				element.removeAttribute( 'aria-busy' );
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
		run( 'all' === link.getAttribute( 'data-shrt-scan' ) ? all( '[data-shrt-status]' ) : [ link.closest( '[data-shrt-status]' ) ] );
	} );

	run( all( '[data-shrt-status][data-outdated]' ) );

	// FAQ: open the question named in the hash, e.g. #faq-not-delivered.
	var question = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;
	if ( question && 'DETAILS' === question.tagName ) {
		question.open = true;
		window.scrollTo( 0, question.getBoundingClientRect().top + window.pageYOffset - 48 );
	}

	// Forms with a save bar: unsaved changes, discard and a warning before leaving.
	all( 'form.shrt-dirty' ).forEach( function ( form ) {
		var state = form.querySelector( '.shrt-bar__state' ),
			discard = form.querySelector( '.shrt-bar__discard' ),
			saved = state.textContent,
			initial = null,
			dirty = false,
			submitting = false;

		function snapshot() {
			return new URLSearchParams( new FormData( form ) ).toString();
		}

		function update() {
			form.dispatchEvent( new CustomEvent( 'shrt:change' ) );
			if ( null === initial ) {
				return;
			}
			dirty = snapshot() !== initial;
			state.textContent = dirty ? c.labels.dirty : saved;
			state.classList.toggle( 'is-dirty', dirty );
			discard.hidden = ! dirty;
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		form.addEventListener( 'submit', function () {
			submitting = true;
		} );
		discard.addEventListener( 'click', function () {
			form.reset();
			update();
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty && ! submitting ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		form.shrtUpdate = update;
		form.shrtStart = function () {
			update();
			initial = snapshot();
			update();
		};
	} );

	// Editor.
	var form = document.querySelector( '.shrt-editor' );
	all( 'form.shrt-dirty' ).forEach( function ( other ) {
		if ( other !== form ) {
			other.shrtStart();
		}
	} );
	if ( ! form ) {
		return;
	}

	var data = JSON.parse( form.getAttribute( 'data-shrt' ) ),
		output = form.querySelector( '.shrt-output' ),
		note = form.querySelector( '.shrt-output__note' ),
		fields = form.elements,
		view = 'preview';

	function format( text, number ) {
		return text.replace( '%d', number ).replace( '%s', number );
	}

	function mode() {
		var checked = form.querySelector( 'input[name="site_mode"]:checked' );
		return checked && checked.value ? checked.value : data.defaultMode;
	}

	function checked( name ) {
		return all( 'input[name="' + name + '[]"]:checked', form ).map( function ( input ) {
			return input.value;
		} );
	}

	// Same order and lines as shawesome_robots_txt_added_block() in PHP.
	function addedBlock() {
		var tokens = [],
			star = [],
			parts = [];

		if ( fields.crawl_delay_on.checked ) {
			star.push( 'Crawl-delay: ' + Math.min( 60, Math.max( 1, parseInt( fields.crawl_delay.value, 10 ) || 10 ) ) );
		}
		checked( 'bots' ).forEach( function ( key ) {
			if ( 1 === data.bots[ key ].length && '*' === data.bots[ key ][ 0 ] ) {
				star.push( 'Disallow: /' );
			} else {
				tokens = tokens.concat( data.bots[ key ] );
			}
		} );
		[ 'css_js', 'images', 'pdfs' ].forEach( function ( key ) {
			if ( ! fields[ key ].checked ) {
				star = star.concat( data.resources[ key ] );
			}
		} );
		checked( 'cleanup' ).forEach( function ( key ) {
			star = star.concat( data.cleanup[ key ] );
		} );
		checked( 'woo' ).forEach( function ( key ) {
			star = star.concat( data.woo[ key ] || [] );
		} );

		if ( tokens.length ) {
			parts.push( 'User-agent: ' + tokens.join( '\nUser-agent: ' ) + '\nDisallow: /' );
		}
		if ( star.length ) {
			parts.push( 'User-agent: *\n' + star.join( '\n' ) );
		}
		if ( fields.sitemap.checked ) {
			parts.push( 'Sitemap: ' + data.sitemapUrl );
		}
		return parts.length ? '# Added by shawesome robots.txt\n' + parts.join( '\n\n' ) : '';
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
		} else if ( 'block' === mode() ) {
			output.appendChild( block( c.labels.block, '#d63638', 'User-agent: *\nDisallow: /' ) );
			notes.push( data.physical ? c.labels.fileNote : c.labels.blockNote );
		} else {
			var added = addedBlock(),
				rules = null !== data.file ? data.file : fields.content.value.trim();

			if ( 'base' === mode() && data.seo ) {
				output.appendChild( block( format( c.labels.seo, data.seo ), '#dcdcde', c.labels.seoText ) );
			}
			output.appendChild( block( null !== data.file ? c.labels.file : c.labels.rules, '#2271b1', rules || c.labels.empty ) );
			if ( added && ! data.hidden ) {
				output.appendChild( block( c.labels.options, '#dba617', added ) );
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

	// Badges of the option groups: "rate", "allow" (unchecked = blocked), "block" (checked = blocked), "toggle".
	function summaries() {
		fields.crawl_delay.disabled = ! fields.crawl_delay_on.checked;

		all( '[data-shrt-summary]', form ).forEach( function ( badge ) {
			var type = badge.getAttribute( 'data-shrt-summary' ),
				boxes = all( 'input[type="checkbox"]', badge.closest( 'details' ) ),
				on = boxes.filter( function ( box ) {
					return box.checked;
				} ).length,
				text,
				active;

			if ( 'rate' === type ) {
				active = fields.crawl_delay_on.checked;
				text = active ? format( c.labels.delay, parseInt( fields.crawl_delay.value, 10 ) || 10 ) : c.labels.noLimit;
			} else if ( 'allow' === type ) {
				active = on < boxes.length;
				text = active ? format( c.labels.blocked, boxes.length - on ) : c.labels.allAllowed;
			} else if ( 'block' === type ) {
				active = on > 0;
				text = active ? format( c.labels.blocked, on ) : c.labels.noneBlocked;
			} else {
				active = on > 0;
				text = active ? c.labels.on : c.labels.off;
			}

			badge.textContent = text;
			badge.classList.toggle( 'is-active', active );
		} );
	}

	form.addEventListener( 'shrt:change', function () {
		summaries();
		render();
	} );

	all( '[data-shrt-view]', form ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			view = button.getAttribute( 'data-shrt-view' );
			all( '[data-shrt-view]', form ).forEach( function ( other ) {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );
			render();
		} );
	} );

	form.shrtStart();
}( window.shawesomeRobotsTxt ) );
