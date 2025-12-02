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

            var blockProps = useBlockProps( { 
                className: 'cirrusly-msrp-block-editor',
                style: { 
                    textAlign: attributes.textAlign 
                } 
            } );
            
            var msrpStyle = {
                color: '#777',
                textDecoration: attributes.showStrikethrough ? 'line-through' : 'none',
                marginRight: '5px',
                fontWeight: attributes.isBold ? 'bold' : 'normal',
                textAlign: attributes.textAlign
            };

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
                el( 'div', blockProps,
                    el( 'span', { style: msrpStyle }, 'MSRP: $199.99' ),
                    el( 'span', { style: { fontSize: '12px', fontStyle: 'italic', color: '#888' } }, '(Placeholder)' )
                )
            ];
        },
        save: function() {
            return null;
        },
    } );
} )( window.wp );