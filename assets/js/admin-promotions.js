jQuery(document).ready(function($){
    // Logic for Promotion Generator
    $('#pg_generate').click(function(){
        var id = $('#pg_id').val(), app = $('#pg_app').val(), type = $('#pg_type').val();
        var title = $('#pg_title').val(), dates = $('#pg_dates').val(), code = $('#pg_code').val();
        var str = id + ',' + app + ',' + type + ',' + title + ',' + dates + ',ONLINE,' + dates + ',' + (type==='GENERIC_CODE' ? code : '');
        $('#pg_output').text(str);
        $('#pg_result_area').fadeIn();
    });

    var loadPromotions = function( forceRefresh ) {
        var $btn = $('#cc_load_promos');
        var $table = $('#cc-gmc-promos-table tbody');
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:spin 2s linear infinite;"></span> Syncing...');
        if( forceRefresh ) $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center;">Refreshing data...</td></tr>');
        
        $.post(cirrusly_promo_data.ajaxurl, {
            action: 'cc_list_promos_gmc',
            security: cirrusly_promo_data.nonce_list,
            force_refresh: forceRefresh ? 1 : 0
        }, function(res) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync from Google');
            if(res.success) {
                $table.empty();
                if(res.data.length === 0) {
                    $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center;">No promotions found in Merchant Center.</td></tr>');
                    return;
                }
                function ccEscapeHtml(str) {
                    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                }
                $.each(res.data, function(i, p){
                    var statusColor = '#777';
                    if(p.status === 'active') statusColor = '#008a20';
                    if(p.status === 'rejected') statusColor = '#d63638';
                    if(p.status === 'expired') statusColor = '#999';
                    var displayStatus = p.status.toUpperCase();
                    if(p.status.indexOf('(') > 0) statusColor = '#dba617';
                    var row = '<tr>' +
                        '<td><strong>' + ccEscapeHtml(p.id) + '</strong></td>' +
                        '<td>' + ccEscapeHtml(p.title) + '</td>' +
                        '<td>' + ccEscapeHtml(p.dates) + '</td>' +
                        '<td><span class="gmc-badge" style="background:'+statusColor+';color:#fff;">'+ccEscapeHtml(displayStatus)+'</span></td>' +
                        '<td>' + ccEscapeHtml(p.type) + (p.code ? ': <code>'+ccEscapeHtml(p.code)+'</code>' : '') + '</td>' +
                        '<td><button type="button" class="button button-small cc-edit-promo" ' +
                        'data-id="'+ccEscapeHtml(p.id)+'" data-title="'+ccEscapeHtml(p.title)+'" data-dates="'+ccEscapeHtml(p.dates)+'" data-app="'+ccEscapeHtml(p.app)+'" data-type="'+ccEscapeHtml(p.type)+'" data-code="'+ccEscapeHtml(p.code || '')+'">Edit</button></td>' +
                        '</tr>';
                    $table.append(row);
                });
            } else {
                if(forceRefresh) alert('Error: ' + (res.data || 'Failed to fetch data.'));
                $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center; color:#d63638;">Error loading data.</td></tr>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync from Google');
            $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center; color:#d63638;">Error loading data.</td></tr>');
                    if ( forceRefresh ) {
                alert('Error: Could not reach the server.');
            }
        });
    };

    if( $('#cc-gmc-promos-table').length > 0 ) {
        loadPromotions(false);
    }
    $('#cc_load_promos').click(function(){ loadPromotions(true); });

    $(document).on('click', '.cc-edit-promo', function(e){
        e.preventDefault();
        var d = $(this).data();
        $('#pg_id').val(d.id); 
        $('#pg_title').val(d.title);
        $('#pg_dates').val(d.dates);
        $('#pg_app').val(d.app).trigger('change');
        $('#pg_type').val(d.type).trigger('change');
        $('#pg_code').val(d.code);
        $('html, body').animate({ scrollTop: $("#cc_promo_form_container").offset().top - 50 }, 500);
        $('#cc_promo_form_container').css('border', '2px solid #2271b1').animate({borderWidth: 0}, 1500, function(){ $(this).css('border',''); });
        $('#cc_form_title').text('Edit Promotion: ' + d.id);
    });

    $('#pg_api_submit').click(function(){
        var $btn = $(this);
        var originalText = $btn.html();
        if( !$('#pg_id').val() || !$('#pg_title').val() ) {
            alert('Please fill in Promotion ID and Title first.');
            return;
        }
        $btn.prop('disabled', true).text('Sending...');
        $.post(cirrusly_promo_data.ajaxurl, {
            action: 'cc_submit_promo_to_gmc',
            security: cirrusly_promo_data.nonce_submit,
            // Prefix custom data key
            cirrusly_promo_data: {
                id: $('#pg_id').val(),
                title: $('#pg_title').val(),
                dates: $('#pg_dates').val(),
                app: $('#pg_app').val(),
                type: $('#pg_type').val(),
                code: $('#pg_code').val()
            }
        }).done(function(response) {
            if (response && response.success) {
                alert('Success! Promotion pushed to Google Merchant Center.');
                loadPromotions(true);
            } else {
                alert('Error: ' + ((response && response.data) || 'Could not connect to API.'));
            }
        }).fail(function() {
            alert('Error: Could not reach the server.');
        }).always(function() {
            $btn.prop('disabled', false).html(originalText);
        });
    });
    
    // Bulk action checkbox logic
    $('#cb-all-promo').change(function(){
        $('input[name=\'gmc_promo_products[]\']').prop('checked',this.checked);
    });
});