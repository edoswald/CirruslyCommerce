( function( wp ) {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;

    registerBlockType( 'cirrusly/discount-notice', {
        title: 'Automated Discount Notice',
        icon: 'megaphone', 
        category: 'woocommerce',
        description: 'Conditional banner that appears only when a Google Automated Discount is active.',
        keywords: [ 'discount', 'notice', 'google' ],
        attributes: {
            message: { type: 'string', default: '⚡ Exclusive Price Unlocked!' },
        },
        supports: { html: false },
        edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var ServerSideRender = wp.serverSideRender;

            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: 'Notice Content', initialOpen: true },
                        el( TextControl, {
                            label: 'Success Message',
                            value: attributes.message,
                            onChange: function( val ) { setAttributes( { message: val } ); },
                            help: 'This text is only visible when a user has an active discount session.'
                        } )
                    )
                ),
                el( 'div', useBlockProps( { className: 'cirrusly-discount-block-editor' } ),
                    el( ServerSideRender, {
                        block: 'cirrusly/discount-notice',
                        attributes: attributes
                    } )
                )
            ];
        },
        save: function() { return null; },
    } );
} )( window.wp );