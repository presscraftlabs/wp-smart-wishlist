/**
 * The wishlist page: actions through the REST API, then the page markup again from GET /view.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { refresh as refreshButtons } from './buttons';
import toast from './toast';

/**
 * Turn on the controls that need JavaScript.
 *
 * @param {HTMLElement} page Page element.
 */
function enhance( page ) {
	page.querySelectorAll(
		'[data-wishcraft-action][hidden], .wishcraft-list__actions[hidden], .wishcraft-item__move[hidden]'
	).forEach( ( node ) => {
		node.hidden = false;
	} );
	page.querySelectorAll( '[data-wishcraft-field]' ).forEach( ( node ) => {
		node.disabled = false;
	} );
}

/**
 * Page URL for a list.
 *
 * @param {number} listId List.
 * @return {string} URL.
 */
function pageUrl( listId ) {
	const location = new URL( window.location.href );
	if ( listId ) {
		location.searchParams.set( 'list', String( listId ) );
	} else {
		location.searchParams.delete( 'list' );
	}
	return location.toString();
}

/**
 * Render the page again.
 *
 * @param {HTMLElement} page   Page element.
 * @param {number}      listId List to show.
 * @return {Promise<HTMLElement>} New page element.
 */
async function reload( page, listId ) {
	const token = page.dataset.token || '';
	const target = pageUrl( listId );
	const data = await api( 'view', {
		query: { list: listId, token, url: target },
	} );
	const holder = document.createElement( 'div' );
	holder.innerHTML = data.html;
	const fresh = holder.firstElementChild;
	page.replaceWith( fresh );
	enhance( fresh );
	if ( ! token ) {
		window.history.replaceState(
			null,
			'',
			pageUrl( parseInt( fresh.dataset.list, 10 ) || 0 )
		);
	}
	refreshButtons( fresh );
	return fresh;
}

/**
 * Run an action and reload, reporting errors.
 *
 * @param {HTMLElement}      page   Page.
 * @param {Function}         action Async action returning the list to show (or undefined).
 * @param {HTMLElement|null} busy   Element to mark busy.
 */
async function run( page, action, busy = null ) {
	if ( busy ) {
		busy.setAttribute( 'aria-busy', 'true' );
	}
	try {
		const listId = await action();
		await reload(
			page,
			listId === undefined
				? parseInt( page.dataset.list, 10 ) || 0
				: listId
		);
	} catch ( error ) {
		toast( error.message );
		if ( busy ) {
			busy.removeAttribute( 'aria-busy' );
		}
	}
}

/**
 * Open the list dialog.
 *
 * @param {HTMLElement}      page   Page.
 * @param {HTMLElement|null} source Edit button with the list's values, or null for a new list.
 */
function openDialog( page, source ) {
	const dialog = page.querySelector( '[data-wishcraft-dialog="list"]' );
	if ( ! dialog || typeof dialog.showModal !== 'function' ) {
		return;
	}
	const form = dialog.querySelector( 'form' );
	const heading = dialog.querySelector( 'h2' );
	form.reset();
	form.dataset.mode = source ? 'edit' : 'new';
	heading.textContent = source
		? heading.dataset.titleEdit
		: heading.dataset.titleNew;
	if ( source ) {
		form.elements.title.value = source.dataset.title || '';
		form.elements.description.value = source.dataset.description || '';
		if ( form.elements.privacy ) {
			form.elements.privacy.value = source.dataset.privacy || 'private';
		}
	}
	dialog.querySelector( '.wishcraft-dialog__error' ).hidden = true;
	dialog.showModal();
}

/**
 * Wire one page (events are delegated to the document, so reloads keep working).
 */
export default function initPage() {
	document.querySelectorAll( '[data-wishcraft-page]' ).forEach( enhance );

	document.addEventListener( 'click', ( event ) => {
		const control = event.target.closest( '[data-wishcraft-action]' );
		const page = control && control.closest( '[data-wishcraft-page]' );
		if ( ! page ) {
			return;
		}
		const action = control.dataset.wishcraftAction;
		const listId = parseInt( page.dataset.list, 10 ) || 0;
		if ( action === 'remove' ) {
			event.preventDefault();
			const item = control.closest( '[data-item-id]' );
			run(
				page,
				() =>
					api( `items/${ item.dataset.itemId }`, {
						method: 'DELETE',
					} ).then( () => undefined ),
				item
			);
		} else if ( action === 'new-list' ) {
			openDialog( page, null );
		} else if ( action === 'edit-list' ) {
			openDialog( page, control );
		} else if ( action === 'delete-list' ) {
			// eslint-disable-next-line no-alert
			if ( window.confirm( control.dataset.confirm ) ) {
				run( page, () =>
					api( `lists/${ listId }`, { method: 'DELETE' } ).then(
						() => 0
					)
				);
			}
		} else if ( action === 'copy-link' ) {
			const input = control.parentNode.querySelector(
				'[data-wishcraft-share-url]'
			);
			const done = () => toast( control.dataset.copied );
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard
					.writeText( input.value )
					.then( done, () => {
						input.select();
					} );
			} else {
				input.select();
				document.execCommand( 'copy' );
				done();
			}
		} else if ( action === 'close' ) {
			control.closest( 'dialog' ).close();
		}
	} );

	document.addEventListener( 'change', ( event ) => {
		const field = event.target.closest( '[data-wishcraft-field]' );
		const page = field && field.closest( '[data-wishcraft-page]' );
		if ( ! page ) {
			return;
		}
		const item = field.closest( '[data-item-id]' );
		const id = item.dataset.itemId;
		const name = field.dataset.wishcraftField;
		if ( name === 'move' ) {
			if ( field.value ) {
				run(
					page,
					() =>
						api( `items/${ id }/move`, {
							method: 'POST',
							data: { list_id: parseInt( field.value, 10 ) },
						} ).then( () => undefined ),
					item
				);
			}
			return;
		}
		const value =
			name === 'quantity'
				? Math.max( 1, parseInt( field.value, 10 ) || 1 )
				: field.value;
		api( `items/${ id }`, { method: 'PATCH', data: { [ name ]: value } } )
			.then( () => toast( __( 'Saved.', 'wp-smart-wishlist' ) ) )
			.catch( ( error ) => toast( error.message ) );
	} );

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( '[data-wishcraft-form="list"]' );
		const page = form && form.closest( '[data-wishcraft-page]' );
		if ( ! page ) {
			return;
		}
		event.preventDefault();
		const data = {
			title: form.elements.title.value,
			description: form.elements.description.value,
		};
		if ( form.elements.privacy ) {
			data.privacy = form.elements.privacy.value;
		}
		const editing = form.dataset.mode === 'edit';
		const listId = parseInt( page.dataset.list, 10 ) || 0;
		const request = editing
			? api( `lists/${ listId }`, { method: 'PATCH', data } )
			: api( 'lists', { method: 'POST', data } );
		request
			.then( ( list ) => {
				form.closest( 'dialog' ).close();
				return reload( page, list.id );
			} )
			.catch( ( error ) => {
				const message = form.querySelector(
					'.wishcraft-dialog__error'
				);
				message.textContent = error.message;
				message.hidden = false;
			} );
	} );
}
