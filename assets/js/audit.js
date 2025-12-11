jQuery(document).ready(function($){
    // Handle inline edit blur (Save) - support legacy and new classes
    $(document).on('blur', '.cirrusly-inline-edit, .cc-inline-edit', function(){
        var $el = $(this);
        var $row = $el.closest('tr');
        var pid = $el.data('pid');
        var field = $el.data('field');
        var val = $el.text();
        
        $el.css('opacity', '0.5');

        $.post(cirrusly_audit_vars.ajax_url, {
            action: 'cirrusly_audit_save',
            pid: pid,
            field: field,
            value: val,
            _nonce: cirrusly_audit_vars.nonce
        }, function(res){
            $el.css('opacity', '1');
            if(res.success) {
                $el.css('background-color', '#e7f6e7');
                setTimeout(function(){ $el.css('background-color', 'transparent'); }, 1500);
                if(res.data) {
                    if(res.data.net_html) $row.find('.col-net').html(res.data.net_html);
                    if(res.data.net_style) $row.find('.col-net').attr('style', res.data.net_style);
                    if(res.data.margin) $row.find('.col-margin').text(res.data.margin + '%');
                }
            } else {
                $el.css('background-color', '#f8d7da');
                alert('Save Failed: ' + (res.data || 'Unknown error'));
            }
        });
    });

    // Handle inline edit focus (Select All)
    $(document).on('focus', '.cirrusly-inline-edit, .cc-inline-edit', function() {
        var range = document.createRange();
        range.selectNodeContents(this);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    });
});