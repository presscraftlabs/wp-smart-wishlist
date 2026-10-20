/**
 * Requests to the wishcraft/v1 REST API.
 */

const config = window.wishcraftConfig || {};

/**
 * URL of a route; the root may already hold a query string (sites without pretty permalinks).
 *
 * @param {string} path  Route below the namespace.
 * @param {Object} query Query arguments.
 * @return {string} URL.
 */
export function url( path, query = {} ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) => params.append( `${ key }[]`, item ) );
		} else if ( value !== undefined && value !== null && value !== '' ) {
			params.append( key, value );
		}
	} );
	const root = config.root || '/wp-json/wishcraft/v1/';
	const search = params.toString();
	let full = root + path;
	if ( search ) {
		full += ( full.includes( '?' ) ? '&' : '?' ) + search;
	}
	return full;
}

/**
 * Call the API.
 *
 * @param {string} path             Route.
 * @param {Object} [options]        Options.
 * @param {string} [options.method] Method.
 * @param {Object} [options.data]   JSON body.
 * @param {Object} [options.query]  Query arguments.
 * @return {Promise<Object>} Response data.
 */
export async function api( path, { method = 'GET', data, query } = {} ) {
	const headers = { Accept: 'application/json', 'X-WishCraft': '1' };
	if ( config.nonce ) {
		headers[ 'X-WP-Nonce' ] = config.nonce;
	}
	if ( data ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	const response = await window.fetch( url( path, query ), {
		method,
		headers,
		credentials: 'same-origin',
		body: data ? JSON.stringify( data ) : undefined,
	} );
	const body = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		const error = new Error( body.message || response.statusText );
		error.code = body.code;
		throw error;
	}
	return body;
}

export default config;
