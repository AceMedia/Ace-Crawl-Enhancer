jQuery(document).ready(function($) {
            // Character counters for taxonomy fields
            function updateCounter(field, counterClass, optimal, max) {
                var length = $(field).val().length;
                var counter = $(counterClass);
                var progress = counter.find('.ace-counter-progress');
                
                counter.find('.ace-counter-text').text(length + ' characters');
                
                var percentage = (length / max) * 100;
                progress.css('width', Math.min(percentage, 100) + '%');
                
                progress.removeClass('good warning error');
                if (length >= optimal && length <= max) {
                    progress.addClass('good');
                } else if (length > 0 && (length < optimal || length > max)) {
                    progress.addClass('warning');
                } else if (length > max * 1.2) {
                    progress.addClass('error');
                }
            }
            
            // Title counter (optimal: 50-60, max: 70)
            $('#ace_seo_title').on('input', function() {
                updateCounter(this, '.ace-title-counter', 50, 70);
            }).trigger('input');
            
            // Description counter (optimal: 140-160, max: 170)
            $('#ace_seo_desc').on('input', function() {
                updateCounter(this, '.ace-desc-counter', 140, 170);
            }).trigger('input');
        });
