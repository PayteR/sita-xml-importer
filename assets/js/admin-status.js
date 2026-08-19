/**
 * Import log screen: live status polling and the "Import now" cooldown timer.
 *
 * Configuration (ajax URL, nonce and translated strings) is provided by
 * wp_localize_script() as the global `sitaXmlImporterStatus`.
 */
( function () {
	var cfg = window.sitaXmlImporterStatus || {};

	var statusEl = document.getElementById( 'sxi-status' );
	if ( ! statusEl ) {
		return;
	}

	var ajaxUrl = cfg.ajaxUrl;
	var nonce = cfg.nonce;
	var idleText = cfg.idleText;
	var runningText = cfg.runningText;
	var blockedHintText = cfg.blockedHintText;
	var justStarted = !! cfg.justStarted;
	var seenRunning = statusEl.textContent.indexOf( '…' ) !== -1;
	var attempts = 0;

	function poll() {
		attempts++;
		var body = new URLSearchParams();
		body.append( 'action', 'sita_xml_importer_status' );
		body.append( '_ajax_nonce', nonce );
		fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) { return; }
				if ( res.data.running ) {
					seenRunning = true;
					statusEl.textContent = runningText;
					setTimeout( poll, 3000 );
				} else if ( seenRunning ) {
					// A run we were watching just finished - reload to refresh the log.
					window.location.reload();
				} else if ( justStarted && attempts < 8 ) {
					// The background run may not have grabbed the lock yet.
					statusEl.textContent = runningText;
					setTimeout( poll, 2000 );
				} else if ( justStarted ) {
					// Queued but never started within the grace window -
					// most likely a blocked loopback / disabled WP-Cron.
					statusEl.textContent = blockedHintText;
				} else {
					statusEl.textContent = idleText;
				}
			} )
			.catch( function () {} );
	}
	poll();

	// Live countdown on the "Import now" cooldown: tick the label down and
	// re-enable the button at zero (the server stays authoritative - a click
	// is still validated there).
	var cd = document.getElementById( 'sxi-cooldown' );
	var btn = document.getElementById( 'sxi-import-now' );
	if ( cd && btn ) {
		var remaining = parseInt( cd.getAttribute( 'data-remaining' ), 10 ) || 0;
		var cooldownText = cfg.cooldownText;
		var fmt = function ( s ) {
			var m = Math.floor( s / 60 ), ss = s % 60;
			return m + ':' + ( ss < 10 ? '0' : '' ) + ss;
		};
		var tick = function () {
			if ( remaining > 0 ) {
				cd.textContent = cooldownText.replace( '%s', fmt( remaining ) );
				remaining--;
				setTimeout( tick, 1000 );
			} else {
				cd.textContent = '';
				if ( statusEl.textContent.indexOf( '…' ) === -1 ) {
					btn.disabled = false;
					btn.removeAttribute( 'disabled' );
				}
			}
		};
		if ( remaining > 0 ) { tick(); }
	}
} )();
