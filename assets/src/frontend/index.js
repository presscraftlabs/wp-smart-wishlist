/**
 * WishCraft front end.
 */
import initButtons from './buttons';
import initPage from './page';

import './index.scss';

function start() {
	initButtons();
	initPage();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
