( function( wp ) {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var TextControl = wp.components.TextControl;
    var SelectControl = wp.components.SelectControl;
    var DateTimePicker = wp.components.DateTimePicker;

    registerBlockType( 'cirrusly/countdown', {
        title: 'Sale Countdown',
        icon: 'clock', 
        category: 'woocommerce',
        description: 'Displays a countdown timer for sales.',
        keywords: [ 'timer', 'sale', 'countdown' ],
        attributes: {
            textAlign: { type: 'string', default: 'left' },
            label: { type: 'string', default: 'Sale Ends In:' },
            useMeta: { type: 'boolean', default: true },
            manualDate: { type: 'string', default: '' },
        },
        supports: { html: false, align: ['left', 'center', 'right'] },
        edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var ServerSideRender = wp.serverSideRender;

            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: 'Timer Settings', initialOpen: true },
                        el( SelectControl, {
                            label: 'Alignment',
                            value: attributes.textAlign,
                            options: [
                                { label: 'Left', value: 'left' },
                                { label: 'Center', value: 'center' },
                                { label: 'Right', value: 'right' },
                            ],
                            onChange: function( val ) { setAttributes( { textAlign: val } ); }
                        } ),
                        el( TextControl, {
                            label: 'Label',
                            value: attributes.label,
                            onChange: function( val ) { setAttributes( { label: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Use Product Smart Date',
                            help: 'Automatically detect sale end date from product meta or rules.',
                            checked: attributes.useMeta,
                            onChange: function( val ) { setAttributes( { useMeta: val } ); }
                        } ),
                        // Show Date Picker only if NOT using Meta, or as an override
                        ! attributes.useMeta && el( 'div', { style: { marginTop: '15px' } },
                            el( 'label', {}, 'Manual End Date' ),
                            el( DateTimePicker, {
                                currentDate: attributes.manualDate,
                                onChange: function( val ) { setAttributes( { manualDate: val } ); },
                                is12Hour: true
                            } )
                        )
                    )
                ),
                el( 'div', Object.assign( { key: 'content' }, useBlockProps( { className: 'cirrusly-countdown-block-editor' } ) ),
                    el( ServerSideRender, {
                        block: 'cirrusly/countdown',
                        attributes: attributes
                    } )
                )
            ];
        },
        save: function() { return null; },
    } );
} )( window.wp );