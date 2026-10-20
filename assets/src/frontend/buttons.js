/**
 * Wishlist buttons and counters: state from GET /status, toggling, and a list picker for
 * visitors with several lists.
 */
import { __, sprintf } from '@wordpress/i18n';

import config, { api } from './api';
import toast from './toast';

const state = {
	items: {},
	lists: [],
	count: null,
	// The first GET /status, which clicks wait for so they know the visitor's lists.
	ready: Promise.resolve(),
};

/**
 * Buttons on the page.
 *
 * @param {Document|HTMLElement} root Where to look.
 * @return {HTMLElement[]} Buttons.
 */
function buttons( root = document ) {
	return Array.from( root.querySelectorAll( '[data-wishcraft-button]' ) );
}

/**
 * Show the state of every button and counter.
 */
function paint() {
	const labels = config.labels || {};
	buttons().forEach( ( button ) => {
		const saved =
			( state.items[ button.dataset.objectId ] || [] ).length > 0;
		button.classList.toggle( 'is-saved', saved );
		button.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
		const label = button.querySelector( '.wishcraft-button__label' );
		if ( label ) {
			label.textContent = saved ? labels.added : labels.add;
		}
	} );
	if ( state.count !== null ) {
		document
			.querySelectorAll(
				'[data-wishcraft-counter] .wishcraft-counter__count'
			)
			.forEach( ( node ) => {
				node.textContent = String( state.count );
				node.dataset.count = String( state.count );
			} );
	}
}

/**
 * Load the state for the buttons on the page.
 *
 * @param {Document|HTMLElement} root Where to look.
 * @return {Promise<void>} Done.
 */
export async function refresh( root = document ) {
	if ( ! config.canSave ) {
		return;
	}
	const ids = [
		...new Set(
			buttons( root ).map( ( button ) => button.dataset.objectId )
		),
	].slice( 0, 200 );
	if (
		! ids.length &&
		! document.querySelector( '[data-wishcraft-counter]' )
	) {
		return;
	}
	try {
		const data = await api( 'status', { query: { ids } } );
		state.items = { ...state.items, ...data.items };
		state.lists = data.lists || [];
		state.count = data.count;
		paint();
	} catch ( error ) {
		// Buttons keep their server-rendered state.
	}
}

/**
 * Selected variation of a variable product, when the button sits by its cart form.
 *
 * @param {HTMLElement} button Button.
 * @return {number} Variation ID or 0.
 */
function variation( button ) {
	const form = document.querySelector(
		`form.variations_form[data-product_id="${ button.dataset.objectId }"]`
	);
	const input = form && form.querySelector( 'input[name="variation_id"]' );
	return input ? parseInt( input.value, 10 ) || 0 : 0;
}

/**
 * Apply a /toggle response.
 *
 * @param {Object} data Response.
 */
function applied( data ) {
	state.items[ data.object_id ] = data.lists;
	state.count = data.count;
	paint();
	const list = state.lists.find( ( item ) => item.id === data.list_id );
	const name = list ? list.title : __( 'your wishlist', 'wp-smart-wishlist' );
	toast(
		data.added
			? /* translators: %s: list name. */ sprintf(
					__( 'Saved to %s.', 'wp-smart-wishlist' ),
					name
			  )
			: /* translators: %s: list name. */ sprintf(
					__( 'Removed from %s.', 'wp-smart-wishlist' ),
					name
			  ),
		data.added && config.page
			? {
					href: config.page,
					text: __( 'View wishlist', 'wp-smart-wishlist' ),
			  }
			: null
	);
	document.dispatchEvent(
		new window.CustomEvent( 'wishcraft:changed', { detail: data } )
	);
}

/**
 * Toggle in one list.
 *
 * @param {HTMLElement} button Button.
 * @param {number}      listId List (0: default list).
 * @return {Promise<Object|null>} Response.
 */
