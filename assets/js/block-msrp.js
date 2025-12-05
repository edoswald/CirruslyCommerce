( function( wp ) {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var SelectControl = wp.components.SelectControl;

    registerBlockType( 'cirrusly-commerce/msrp', {
        title: 'MSRP Display',
        icon: 'tag', 
        category: 'woocommerce',
        description: 'Displays the MSRP/List Price for the current product.',
        keywords: [ 'msrp', 'list price', 'price' ],
        attributes: {
            textAlign: {
                type: 'string',
                default: 'left',
            },
            showStrikethrough: {
                type: 'boolean',
                default: true,
            },
            isBold: {
                type: 'boolean',
                default: false,
            },
        },
        supports: {
            html: false,
            align: ['left', 'center', 'right'],
        },
edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var ServerSideRender = wp.serverSideRender; // Access global wp component

            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: 'Appearance Settings', initialOpen: true },
                        el( SelectControl, {
                            label: 'Alignment',
                            value: attributes.textAlign,
                            options: [
                                { label: 'Left', value: 'left' },
                                { label: 'Center', value: 'center' },
                                { label: 'Right', value: 'right' },
                            ],
                            onChange: function( newAlign ) { setAttributes( { textAlign: newAlign } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Show Strikethrough',
                            checked: attributes.showStrikethrough,
                            onChange: function( newVal ) { setAttributes( { showStrikethrough: newVal } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Bold Text',
                            checked: attributes.isBold,
                            onChange: function( newVal ) { setAttributes( { isBold: newVal } ); }
                        } )
                    )
                ),
                el( 'div', useBlockProps( { className: 'cirrusly-msrp-block-editor' } ),
                    // Dynamically render the PHP output in the editor
                    el( ServerSideRender, {
                        block: 'cirrusly/msrp',
                        attributes: attributes
                    } )
                )
            ];
        },
        save: function() {
            return null;
        },
    } );
} )( window.wp );