/**
 * Editor side of the WishCraft blocks. They are rendered on the server; the editor shows that
 * rendering and adds settings in the sidebar.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import buttonMeta from '../../../blocks/button/block.json';
import counterMeta from '../../../blocks/counter/block.json';
import wishlistMeta from '../../../blocks/wishlist/block.json';

/**
 * Edit component for a block with a "Show label" setting.
 *
 * @param {string} name Block name.
 * @return {Function} Component.
 */
function labelled( name ) {
	return function Edit( { attributes, setAttributes, context } ) {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'wp-smart-wishlist' ) }>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show label', 'wp-smart-wishlist' ) }
							checked={ !! attributes.showLabel }
							onChange={ ( showLabel ) =>
								setAttributes( { showLabel } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender
					block={ name }
					attributes={ attributes }
					urlQueryArgs={
						context && context.postId
							? { post_id: context.postId }
							: {}
					}
				/>
			</div>
		);
	};
}

registerBlockType( buttonMeta.name, {
	edit: labelled( buttonMeta.name ),
	save: () => null,
} );
registerBlockType( counterMeta.name, {
	edit: labelled( counterMeta.name ),
	save: () => null,
} );
registerBlockType( wishlistMeta.name, {
	edit: function Edit() {
		return (
			<div { ...useBlockProps() }>
				<ServerSideRender block={ wishlistMeta.name } />
			</div>
		);
	},
	save: () => null,
} );
