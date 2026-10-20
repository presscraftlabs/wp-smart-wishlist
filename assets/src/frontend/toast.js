/**
 * Short messages in a polite live region.
 */

let region;
let timer;

/**
 * Show a message.
 *
 * @param {string} message Text.
 * @param {Object} [link]  { href, text } to show after the text.
 */
export default function toast( message, link ) {
	if ( ! region ) {
		region = document.createElement( 'div' );
		region.className = 'wishcraft-toast';
		region.setAttribute( 'role', 'status' );
		region.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( region );
	}
	region.textContent = message;
	if ( link && link.href ) {
		const anchor = document.createElement( 'a' );
		anchor.href = link.href;
		anchor.textContent = link.text;
		region.append( ' ', anchor );
	}
	region.classList.add( 'is-visible' );
	window.clearTimeout( timer );
	timer = window.setTimeout(
		() => region.classList.remove( 'is-visible' ),
		5000
	);
}
