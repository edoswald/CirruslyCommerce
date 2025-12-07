( function( wp ) {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;

    registerBlockType( 'cirrusly/badges', {
        title: 'Smart Badges',
        icon: 'awards', 
        category: 'woocommerce',
        description: 'Displays Cirrusly Commerce smart badges (Low Stock, Best Seller, Sale, etc).',
        keywords: [ 'badge', 'sale', 'sticker' ],
        attributes: {
            align: { type: 'string', default: 'left' },
        },
        supports: { html: false, align: ['left', 'center', 'right'] },
        edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var ServerSideRender = wp.serverSideRender;

            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: 'Settings', initialOpen: true },
                        el( SelectControl, {
                            label: 'Alignment',
                            value: attributes.align,
                            options: [
                                { label: 'Left', value: 'left' },
                                { label: 'Center', value: 'center' },
                                { label: 'Right', value: 'right' },
                            ],
                            onChange: function( val ) { setAttributes( { align: val } ); }
                        } )
                    )
                ),
                el( 'div', Object.assign( { key: 'content' }, useBlockProps( { className: 'cirrusly-badges-block-editor' } ) ),
                    el( ServerSideRender, {
                        block: 'cirrusly/badges',
                        attributes: attributes
                    } )
                )
            ];
        },
        save: function() { return null; },
    } );
} )( window.wp );