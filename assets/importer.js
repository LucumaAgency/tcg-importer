(function ($) {
	'use strict';

	var $setSelect   = $('#tcg-set-select');
	var $importBtn   = $('#tcg-import-btn');
	var $cancelBtn   = $('#tcg-cancel-btn');
	var $progressWrap = $('.tcg-progress-wrapper');
	var $progressBar = $('.tcg-progress-bar-inner');
	var $progressText = $('.tcg-progress-text');
	var $progressStatus = $('.tcg-progress-status');
	var $summary     = $('.tcg-summary');
	var $log         = $('#tcg-log');

	var cancelled = false;
	var batchSize = 20;

	// --- Load sets on page load ---
	function loadSets() {
		$.post(tcgImporter.ajax_url, {
			action: 'tcg_fetch_sets',
			nonce: tcgImporter.nonce
		}, function (res) {
			if (!res.success) {
				log('Error cargando sets: ' + (res.data || 'desconocido'), 'error');
				return;
			}

			$setSelect.empty().append('<option value="">— Seleccionar set —</option>');

			$.each(res.data, function (i, set) {
				var label = set.set_name;
				if (set.num_of_cards) {
					label += ' (' + set.num_of_cards + ' cartas)';
				}
				$setSelect.append('<option value="' + escHtml(set.set_name) + '">' + escHtml(label) + '</option>');
			});

			$setSelect.prop('disabled', false);
			$importBtn.prop('disabled', false);
		}).fail(function () {
			log('Error de red al cargar sets.', 'error');
		});
	}

	// --- Import flow ---
	$importBtn.on('click', function () {
		var setName = $setSelect.val();
		if (!setName) {
			alert('Selecciona un set primero.');
			return;
		}

		cancelled = false;
		$log.empty();
		$summary.hide().empty();
		$importBtn.prop('disabled', true);
		$cancelBtn.show();
		$progressWrap.show();
		updateProgress(0, 0);

		log('Contando cartas en "' + setName + '"…');

		$.post(tcgImporter.ajax_url, {
			action: 'tcg_count_cards',
			nonce: tcgImporter.nonce,
			set: setName
		}, function (res) {
			if (!res.success) {
				log('Error contando cartas: ' + (res.data || 'desconocido'), 'error');
				resetUI();
				return;
			}

			var total = res.data.total;
			log('Total de cartas: ' + total);

			if (total === 0) {
				log('No hay cartas para importar.', 'warn');
				resetUI();
				return;
			}

			importLoop(setName, 0, total, { created: 0, updated: 0, errors: 0 });
		}).fail(function () {
			log('Error de red al contar cartas.', 'error');
			resetUI();
		});
	});

	$cancelBtn.on('click', function () {
		cancelled = true;
		log('Importación cancelada por el usuario.', 'warn');
		$cancelBtn.hide();
	});

	// --- Batch loop ---
	function importLoop(setName, offset, total, stats) {
		if (cancelled || offset >= total) {
			showSummary(stats, total);
			resetUI();
			return;
		}

		var currentBatch = Math.min(batchSize, total - offset);
		log('Importando lote ' + (offset + 1) + '–' + Math.min(offset + currentBatch, total) + ' de ' + total + '…');

		$.post(tcgImporter.ajax_url, {
			action: 'tcg_import_batch',
			nonce: tcgImporter.nonce,
			set: setName,
			offset: offset,
			limit: batchSize
		}, function (res) {
			if (!res.success) {
				log('Error en lote: ' + (res.data || 'desconocido'), 'error');
				stats.errors += batchSize;
			} else {
				var d = res.data;
				stats.created += d.created;
				stats.updated += d.updated;
				stats.errors  += d.errors;

				$.each(d.log, function (i, entry) {
					var icon = entry.status === 'created' ? '✓' :
					           entry.status === 'updated' ? '↻' : '✗';
					var cls  = entry.status === 'error' ? 'error' : 'ok';
					log(icon + ' [' + entry.status + '] ' + entry.message, cls);
				});
			}

			var newOffset = offset + batchSize;
			var pct = Math.min(Math.round((newOffset / total) * 100), 100);
			updateProgress(pct, newOffset, total);

			// Continue with next batch.
			importLoop(setName, newOffset, total, stats);
		}).fail(function () {
			log('Error de red en lote (offset ' + offset + '). Reintentando…', 'error');
			// Retry once after a short delay.
			setTimeout(function () {
				importLoop(setName, offset, total, stats);
			}, 3000);
		});
	}

	// --- UI helpers ---
	function updateProgress(pct, current, total) {
		$progressBar.css('width', pct + '%');
		$progressText.text(pct + '%');
		if (typeof total !== 'undefined') {
			$progressStatus.text(current + ' / ' + total + ' procesadas');
		}
	}

	function showSummary(stats, total) {
		var html = '<h3>Resumen</h3><ul>';
		html += '<li><strong>Creadas:</strong> ' + stats.created + '</li>';
		html += '<li><strong>Actualizadas:</strong> ' + stats.updated + '</li>';
		html += '<li><strong>Errores:</strong> ' + stats.errors + '</li>';
		html += '<li><strong>Total procesadas:</strong> ' + (stats.created + stats.updated + stats.errors) + ' / ' + total + '</li>';
		html += '</ul>';
		$summary.html(html).show();
	}

	function resetUI() {
		$importBtn.prop('disabled', false);
		$cancelBtn.hide();
	}

	function log(message, type) {
		type = type || 'info';
		var cls = 'tcg-log-entry tcg-log-' + type;
		$log.append('<div class="' + cls + '">' + escHtml(message) + '</div>');
		$log.scrollTop($log[0].scrollHeight);
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Init.
	loadSets();

})(jQuery);
