$(function() {
    function initProgressBars(opts) {
        opts = opts === undefined ? {} : opts;
        var P = this;

        var setOption = function(val, def) { return opts[val]===undefined ? def : opts[val]; }

        P.selector = setOption('selector', '.progress-section');

        // Constants
        var inputSelector = 'input[type!="hidden"], select, textarea';

        // Build sections
        var sections = $(P.selector);

        var getChildInputs = function(elem) {
            return $(elem).find(inputSelector).not(
                $(elem).find(P.selector).find(inputSelector)
            );
        };

        var notEmpty = function(elem) {
            var e = $(elem);
            var val = 0;

            if( elem.nodeName.toLowerCase() == 'input' && e.attr('type') == 'checkbox' ) {
                if( e.prop('checked') ){
                    val = 1;
                }
            } else {
                val = e.val() ? 1 : 0;
            }

            return val;
        };

        var calculateChildInputs = function() {
            sections.each(function(i, el) {
                var fields = getChildInputs(el);
                $(el)
                    .data('progress-total', fields.length)
                    .data('progress-done', function() {
                        var total = 0;
                        fields.each(function(i, elem) { total += notEmpty(elem) });
                        return total;
                    }()
                );
            });
        };

        calculateChildInputs();

        var calculateChildSections = function () {
            sections.each(function(i, el) {
                var e = $(el);
                var sectionTotal = 0;
                var sectionDone = 0;
                e.find(P.selector).each(function(i, childEl) {
                    sectionTotal += $(childEl).data('progress-total');
                    sectionDone += $(childEl).data('progress-done')
                });

                e.data(
                    'progress-cumulative-total',
                    e.data('progress-total') + sectionTotal
                ).data(
                    'progress-cumulative-done',
                    e.data('progress-done') + sectionDone
                );

            });
        };

        calculateChildSections();

        var updateBarWidths = function() {
            sections.each(function(i, el) {
                var w = $(el).data('progress-cumulative-done') / $(el).data('progress-cumulative-total')
                w = (Math.round(w * 1000) / 10) + "%";

                $('#outline a[href="#' + $(el).attr('id') + '"]')
                    .parent()
                    .find('> .progress')
                        .attr('title', w)
                    .find('.bar')
                        .css({
                            'width': w
                        });
            });
        }

        updateBarWidths();

        console.log(inputSelector)

        $(document).on('change', inputSelector, function() {
            initProgressBars({'selector': P.selector});
        });

        var overallTotal = 0;
        var overallDone = 0;

        for(var i=0;i<sections.length;i++){
            overallTotal += $(sections[i]).data('progress-total');
            overallDone += $(sections[i]).data('progress-done');
        }

        var overallPercent = (Math.round((overallDone/overallTotal) * 1000) / 10) + "%";

        $('#totalProgress')
            .attr('title', overallPercent)
            .find('.bar')
                .css({ 'width': overallPercent });

    }

    initProgressBars({'selector': '.study, .test'});

});