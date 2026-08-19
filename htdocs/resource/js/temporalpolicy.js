/* global jQuery */
(function ($) {
	'use strict';
	window.initResourceTemporalPolicy = function (config) {
		function fieldSet(prefix) {
			return $('[id^="' + prefix + '"], [name^="' + prefix + '"]');
		}
		function apply(policy) {
			if (!policy || !policy.has_requirements) return;
			var startFields = fieldSet('date_start');
			var endFields = fieldSet('date_end');
			startFields.toggle(!!policy.show_start).prop('disabled', !policy.show_start);
			endFields.toggle(!!policy.show_end).prop('disabled', !policy.show_end);
			$('.resource-time-start-label').toggle(!!policy.show_start);
			$('.resource-time-end-label').toggle(!!policy.show_end);
			$('#trlinefordates, #service_duration_area').toggle(!!policy.show_start || !!policy.show_end);
			$('#resource_temporal_policy').remove();
			var message = policy.calculate_end ? config.calculatedLabel : (policy.automatic && !policy.show_start && !policy.show_end ? config.automaticLabel : '');
			if (message) $('#trlinefordates td, #service_duration_area td').last().append('<span id="resource_temporal_policy" class="opacitymedium marginleftonly">' + $('<div>').text(message).html() + '</span>');
			startFields.toggleClass('inputmandatory', !!policy.show_start);
			endFields.toggleClass('inputmandatory', !!policy.show_end);
			function ensureSeconds(prefix) {
				if ($('#' + prefix + 'sec').length || !$('#' + prefix + 'min').length) return;
				var select = $('<select>', {id: prefix + 'sec', name: prefix + 'sec', class: 'flat resource-time-seconds'});
				for (var second = 0; second < 60; second++) select.append($('<option>', {value: second, text: String(second).padStart(2, '0')}));
				$('#' + prefix + 'min').after($('<span>', {class: 'resource-time-seconds', text: ':'})).after(select);
			}
			ensureSeconds('date_start');
			ensureSeconds('date_end');
			var startTime = fieldSet('date_starthour').add(fieldSet('date_startmin')).add($('#date_startsec')).add($('.resource-time-start-label .resource-time-seconds'));
			var endTime = fieldSet('date_endhour').add(fieldSet('date_endmin')).add($('#date_endsec')).add($('.resource-time-end-label .resource-time-seconds'));
			startTime.show().prop('disabled', false);
			endTime.show().prop('disabled', false);
			if (policy.start_input_mode === 'date' || policy.time_precision === 'day') startTime.hide().prop('disabled', true);
			if (policy.end_input_mode === 'date' || policy.time_precision === 'day') endTime.hide().prop('disabled', true);
			if (policy.time_precision === 'hour') fieldSet('date_startmin').add($('#date_startsec')).add(fieldSet('date_endmin')).add($('#date_endsec')).add($('.resource-time-seconds')).hide().prop('disabled', true);
			if (policy.time_precision === 'minute') $('#date_startsec, #date_endsec, .resource-time-seconds').hide().prop('disabled', true);
		}
		function load(productId) {
			if (!productId || productId <= 0) return;
			$.getJSON(config.url, {product_id: productId}, apply);
		}
		$('#idprod').on('change.resourceTemporalPolicy', function () { load(parseInt($(this).val(), 10)); });
		load(config.productId || parseInt($('#idprod').val(), 10));
	};
})(jQuery);

