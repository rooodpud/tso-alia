/**
 * Alia chat widget (includes/class-widget.php). Lives inside the contact flyout.
 * Talks to POST /wp-json/alia/v1/message (+ feedback, handoff, nonce). Session memory (conversation,
 * destination, trip-planner answers) is kept in sessionStorage only and goes when the tab closes.
 * "Start over" clears it.
 */
( function () {
	'use strict';

	var cfg = window.tsAlia;
	var root = document.getElementById( 'ts-flyout' );
	var panel = document.getElementById( 'ts-alia' );
	if ( ! cfg || ! root || ! panel ) {
		return;
	}

	var KEY = 'tsAlia2';
	var log = document.getElementById( 'ts-alia-log' );
	var typing = document.getElementById( 'ts-alia-typing' );
	var typingText = typing.querySelector( '.ts-alia__typing-text' );
	var qrBox = document.getElementById( 'ts-alia-qr' );
	var form = document.getElementById( 'ts-alia-form' );
	var input = document.getElementById( 'ts-alia-input' );
	var ho = document.getElementById( 'ts-alia-handoff' );
	var head = root.querySelector( '.ts-flyout__head' );
	var menu = root.querySelector( '.ts-flyout__menu' );
	var openBtn = root.querySelector( '[data-ts-alia-open]' );
	var backBtn = panel.querySelector( '[data-ts-alia-back]' );
	var restartBtn = panel.querySelector( '[data-ts-alia-restart]' );
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var mem = load();
	var busy = false;
	var gen = 0; // incremented by "Start over" so a reply in flight is dropped
	var picked = {}; // selected multi-choice chips (interests)

	/* ----- storage ---------------------------------------------------- */

	function fresh() {
		return { sid: Math.random().toString(36).slice(2) + Date.now().toString(36), state: {}, msgs: [], qr: [] };
	}

	function load() {
		try {
			var raw = window.sessionStorage.getItem( KEY );
			if ( raw ) {
				var o = JSON.parse( raw );
				if ( o && o.sid && Array.isArray( o.msgs ) ) {
					return o;
				}
			}
		} catch ( e ) { /* storage unavailable: memory only */ }
		return fresh();
	}

	function save() {
		try {
			mem.msgs = mem.msgs.slice( -40 );
			window.sessionStorage.setItem( KEY, JSON.stringify( mem ) );
		} catch ( e ) { /* ignore */ }
	}

	/* ----- rendering -------------------------------------------------- */

	function scroll() {
		log.scrollTop = log.scrollHeight;
	}

	function addLinks( el, links ) {
		if ( ! links || ! links.length ) {
			return;
		}
		var wrap = document.createElement( 'div' );
		wrap.className = 'ts-alia__links';
		links.forEach( function ( l ) {
			if ( ! l || ! /^(https?:|tel:|mailto:)/i.test( l.url || '' ) ) {
				return;
			}
			var a = document.createElement( 'a' );
			a.className = 'ts-alia__link';
			a.textContent = l.label || l.url;
			a.href = l.url;
			if ( /^https?:/i.test( l.url ) ) {
				a.target = '_blank';
				a.rel = 'noopener';
			}
			wrap.appendChild( a );
		} );
		el.appendChild( wrap );
	}

	function bubble( who, text, links ) {
		var el = document.createElement( 'div' );
		el.className = 'ts-alia__msg ts-alia__msg--' + who;
		if ( 'bot' === who ) {
			var sr = document.createElement( 'span' );
			sr.className = 'ts-alia__sr';
			sr.textContent = 'Alia: ';
			el.appendChild( sr );
		}
		if ( text ) {
			var p = document.createElement( 'p' );
			p.textContent = text;
			el.appendChild( p );
		}
		addLinks( el, links );
		log.appendChild( el );
		scroll();
		return el;
	}

	/** Carousel of day-block cards: image, title, nights, one-line blurb, link. */
	function carousel( cards ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ts-alia__cards';
		wrap.setAttribute( 'role', 'group' );
		wrap.setAttribute( 'aria-label', 'Trip idea, ' + cards.length + ' stops' );
		wrap.tabIndex = 0;
		cards.forEach( function ( c, i ) {
			var card = document.createElement( 'article' );
			card.className = 'ts-alia__card';
			if ( c.image && /^https?:/i.test( c.image ) ) {
				var img = document.createElement( 'img' );
				img.src = c.image;
				img.alt = '';
				img.loading = 'lazy';
				card.appendChild( img );
			}
			var body = document.createElement( 'div' );
			body.className = 'ts-alia__card-body';
			var h = document.createElement( 'p' );
			h.className = 'ts-alia__card-title';
			h.textContent = ( i + 1 ) + '. ' + c.title;
			body.appendChild( h );
			var n = document.createElement( 'p' );
			n.className = 'ts-alia__card-nights';
			n.textContent = c.nights + ( 1 === c.nights ? ' night' : ' nights' ) + ( c.note ? ' · ' + c.note : '' );
			body.appendChild( n );
			if ( c.blurb ) {
				var b = document.createElement( 'p' );
				b.className = 'ts-alia__card-blurb';
				b.textContent = c.blurb;
				body.appendChild( b );
			}
			if ( c.link && /^https?:/i.test( c.link ) ) {
				var a = document.createElement( 'a' );
				a.className = 'ts-alia__link';
				a.href = c.link;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'See the page';
				a.setAttribute( 'aria-label', 'See the page for ' + c.title );
				body.appendChild( a );
			}
			card.appendChild( body );
			wrap.appendChild( card );
		} );
		log.appendChild( wrap );
		scroll();
	}

	function thumbs( logRef ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ts-alia__fb';
		var label = document.createElement( 'span' );
		label.textContent = 'Was this helpful?';
		wrap.appendChild( label );
		[ [ 1, 'Yes, helpful', '👍' ], [ -1, 'No, not helpful', '👎' ] ].forEach( function ( v ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'ts-alia__thumb';
			b.setAttribute( 'aria-label', v[ 1 ] );
			b.textContent = v[ 2 ];
			b.addEventListener( 'click', function ( e ) {
				e.stopPropagation(); // the button is removed below; see chip()
				wrap.textContent = 'Thank you for the feedback.';
				api( 'feedback', { log: logRef.id, token: logRef.token, vote: v[ 0 ] } ).catch( function () {} );
			} );
			wrap.appendChild( b );
		} );
		log.appendChild( wrap );
		scroll();
	}

	function chip( label, onClick, pressed ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'ts-alia__chip';
		b.textContent = label;
		if ( typeof pressed === 'boolean' ) {
			b.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
		}
		b.addEventListener( 'click', function ( e ) {
			// The handler removes this button from the page. The flyout closes on any document click whose
			// target is no longer inside it, so keep this click from reaching that listener.
			e.stopPropagation();
			onClick( b );
		} );
		return b;
	}

	function renderQr( list ) {
		qrBox.textContent = '';
		picked = {};
		( list || [] ).forEach( function ( q ) {
			if ( q.multi ) {
				picked[ q.plan.value ] = !! q.on;
				qrBox.appendChild( chip( q.label, function ( b ) {
					picked[ q.plan.value ] = ! picked[ q.plan.value ];
					b.setAttribute( 'aria-pressed', picked[ q.plan.value ] ? 'true' : 'false' );
				}, !! q.on ) );
				return;
			}
			if ( q.done ) {
				var d = chip( q.label, function () {
					var vals = Object.keys( picked ).filter( function ( k ) { return picked[ k ]; } );
					var names = [];
					( mem.qr || [] ).forEach( function ( x ) {
						if ( x.multi && picked[ x.plan.value ] ) { names.push( x.label ); }
					} );
					send( names.join( ', ' ) || 'No preference', { plan: { op: 'set', slot: 'interests', value: vals } } );
				} );
				d.className += ' ts-alia__chip--done';
				qrBox.appendChild( d );
				return;
			}
			qrBox.appendChild( chip( q.label, function () {
				if ( 'handoff' === q.action ) {
					openHandoff();
					return;
				}
				send( q.label, q );
			} ) );
		} );
	}

	function restoreAll() {
		log.textContent = '';
		mem.msgs.forEach( function ( m ) {
			if ( 'cards' === m.who ) {
				carousel( m.cards || [] );
			} else {
				bubble( m.who, m.text, m.links );
			}
		} );
		renderQr( mem.qr );
	}

	/* ----- network ---------------------------------------------------- */

	function api( route, body, retried ) {
		return fetch( cfg.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Alia-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			if ( 403 === r.status && ! retried ) {
				return fetch( cfg.rest + 'nonce', { credentials: 'same-origin' } )
					.then( function ( n ) { return n.json(); } )
					.then( function ( j ) {
						if ( j && j.nonce ) {
							cfg.nonce = j.nonce;
						}
						return api( route, body, true );
					} );
			}
			if ( ! r.ok ) {
				var err = new Error( 'http ' + r.status );
				err.status = r.status;
				return r.json().catch( function () { return {}; } ).then( function ( j ) {
					err.data = j;
					throw err;
				} );
			}
			return r.json();
		} );
	}

	/* ----- conversation ----------------------------------------------- */

	function delayFor( text, first ) {
		var n = ( text || '' ).length;
		var ms = Math.min( 1800, Math.max( 700, 500 + 6 * n ) );
		if ( n < 40 ) {
			ms = Math.max( 450, ms - 250 );
		}
		if ( reduce ) {
			ms = Math.round( ms / 3 );
		}
		return first ? ms : Math.round( ms * 0.9 );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
	}

	function showTyping( on ) {
		typing.hidden = ! on;
		if ( on ) {
			typingText.textContent = reduce ? 'Alia is typing…' : 'Alia is typing';
		}
	}

	function setBusy( on ) {
		busy = on;
		input.disabled = on;
		form.querySelector( '.ts-alia__send' ).disabled = on;
		panel.setAttribute( 'aria-busy', on ? 'true' : 'false' );
	}

	function present( res, myGen ) {
		var chunks = res.chunks || [];
		var cardsAfter = res.cards_after || 0;
		var i = 0;
		function done() {
			showTyping( false );
			mem.state = res.state || mem.state;
			mem.qr = res.quick_replies || [];
			save();
			renderQr( mem.qr );
			if ( res.feedback && res.log ) {
				thumbs( res.log );
			}
			return Promise.resolve();
		}
		function next() {
			if ( myGen !== gen ) {
				return Promise.resolve();
			}
			if ( i >= chunks.length ) {
				return done();
			}
			var c = chunks[ i ];
			showTyping( true );
			return wait( delayFor( c.text, 0 === i ) ).then( function () {
				if ( myGen !== gen ) {
					return;
				}
				showTyping( false );
				bubble( 'bot', c.text, c.links );
				mem.msgs.push( { who: 'bot', text: c.text, links: c.links || [] } );
				i += 1;
				if ( cardsAfter && i === cardsAfter && res.cards && res.cards.length ) {
					carousel( res.cards );
					mem.msgs.push( { who: 'cards', cards: res.cards } );
				}
				save();
				return next();
			} );
		}
		if ( ! chunks.length ) {
			return done();
		}
		return next();
	}

	function send( text, action ) {
		if ( busy ) {
			return;
		}
		text = ( text || '' ).trim();
		if ( ! text && ! action ) {
			return;
		}
		var myGen = gen;
		var body = { text: '', state: mem.state, sid: mem.sid };
		if ( action && 'more' === action.action ) {
			body.more = action.entry;
		} else if ( action && 'pick' === action.action ) {
			body.pick = action.entry;
		} else if ( action && action.plan ) {
			body.plan = action.plan;
		} else {
			body.text = text;
		}
		if ( text ) {
			bubble( 'user', text );
			mem.msgs.push( { who: 'user', text: text } );
		}
		input.value = '';
		renderQr( [] );
		save();
		setBusy( true );
		showTyping( true );
		api( 'message', body )
			.then( function ( res ) {
				return present( res, myGen );
			} )
			.catch( function () {
				if ( myGen !== gen ) {
					return;
				}
				showTyping( false );
				bubble( 'bot', 'Sorry, I could not answer just now. Please try again in a moment, or use the Contact us option.' );
			} )
			.then( function () {
				if ( myGen === gen ) {
					setBusy( false );
					input.focus();
				}
			} );
	}

	function start() {
		if ( ! mem.msgs.length ) {
			setBusy( true );
			var myGen = gen;
			showTyping( true );
			api( 'message', { text: 'hello', state: mem.state, sid: mem.sid } )
				.then( function ( res ) {
					res.feedback = false;
					return present( res, myGen );
				} )
				.catch( function () {
					showTyping( false );
					bubble( 'bot', 'Hi, I’m Alia, the virtual travel assistant. I could not load just now, please try again in a moment.' );
				} )
				.then( function () {
					if ( myGen === gen ) {
						setBusy( false );
					}
				} );
		} else {
			restoreAll();
		}
	}

	/* ----- hand-off form ---------------------------------------------- */

	function openHandoff() {
		form.hidden = true;
		qrBox.hidden = true;
		log.hidden = true;
		ho.hidden = false;
		var first = ho.querySelector( 'input[name="name"]' );
		if ( first ) {
			first.focus();
		}
		var plan = ( mem.state && mem.state.plan ) || {};
		var sum = ho.querySelector( '.ts-alia__ho-summary' );
		var n = ( plan.items || [] ).length;
		sum.textContent = n ? 'Your trip idea (' + n + ' stops) will be included.' : 'Tell the team what you have in mind.';
	}

	function closeHandoff() {
		ho.hidden = true;
		form.hidden = false;
		qrBox.hidden = false;
		log.hidden = false;
		clearHoErrors();
		input.focus();
	}

	function clearHoErrors() {
		Array.prototype.forEach.call( ho.querySelectorAll( '.ts-alia__err' ), function ( el ) {
			el.hidden = true;
			el.textContent = '';
		} );
	}

	function hoError( key, text ) {
		var el = ho.querySelector( '[data-err="' + key + '"]' );
		if ( el ) {
			el.textContent = text;
			el.hidden = false;
		}
	}

	ho.querySelector( '[data-ts-alia-ho-cancel]' ).addEventListener( 'click', closeHandoff );
	ho.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearHoErrors();
		var f = ho.elements;
		var name = f.name.value.trim();
		var email = f.email.value.trim();
		var phone = f.phone.value.trim();
		var bad = null;
		if ( ! name ) { hoError( 'name', 'Please tell us your name.' ); bad = bad || f.name; }
		if ( ! email && ! phone ) { hoError( 'contact', 'Please add an email address or a phone number.' ); bad = bad || f.email; }
		if ( email && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) { hoError( 'email', 'That email address does not look right.' ); bad = bad || f.email; }
		if ( ! f.consent.checked ) { hoError( 'consent', 'Please tick this so we can reply to you.' ); bad = bad || f.consent; }
		if ( bad ) {
			bad.focus();
			return;
		}
		var btn = ho.querySelector( '.ts-alia__send' );
		btn.disabled = true;
		btn.textContent = 'Sending…';
		api( 'handoff', {
			name: name, email: email, phone: phone, notes: f.notes.value.trim(),
			consent: 'yes', website: f.website.value, sid: mem.sid,
			plan: ( mem.state && mem.state.plan ) || {}
		} ).then( function ( res ) {
			f.name.value = ''; f.email.value = ''; f.phone.value = ''; f.notes.value = ''; f.consent.checked = false;
			closeHandoff();
			var links = res.whatsapp ? [ res.whatsapp ] : [];
			var t = 'Thank you, I’ve passed this to our team. Someone will reply to you by email or phone, usually within one working day.';
			bubble( 'bot', t, links );
			mem.msgs.push( { who: 'bot', text: t, links: links } );
			mem.qr = [];
			renderQr( [] );
			save();
		} ).catch( function ( err ) {
			var d = ( err && err.data && err.data.data ) || ( err && err.data ) || {};
			if ( 422 === err.status && d.errors ) {
				Object.keys( d.errors ).forEach( function ( k ) { hoError( k, 'Please check this.' ); } );
			} else if ( 429 === err.status ) {
				hoError( 'form', 'Please wait a little before sending another request.' );
			} else {
				hoError( 'form', 'Sorry, that could not be sent. Please try again, or use WhatsApp or the Contact us option.' );
			}
		} ).then( function () {
			btn.disabled = false;
			btn.textContent = 'Send to our team';
		} );
	} );

	/* ----- open / close ----------------------------------------------- */

	function showAlia() {
		var f = root.querySelector( '.ts-flyout__form' );
		var d = root.querySelector( '.ts-flyout__done' );
		var c = root.querySelector( '.ts-chat' );
		head.hidden = true;
		menu.hidden = true;
		if ( f ) { f.hidden = true; }
		if ( d ) { d.hidden = true; }
		if ( c ) { c.hidden = true; }
		panel.hidden = false;
		if ( ! log.getAttribute( 'data-started' ) ) {
			log.setAttribute( 'data-started', '1' );
			start();
		}
		if ( ho.hidden ) {
			input.focus();
		}
	}

	function hideAlia() {
		panel.hidden = true;
	}

	if ( openBtn ) {
		openBtn.addEventListener( 'click', showAlia );
	}
	backBtn.addEventListener( 'click', function () {
		hideAlia();
		head.hidden = false;
		menu.hidden = false;
		if ( openBtn ) {
			openBtn.focus();
		}
	} );
	restartBtn.addEventListener( 'click', function () {
		gen += 1;
		showTyping( false );
		setBusy( false );
		closeHandoff();
		try { window.sessionStorage.removeItem( KEY ); } catch ( e ) { /* ignore */ }
		mem = fresh();
		log.textContent = '';
		renderQr( [] );
		log.setAttribute( 'data-started', '1' );
		start();
		input.focus();
	} );
	// The flyout announces every view it shows; Alia hides itself when another view takes over.
	root.addEventListener( 'ts-flyout:view', function () {
		hideAlia();
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var v = input.value.trim();
		if ( v ) {
			send( v, null );
		}
	} );
}() );
