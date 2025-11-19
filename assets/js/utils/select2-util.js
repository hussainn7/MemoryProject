// require('../../../node_modules/bootstrap-datepicker');
require('select2/dist/js/select2.full');
require('select2/dist/js/i18n/de');

const select2Util = function ($) {

    // Initialize Select2 on all .select2 elements except those with custom initialization
    $('.select2').not('.js--select-memory-thema').each(function() {
        // Skip if already initialized
        if ($(this).data('select2')) {
            return;
        }
        
        $(this).select2({
            placeholder: "----",
            theme: 'bootstrap-5',
            allowClear: true
        });
    });

};

export default select2Util;
