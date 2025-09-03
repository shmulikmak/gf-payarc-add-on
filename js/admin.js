/**
 * PayArc Admin JavaScript for refund functionality
 */
jQuery(document).ready(function($) {
    // Ensure gfPayArcAdminVars is available
    if (typeof gfPayArcAdminVars === 'undefined') {
        console.error('PayArc Admin: gfPayArcAdminVars not loaded');
        return;
    }
    
    $('#payarc-refund-button').on('click', function() {
        var $button = $(this);
        var $spinner = $('#payarc-refund-spinner');
        var $message = $('#payarc-refund-message');
        var entryId = $button.data('entry-id');
        var transactionId = $button.data('transaction-id');

        // Validate data attributes
        if (!entryId || !transactionId) {
            alert('Error: Missing entry ID or transaction ID');
            return;
        }

        if (!confirm(gfPayArcAdminVars.strings.confirm_refund)) {
            return;
        }

        $button.prop('disabled', true);
        $spinner.show();
        $message.html('');

        $.ajax({
            url: gfPayArcAdminVars.ajax_url,
            type: 'POST',
            data: {
                action: 'gf_payarc_refund',
                nonce: gfPayArcAdminVars.nonce,
                entry_id: entryId,
                transaction_id: transactionId
            },
            success: function(response) {
                $spinner.hide();
                
                if (response.success) {
                    $message.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    $button.remove();
                    // Refresh the page after 2 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $message.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $spinner.hide();
                $message.html('<div class="notice notice-error inline"><p>' + gfPayArcAdminVars.strings.unexpected_error + '</p></div>');
                $button.prop('disabled', false);
            }
        });
    });
});