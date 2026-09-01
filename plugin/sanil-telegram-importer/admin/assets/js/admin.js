(function ($) {
	'use strict';

	function toast(msg, type) {
		var $t = $('<div>', { 'class': 'sti-toast ' + (type || '') }).text(msg || '');
		$('body').append($t);
		setTimeout(function () { $t.addClass('show'); }, 10);
		setTimeout(function () {
			$t.removeClass('show');
			setTimeout(function () { $t.remove(); }, 300);
		}, 3500);
	}

	function ajax(action, data, $btn) {
		data = data || {};
		data.action = action;
		data.nonce = STI.nonce;
		if ($btn) { $btn.prop('disabled', true).data('label', $btn.data('label') || $btn.text()); }
		return $.post(STI.ajaxUrl, data).always(function () {
			if ($btn) { $btn.prop('disabled', false); }
		});
	}

	$(function () {

		/* ===== Sidebar mobile — یک هندلر واحد (جلوگیری از دوبار toggle) ===== */
		var $sidebar = $('#sti-sidebar');
		var $backdrop = $('#sti-sidebar-backdrop');
		var $toggle = $('#sti-sidebar-toggle');
		$sidebar.removeClass('open');
		$backdrop.removeClass('show');
		$('body').removeClass('sti-sidebar-open');

		function openSidebar() {
			$sidebar.addClass('open');
			$backdrop.addClass('show');
			$('body').addClass('sti-sidebar-open');
		}
		function closeSidebar() {
			$sidebar.removeClass('open');
			$backdrop.removeClass('show');
			$('body').removeClass('sti-sidebar-open');
		}

		$toggle.off('click.sti').on('click.sti', function (e) {
			e.preventDefault();
			if ($sidebar.hasClass('open')) { closeSidebar(); }
			else { openSidebar(); }
		});
		$backdrop.off('click.sti').on('click.sti', function () { closeSidebar(); });
		$sidebar.find('a').off('click.sti-close').on('click.sti-close', function () {
			// روی موبایل بعد از کلیک منو بسته شود
			if ($(window).width() <= 900) { closeSidebar(); }
		});
		$(document).on('keydown.sti', function (e) {
			if (e.key === 'Escape' && $sidebar.hasClass('open')) { closeSidebar(); }
		});

		/* ===== Webhook / Telegram / FTP ===== */
		$('#sti-generate-secret').on('click', function () {
			var $btn = $(this);
			ajax('sti_generate_secret', {}, $btn).done(function (res) {
				if (res.success) {
					$('#webhook_secret_display').text(res.data.secret);
					$('#webhook_url_display').text(res.data.webhook_url);
					toast('کد امنیتی جدید ساخته شد. حالا دکمه ثبت Webhook را بزن.', 'success');
				}
			});
		});

		$('#sti-set-webhook').on('click', function () {
			var $btn = $(this);
			var $res = $('#sti-webhook-result');
			$res.text('در حال ثبت...');
			ajax('sti_set_webhook', {}, $btn).done(function (res) {
				if (res.success) {
					$res.html('✅ ' + res.data.message + '<div class=\"sti-code\">' + res.data.url + '</div>');
					toast(res.data.message, 'success');
				} else {
					$res.text('❌ ' + res.data.message);
					toast(res.data.message, 'error');
				}
			});
		});

		$('#sti-test-telegram').on('click', function () {
			var $btn = $(this);
			var $res = $('#sti-test-telegram-result');
			$res.text('در حال بررسی...');
			ajax('sti_test_telegram', {}, $btn).done(function (res) {
				$res.text(res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message);
				toast(res.data.message, res.success ? 'success' : 'error');
			});
		});

		$('#sti-test-ftp').on('click', function () {
			var $btn = $(this);
			var $res = $('#sti-test-ftp-result');
			var data = {
				host: $('[name=remote_ftp_host]').val(),
				port: $('[name=remote_ftp_port]').val(),
				user: $('[name=remote_ftp_user]').val(),
				pass: $('[name=remote_ftp_pass]').val()
			};
			$res.text('در حال بررسی...');
			ajax('sti_test_ftp', data, $btn).done(function (res) {
				$res.text(res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message);
				toast(res.data.message, res.success ? 'success' : 'error');
			});
		});

		/* ===== Storage / Content toggles ===== */
		function toggleStorageFields() {
			var mode = $('[name=storage_mode]:checked').val();
			$('.sti-remote-fields').toggle(mode === 'remote');
			$('.sti-local-fields').toggle(mode === 'local');
			if (mode === 'remote') {
				var type = $('[name=remote_type]').val();
				$('.sti-ftp-fields').toggle(type === 'ftp');
				$('.sti-http-fields').toggle(type === 'http');
			}
		}
		$('[name=storage_mode]').on('change', toggleStorageFields);
		$('[name=remote_type]').on('change', toggleStorageFields);
		toggleStorageFields();


		/* ===== Categories ===== */
		var $modalBg = $('#sti-category-modal-bg');
		function openCategoryModal(cat) {
			cat = cat || {};
			$('#cat_id').val(cat.id || '');
			$('#cat_label').val(cat.telegram_label || '');
			$('#cat_folder_key').val(cat.folder_key || '');
			$('#cat_search_terms').val(cat.search_terms || '');
			$('#cat_woo_term').val(cat.woo_term_id || '');
			$('#cat_price').val(cat.price || '');
			$('#cat_delay').val(cat.publish_delay_minutes || '');
			$('#cat_template').val(cat.description_template || '');
			$('#cat_storage_override').val(cat.storage_mode_override || '');
			$('#cat_sort').val(cat.sort_order || 0);
			$('#cat_active').prop('checked', cat.id ? !!parseInt(cat.is_active) : true);
			$('#sti-category-modal-title').text(cat.id ? 'ویرایش دسته‌بندی' : 'افزودن دسته‌بندی جدید');
			$modalBg.addClass('show');
		}
		$('#sti-add-category').on('click', function () { openCategoryModal(); });
		$('.sti-edit-category').on('click', function () { openCategoryModal($(this).data()); });
		$('#sti-category-cancel, .sti-modal-bg').on('click', function (e) {
			if (e.target === this) { $modalBg.removeClass('show'); }
		});
		$('#sti-category-cancel').on('click', function () { $modalBg.removeClass('show'); });

		$('#sti-category-form').on('submit', function (e) {
			e.preventDefault();
			var $btn = $('#sti-category-save');
			var data = {
				id: $('#cat_id').val(),
				telegram_label: $('#cat_label').val(),
				folder_key: $('#cat_folder_key').val(),
				search_terms: $('#cat_search_terms').val(),
				woo_term_id: $('#cat_woo_term').val(),
				price: $('#cat_price').val(),
				publish_delay_minutes: $('#cat_delay').val(),
				description_template: $('#cat_template').val(),
				storage_mode_override: $('#cat_storage_override').val(),
				sort_order: $('#cat_sort').val(),
				is_active: $('#cat_active').is(':checked') ? 1 : 0
			};
			ajax('sti_category_save', data, $btn).done(function (res) {
				if (res.success) {
					toast('ذخیره شد ✅', 'success');
					location.reload();
				} else {
					toast(res.data.message, 'error');
				}
			});
		});

		$('.sti-delete-category').on('click', function () {
			if (!confirm('این دسته‌بندی حذف شود؟')) { return; }
			var id = $(this).data('id');
			ajax('sti_category_delete', { id: id }).done(function (res) {
				if (res.success) { location.reload(); }
			});
		});

		$('#sti-clear-templates').on('click', function () {
			if (!confirm('قالب اختصاصی همه‌ی دسته‌بندی‌ها پاک شود؟ (فقط قالب سراسری اثر خواهد داشت)')) { return; }
			var $btn = $(this);
			var $res = $('#sti-clear-templates-result');
			ajax('sti_clear_category_templates', {}, $btn).done(function (res) {
				if (res.success) {
					$res.text('✅ ' + res.data.message);
					toast(res.data.message, 'success');
				} else {
					$res.text('❌ ' + res.data.message);
				}
			});
		});

		/* ===== Sessions ===== */
		$('.sti-cancel-session').on('click', function () {
			if (!confirm('این Session لغو شود؟')) { return; }
			var id = $(this).data('id');
			ajax('sti_session_cancel', { id: id }).done(function (res) {
				if (res.success) { location.reload(); }
			});
		});

		$('.sti-repair-image').on('click', function () {
			var $btn = $(this);
			ajax('sti_repair_featured_image', { id: $btn.data('id') }, $btn).done(function (res) {
				toast(res.data.message, res.success ? 'success' : 'error');
				if (res.success) { setTimeout(function () { location.reload(); }, 800); }
			});
		});

		$('.sti-retry-session').on('click', function () {
			var id = $(this).data('id');
			var $btn = $(this);
			ajax('sti_session_retry', { id: id }, $btn).done(function (res) {
				if (res.success) {
					toast(res.data.message, 'success');
					setTimeout(function () { location.reload(); }, 1200);
				} else {
					toast(res.data.message, 'error');
				}
			});
		});

		/* ===== Queue ===== */
		$('#sti-queue-toggle').on('click', function () {
			var $btn = $(this);
			ajax('sti_queue_toggle', {}, $btn).done(function (res) {
				if (res.success) {
					toast(res.data.message, 'success');
					setTimeout(function () { location.reload(); }, 800);
				} else {
					toast(res.data.message, 'error');
				}
			});
		});

		$('#sti-queue-run-now').on('click', function () {
			var $btn = $(this);
			ajax('sti_queue_run_now', {}, $btn).done(function (res) {
				toast(res.data.message, res.success ? 'success' : 'error');
				setTimeout(function () { location.reload(); }, 800);
			});
		});

		$('#sti-queue-save-interval').on('click', function () {
			var $btn = $(this);
			var minutes = $('#sti-queue-interval').val();
			ajax('sti_queue_save_interval', { minutes: minutes }, $btn).done(function (res) {
				if (res.success) {
					toast(res.data.message, 'success');
					setTimeout(function () { location.reload(); }, 800);
				} else {
					toast(res.data.message, 'error');
				}
			});
		});

		$('.sti-queue-remove').on('click', function () {
			var isDelete = $(this).data('delete') == 1;
			var msg = isDelete ? 'این محصول کامل حذف (زباله‌دان) و از صف خارج شود؟' : 'این محصول از صف انتشار خارج شود؟ (به‌عنوان پیش‌نویس می‌ماند)';
			if (!confirm(msg)) { return; }
			var id = $(this).data('id');
			var $btn = $(this);
			ajax('sti_queue_remove_item', { id: id, delete: isDelete ? 1 : 0 }, $btn).done(function (res) {
				if (res.success) {
					toast(res.data.message, 'success');
					setTimeout(function () { location.reload(); }, 800);
				} else {
					toast(res.data.message, 'error');
				}
			});
		});

		/* ===== Bulk ===== */
		$('#sti-select-all').on('change', function () { $('.sti-session-select').prop('checked', this.checked); });
		$('#sti-bulk-run').on('click', function () {
			var action = $('#sti-bulk-action').val(), ids = $('.sti-session-select:checked').map(function () { return $(this).val(); }).get();
			if (!action || !ids.length) { alert('ابتدا عملیات و حداقل یک Session را انتخاب کن.'); return; }
			if (!confirm('این عملیات برای ' + ids.length + ' مورد انجام شود؟')) return;
			var $btn = $(this); $btn.prop('disabled', true);
			$.post(STI.ajaxUrl, { action: 'sti_bulk_session_action', nonce: STI.nonce, bulk_action: action, ids: ids }).done(function (res) {
				$('#sti-bulk-result').text((res.success ? '✅ ' : '❌ ') + res.data.message);
				if (res.success) setTimeout(function () { location.reload(); }, 1000);
			}).always(function () { $btn.prop('disabled', false); });
		});

	});

})(jQuery);
