jQuery(document).ready(function($){
    var frame;
    var $currentBtn;

    // Badge Image Upload (Updated Class)
    $(document).on("click", ".cirrusly-upload-btn", function(e) {
        e.preventDefault();
        if ( typeof wp === "undefined" || typeof wp.media === "undefined" ) {
            return;
        }
        $currentBtn = $(this);
        if ( frame ) { frame.open(); return; }
        
        frame = wp.media({
            title: "Select Badge Image",
            button: { text: "Use this image" },
            multiple: false
        });

        frame.on( "select", function() {
            var attachment = frame.state().get("selection").first().toJSON();
            $currentBtn.prev("input").val(attachment.url).trigger("change");
        });

        frame.open();
    });

    // Clear Image Field (Updated Class)
    $(document).on("click", ".cirrusly-remove-btn", function(e){
        e.preventDefault();
        $(this).siblings("input").val("");
    });

    // Helper to calculate monotonic index
    function getNextIndex( $container, regex ) {
        var idx = $container.data('next-index');
        // Initialize if not present
        if ( typeof idx === 'undefined' ) {
            idx = 0;
            // Scan existing rows to find max index
            $container.find('tr').each(function(){
                $(this).find('input, select').each(function(){
                    var name = $(this).attr('name');
                    if ( name ) {
                        var match = name.match(regex);
                        if ( match && match[1] ) {
                            var current = parseInt( match[1], 10 );
                            if ( current >= idx ) {
                                idx = current + 1;
                            }
                        }
                    }
                });
            });
        }
        // Store next index for subsequent adds
        $container.data('next-index', idx + 1);
        return idx;
    }
    
    // Add Badge Row (Updated ID and Class)
    $("#cirrusly-add-badge-row").click(function(){
        var idx = getNextIndex( $("#cirrusly-badge-rows"), /cirrusly_badge_config\[custom_badges\]\[(\d+)\]/ );
        var row = "<tr>" +
            "<td><input type='text' name='cirrusly_badge_config[custom_badges]["+idx+"][tag]'></td>" +
            "<td><input type='text' name='cirrusly_badge_config[custom_badges]["+idx+"][url]' class='regular-text'> <button type='button' class='button cirrusly-upload-btn'>Upload</button></td>" +
            "<td><input type='text' name='cirrusly_badge_config[custom_badges]["+idx+"][tooltip]'></td>" +
            "<td><input type='number' name='cirrusly_badge_config[custom_badges]["+idx+"][width]' value='60'> px</td>" +
            "<td><button type='button' class='button cirrusly-remove-row'><span class='dashicons dashicons-trash'></span></button></td>" +
            "</tr>";
        $("#cirrusly-badge-rows").append(row);
    });

    // Add Revenue Tier Row (Updated ID)
    $("#cirrusly-add-revenue-row").click(function(){
        var idx = getNextIndex( $("#cirrusly-revenue-rows"), /cirrusly_shipping_config\[revenue_tiers\]\[(\d+)\]/ );
        var row = "<tr>" +
            "<td><input type='number' step='0.01' name='cirrusly_shipping_config[revenue_tiers]["+idx+"][min]'></td>" +
            "<td><input type='number' step='0.01' name='cirrusly_shipping_config[revenue_tiers]["+idx+"][max]'></td>" +
            "<td><input type='number' step='0.01' name='cirrusly_shipping_config[revenue_tiers]["+idx+"][charge]'></td>" +
            "<td><button type='button' class='button cirrusly-remove-row'><span class='dashicons dashicons-trash'></span></button></td>" +
            "</tr>";
        $("#cirrusly-revenue-rows").append(row);
    });

    // Add Matrix Row (Updated ID)
    $("#cirrusly-add-matrix-row").click(function(){
        var idx = getNextIndex( $("#cirrusly-matrix-rows"), /cirrusly_shipping_config\[matrix_rules\]\[(\d+)\]/ );
        var row = "<tr>" +
            "<td><input type='text' name='cirrusly_shipping_config[matrix_rules]["+idx+"][key]'></td>" +
            "<td><input type='text' name='cirrusly_shipping_config[matrix_rules]["+idx+"][label]'></td>" +
            "<td>x <input type='number' step='0.1' name='cirrusly_shipping_config[matrix_rules]["+idx+"][cost_mult]' value='1.0'></td>" +
            "<td><button type='button' class='button cirrusly-remove-row'><span class='dashicons dashicons-trash'></span></button></td>" +
            "</tr>";
        $("#cirrusly-matrix-rows").append(row);
    });

    // Add Countdown Rule Row (Updated ID)
    $("#cirrusly-add-countdown-row").click(function(){
        var idx = getNextIndex( $("#cirrusly-countdown-rows"), /cirrusly_countdown_rules\[(\d+)\]/ );
        var row = "<tr>" +
            "<td><input type='text' name='cirrusly_countdown_rules["+idx+"][taxonomy]'></td>" +
            "<td><input type='text' name='cirrusly_countdown_rules["+idx+"][term]'></td>" +
            "<td><input type='text' name='cirrusly_countdown_rules["+idx+"][end]'></td>" +
            "<td><input type='text' name='cirrusly_countdown_rules["+idx+"][label]'></td>" +
            "<td><select name='cirrusly_countdown_rules["+idx+"][align]'><option value='left'>Left</option><option value='right'>Right</option><option value='center'>Center</option></select></td>" +
            "<td><button type='button' class='button cirrusly-remove-row'><span class='dashicons dashicons-trash'></span></button></td>" +
            "</tr>";
        $("#cirrusly-countdown-rows").append(row);
    });

    // Remove Row (Updated Class)
    $(document).on("click", ".cirrusly-remove-row", function(){
        $(this).closest("tr").remove();
    });
});