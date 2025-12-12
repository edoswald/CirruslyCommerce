jQuery(document).ready(function($) {
    // UPDATED VAR NAMES
    var cirrusly_ship_config = cirrusly_pricing_vars.ship_config;
    var cirrusly_id_map = cirrusly_pricing_vars.id_map;

    function getClassData(id) {
        if (typeof cirrusly_id_map !== 'undefined' && cirrusly_id_map[id]) {
            var slug = cirrusly_id_map[id];
            if (cirrusly_ship_config['classes'][slug]) return cirrusly_ship_config['classes'][slug];
        }
        return cirrusly_ship_config['classes']['default'] || {cost:10.00};
    }
    
    function getShippingRevenue(price) {
        var tiers = cirrusly_ship_config['revenue_tiers']; if (!tiers) return 0;
        for (var i=0; i<tiers.length; i++) { if (price >= tiers[i].min && price <= tiers[i].max) return tiers[i].charge; }
        return 0;
    }
    
    function getFee(total_inc) {
        var mode = cirrusly_ship_config.profile_mode || 'single';
        var pct = cirrusly_ship_config.payment_pct ? (cirrusly_ship_config.payment_pct/100) : 0.029;
        var flat = cirrusly_ship_config.payment_flat ? cirrusly_ship_config.payment_flat : 0.30;
        
        var fee1 = (total_inc * pct) + flat;
        
        if ( mode === 'multi' ) {
            var pct2 = cirrusly_ship_config.payment_pct_2 ? (cirrusly_ship_config.payment_pct_2/100) : 0.0349;
            var flat2 = cirrusly_ship_config.payment_flat_2 ? cirrusly_ship_config.payment_flat_2 : 0.49;
            var split = cirrusly_ship_config.profile_split ? (cirrusly_ship_config.profile_split/100) : 1.0;
            
            var fee2 = (total_inc * pct2) + flat2;
            return (fee1 * split) + (fee2 * (1 - split));
        }
        
        return fee1;
    }
    
    function getContext($el) {
        var $c = $el.closest('form, .woocommerce_variation');
        
        if($c.hasClass('woocommerce_variation')) return { 
            reg: $c.find('input[name^="variable_regular_price"]'), 
            sale: $c.find('input[name^="variable_sale_price"]'), 
            cost: $c.find('input[name^="_cogs_total_value"]'), 
            min: $c.find('input[name^="_auto_pricing_min_price"]'), 
            msrp: $c.find('input[name^="_alg_msrp"]'),
            ship: $c.find('input[name^="_cirrusly_est_shipping"]'), 
            shipClass: $c.find('select[name^="variable_shipping_class"]'), 
            // Updated Selectors
            display: $c.find('.cirrusly-profit-display'), 
            matrix: $c.find('.cirrusly-shipping-matrix'),
            rounding: $c.find('.cirrusly-sale-rounding')
        };
        else return { 
            reg: $c.find('#_regular_price').length ? $c.find('#_regular_price') : $c.find('#_subscription_price'),
            sale: $c.find('#_sale_price').length ? $c.find('#_sale_price') : $c.find('#_subscription_sale_price'),
            cost: $c.find('#_cogs_total_value'),
            min: $c.find('#_auto_pricing_min_price'), 
            msrp: $c.find('#_alg_msrp'),
            ship: $c.find('#_cirrusly_est_shipping'), 
            shipClass: $c.find('#product_shipping_class'), 
            display: $('.cirrusly-profit-display'), 
            matrix: $('.cirrusly-shipping-matrix'),
            rounding: $('.cirrusly-sale-rounding')
        };
    }
    
    function applyRounding(price, strategy) {
        if (!price || isNaN(price)) return 0;
        price = parseFloat(price);
        if (strategy === '99') {
            return Math.floor(price) + 0.99;
        } else if (strategy === '50') {
            var decimal = price - Math.floor(price);
            if (decimal < 0.25) return Math.floor(price);
            if (decimal >= 0.25 && decimal < 0.75) return Math.floor(price) + 0.50;
            return Math.ceil(price);
        } else if (strategy === 'nearest_5') {
            return Math.round(price / 5) * 5;
        }
        return parseFloat(price.toFixed(2));
    }

    function updateMetrics($el) {
        var ctx = getContext($el);
        if (!ctx.reg || !ctx.reg.length) return;

        var reg=parseFloat(ctx.reg.val())||0, sale=parseFloat(ctx.sale.val())||0, cost=parseFloat(ctx.cost.val())||0, min=parseFloat(ctx.min.val())||0, ship=parseFloat(ctx.ship.val())||0;
        var price = (sale > 0 && sale < reg) ? sale : reg;
        var total_cost = cost + ship;
        
        var shipClassId = ctx.shipClass.val();
        var classData = getClassData(shipClassId);

        if (price > 0 && total_cost > 0) {
            var shipRev = getShippingRevenue(price);
            var total_inc = price + shipRev;
            var gross = total_inc - total_cost;
            
            var fee = getFee(total_inc);
            var net = gross - fee;
            var margin = (net/price)*100;
            
            var floor_html = '';
            if ( min > 0 ) {
                var floor_ship_rev = getShippingRevenue(min);
                var floor_gross = min - total_cost + floor_ship_rev;
                var floor_margin = (floor_gross / min) * 100;
                var color = floor_margin < 0 ? 'red' : (floor_margin < 10 ? 'orange' : '#777');
                floor_html = ' | Floor Margin: <span style="font-weight:bold;color:'+color+'">' + floor_margin.toFixed(0) + '%</span>';
                if ( floor_margin < 0 ) ctx.min.css('border-color', 'red'); else ctx.min.css('border-color', '');
            }

            ctx.display.find('.cirrusly-profit-val').text('$'+net.toFixed(2));
            ctx.display.find('.cirrusly-margin-val').html(margin.toFixed(1)+'%' + floor_html);
            
            if(ctx.matrix.length && classData && classData.matrix === true) {
                ctx.matrix.show();
                var html = '', rules = cirrusly_ship_config['matrix_rules'];
                var rulesObj = Array.isArray(rules) ? rules : Object.values(rules);
                if(rulesObj.length) {
                    $.each(rulesObj, function(k,v){
                        var feeScenario = getFee(price + shipRev);
                        var netScenario = (price - cost - feeScenario + shipRev) - (ship * v.cost_mult);
                        var marginScenario = (netScenario / price) * 100;
                        var cls = netScenario > 0 ? 'prof-green' : 'prof-red';
                        html += '<div class="cirrusly-matrix-item '+cls+'"><span style="display:block;font-weight:bold;">'+v.label+'</span>$'+netScenario.toFixed(2)+' <small style="display:block;font-size:9px;">('+marginScenario.toFixed(0)+'%)</small></div>';
                    });
                    ctx.matrix.html(html);
                }
            } else {
                ctx.matrix.hide();
            }
        }
    }

    $(document).on('change', '.cirrusly-tool-reg', function() {
        var strategy = $(this).val();
        if(!strategy) return;
        var ctx = getContext($(this));
        if (!ctx.msrp || !ctx.msrp.length) return;

        var msrp = parseFloat(ctx.msrp.val()) || 0;
        var cost = parseFloat(ctx.cost.val()) || 0;
        var ship = parseFloat(ctx.ship.val()) || 0;
        var total_cost = cost + ship;
        var rounding = ctx.rounding.val();
        var new_price = 0;

        if (strategy.indexOf('msrp_') === 0 && msrp > 0) {
            if (strategy === 'msrp_exact') new_price = msrp;
            if (strategy === 'msrp_sub_05') new_price = msrp * 0.95;
            if (strategy === 'msrp_sub_10') new_price = msrp * 0.90;
        } else if (strategy.indexOf('margin_') === 0 && total_cost > 0) {
            var margin = parseInt(strategy.replace('margin_', '')) / 100;
            new_price = total_cost / (1 - margin);
        }

        if (new_price > 0) {
            new_price = applyRounding(new_price, rounding);
            ctx.reg.val(new_price.toFixed(2)).trigger('change');
        }
        $(this).val('');
    });

    $(document).on('change', '.cirrusly-tool-sale', function() {
        var strategy = $(this).val();
        var ctx = getContext($(this));
        if (!ctx.reg || !ctx.reg.length) return;
        
        if (strategy === 'clear') {
            ctx.sale.val('').trigger('change');
            $(this).val('');
            return;
        }

        var reg = parseFloat(ctx.reg.val()) || 0;
        var msrp = parseFloat(ctx.msrp.val()) || 0;
        var rounding = ctx.rounding.val();
        var new_price = 0;

        if (strategy.indexOf('msrp_') === 0 && msrp > 0) {
            var pct = parseInt(strategy.replace('msrp_', '')) / 100;
            new_price = msrp * (1 - pct);
        } else if (strategy.indexOf('reg_') === 0 && reg > 0) {
            var pct = parseInt(strategy.replace('reg_', '')) / 100;
            new_price = reg * (1 - pct);
        }

        if (new_price > 0) {
            new_price = applyRounding(new_price, rounding);
            ctx.sale.val(new_price.toFixed(2)).trigger('change');
        }
        $(this).val('');
    });

    $('#woocommerce-product-data').on('keyup change', 'input, select', function() { 
        updateMetrics($(this)); 
    });
    
    $(document).on('change', '[name^="variable_shipping_class"], #product_shipping_class', function() {
        var ctx = getContext($(this)); var data = getClassData($(this).val());
        ctx.ship.val(data.cost.toFixed(2)).change();
        updateMetrics($(this));
    });
    
    if( $('#_regular_price').length || $('#_subscription_price').length ) { 
        setTimeout(function(){ updateMetrics( $('#_regular_price').length ? $('#_regular_price') : $('#_subscription_price') ); }, 500); 
    }
    
    $('#woocommerce-product-data').on( 'woocommerce_variations_loaded', function() { $('.woocommerce_variation').each(function() { updateMetrics( $(this).find('input').first() ); }); });
});