async function toggle( button, listId = 0 ) {
	button.setAttribute( 'aria-busy', 'true' );
	try {
		const data = await api( 'toggle', {
			method: 'POST',
			data: {
				object_id: parseInt( button.dataset.objectId, 10 ),
				variation_id: variation( button ),
				list_id: listId,
			},
		} );
		if ( ! state.lists.some( ( list ) => list.id === data.list_id ) ) {
			await refresh();
		}
		applied( data );
		return data;
	} catch ( error ) {
		toast( error.message );
		return null;
	} finally {
		button.removeAttribute( 'aria-busy' );
	}
}

let picker;

/**
 * Close the picker.
 *
 * @param {boolean} focus Return focus to its button.
 */
function closePicker( focus = true ) {
	if ( ! picker ) {
		return;
	}
	const { button } = picker;
	picker.node.remove();
	picker = null;
	button.setAttribute( 'aria-expanded', 'false' );
	if ( focus ) {
		button.focus();
	}
}

/**
 * Picker for visitors with several lists: a checkbox per list and "New list".
 *
 * @param {HTMLElement} button Button.
 */
function openPicker( button ) {
	closePicker( false );
	const node = document.createElement( 'div' );
	node.className = 'wishcraft-picker';
	node.setAttribute( 'role', 'dialog' );
	node.setAttribute(
		'aria-label',
		__( 'Save to a wishlist', 'wp-smart-wishlist' )
	);
	const saved = state.items[ button.dataset.objectId ] || [];

	const title = document.createElement( 'p' );
	title.className = 'wishcraft-picker__title';
	title.textContent = __( 'Save to', 'wp-smart-wishlist' );
	node.appendChild( title );

	state.lists.forEach( ( list ) => {
		const label = document.createElement( 'label' );
		const box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.checked = saved.includes( list.id );
		box.addEventListener( 'change', async () => {
			box.disabled = true;
			const data = await toggle( button, list.id );
			box.disabled = false;
			box.checked = data ? data.lists.includes( list.id ) : ! box.checked;
		} );
		label.append( box, ' ', list.title );
		node.appendChild( label );
	} );

	if ( config.multiple ) {
		const form = document.createElement( 'form' );
		form.className = 'wishcraft-picker__new';
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.required = true;
		input.maxLength = 190;
		input.placeholder = __( 'New list', 'wp-smart-wishlist' );
		input.setAttribute(
			'aria-label',
			__( 'New list name', 'wp-smart-wishlist' )
		);
		const submit = document.createElement( 'button' );
		submit.type = 'submit';
		submit.className = 'wishcraft-secondary';
		submit.textContent = __( 'Create', 'wp-smart-wishlist' );
		form.append( input, submit );
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			submit.disabled = true;
			try {
				const list = await api( 'lists', {
					method: 'POST',
					data: { title: input.value },
				} );
				state.lists.push( {
					id: list.id,
					title: list.title,
					is_default: list.is_default,
				} );
				await toggle( button, list.id );
				closePicker();
			} catch ( error ) {
				toast( error.message );
				submit.disabled = false;
			}
		} );
		node.appendChild( form );
	}

	button.insertAdjacentElement( 'afterend', node );
	button.setAttribute( 'aria-expanded', 'true' );
	picker = { node, button };
	const first = node.querySelector( 'input' );
	if ( first ) {
		first.focus();
	}
}

/**
 * Click on a button.
 *
 * @param {MouseEvent} event Event.
 */
async function onClick( event ) {
	const button = event.target.closest( '[data-wishcraft-button]' );
	if ( ! button ) {
		if ( picker && ! picker.node.contains( event.target ) ) {
			closePicker( false );
		}
		return;
	}
	if ( ! config.canSave ) {
		// The link goes to the login page.
		return;
	}
	event.preventDefault();
	if ( picker && picker.button === button ) {
		closePicker();
		return;
	}
	await state.ready;
	if ( config.multiple && state.lists.length > 1 ) {
		openPicker( button );
		return;
	}
	toggle( button );
}

/**
 * Start.
 */
export default function initButtons() {
	document.addEventListener( 'click', onClick );
	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && picker ) {
			closePicker();
		}
		// Links with role="button" also respond to the space bar.
		if (
			event.key === ' ' &&
			event.target.matches &&
			event.target.matches( '[data-wishcraft-button]' )
		) {
			event.preventDefault();
			event.target.click();
		}
	} );
	state.ready = refresh();
}
