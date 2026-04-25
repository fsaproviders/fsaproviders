(function ($) {
	'use strict';

	var $container = $('#fsa-amm-items');

	function nextIndex() {
		return $container.children('.fsa-amm-item').length;
	}

	function randomId() {
		return 'item_' + Math.random().toString(36).slice(2, 10);
	}

	function addRow(templateId) {
		var tpl = document.getElementById(templateId);
		if (!tpl) return;

		var html = tpl.innerHTML.replace(/__INDEX__/g, nextIndex());
		var $row = $(html);

		// Assign a fresh ID to the new row
		$row.find('input[name$="[id]"]').val(randomId());

		$container.append($row);
		reindex();
	}

	function reindex() {
		$container.children('.fsa-amm-item').each(function (i) {
			$(this).find('input, select').each(function () {
				var name = $(this).attr('name');
				if (!name) return;
				$(this).attr(
					'name',
					name.replace(/fsa_amm_items\[(\d+|__INDEX__)\]/, 'fsa_amm_items[' + i + ']')
				);
			});
		});
	}

	$('#fsa-amm-add-endpoint').on('click', function () { addRow('fsa-amm-tpl-endpoint'); });
	$('#fsa-amm-add-page').on('click',     function () { addRow('fsa-amm-tpl-page'); });
	$('#fsa-amm-add-url').on('click',      function () { addRow('fsa-amm-tpl-url'); });

	$container.on('click', '.fsa-amm-remove', function (e) {
		e.preventDefault();
		$(this).closest('.fsa-amm-item').remove();
		reindex();
	});

	$container.on('click', '.fsa-amm-up', function (e) {
		e.preventDefault();
		var $row = $(this).closest('.fsa-amm-item');
		var $prev = $row.prev('.fsa-amm-item');
		if ($prev.length) {
			$prev.before($row);
			reindex();
		}
	});

	$container.on('click', '.fsa-amm-down', function (e) {
		e.preventDefault();
		var $row = $(this).closest('.fsa-amm-item');
		var $next = $row.next('.fsa-amm-item');
		if ($next.length) {
			$next.after($row);
			reindex();
		}
	});
})(jQuery);
