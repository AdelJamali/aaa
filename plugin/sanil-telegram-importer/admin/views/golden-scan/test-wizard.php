<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$channels = STI_GS_Channel::all();
$state    = STI_GS_Test_Wizard::state();
$report   = STI_GS_Test_Wizard::report();
?>
<div class="wrap sti-wrap">
	<h1>گلدن اسکن — تست خودکار</h1>
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<p>مرحله‌ها را به ترتیب بزنید. هیچ SQL، Console یا ویرایش فایلی لازم نیست.</p>

	<?php if ( empty( $channels ) ) : ?>
		<div class="notice notice-warning"><p>اول از تب «کانال‌ها» یک کانال اضافه کنید.</p></div>
	<?php else : ?>

	<?php $locked = STI_GS_Test_Wizard::locked_channel(); ?>
	<p>
		<label><strong>کانال تست:</strong></label>
		<select id="gs-wizard-channel" style="min-width:300px;" <?php echo $locked ? 'disabled' : ''; ?>>
			<?php foreach ( $channels as $ch ) : ?>
				<option value="<?php echo (int) $ch['id']; ?>" <?php selected( $locked, (int) $ch['id'] ); ?>>
					<?php echo esc_html( ( $ch['title'] ?: $ch['identifier'] ) . ' — ' . $ch['identifier'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( $locked ) : ?>
			<input type="hidden" id="gs-wizard-channel-locked" value="<?php echo (int) $locked; ?>">
			<span style="color:#666;">🔒 تا «شروع دوباره» روی همین کانال قفل است.</span>
		<?php endif; ?>
	</p>

	<table class="widefat striped" id="gs-wizard-table">
		<thead><tr>
			<th style="width:44px;">#</th>
			<th style="width:230px;">مرحله</th>
			<th style="width:120px;">وضعیت</th>
			<th>نتیجه</th>
			<th style="width:140px;"></th>
		</tr></thead>
		<tbody>
		<?php $i = 1; foreach ( STI_GS_Test_Wizard::STEPS as $key => $label ) :
			$st = $state[ $key ]['status'] ?? 'pending'; ?>
			<tr data-step="<?php echo esc_attr( $key ); ?>">
				<td><?php echo $i++; ?></td>
				<td><strong><?php echo esc_html( $label ); ?></strong></td>
				<td class="gs-status"><?php echo esc_html( $st ); ?></td>
				<td class="gs-message"><?php echo esc_html( $state[ $key ]['message'] ?? '—' ); ?></td>
				<td><button class="button button-primary gs-run-step">اجرا</button></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2 style="margin-top:26px;">گزارش نهایی</h2>
	<table class="widefat striped" id="gs-wizard-report">
		<tbody>
		<?php foreach ( $report as $r ) : ?>
			<tr><td style="width:280px;"><?php echo esc_html( $r['label'] ); ?></td>
			    <td><?php echo esc_html( $r['status'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p style="margin-top:16px;">
		<button class="button" id="gs-wizard-refresh">به‌روزرسانی وضعیت</button>
		<button class="button" id="gs-wizard-close-run">بستن Run باز</button>
		<button class="button" id="gs-wizard-reset">شروع دوباره</button>
	</p>

	<script>
	(function(){
		var ICON = { pass:'🟢 موفق', fail:'🔴 خطا', running:'⏳ در حال اجرا', pending:'⚪ اجرا نشده' };

		function channelId(){
			var locked = document.getElementById('gs-wizard-channel-locked');
			return locked ? locked.value : document.getElementById('gs-wizard-channel').value;
		}

		function post(action, extra){
			var body = new URLSearchParams(Object.assign({ action: action, nonce: STI.nonce }, extra || {}));
			return fetch(STI.ajaxUrl || ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.text(); })
				.then(function(t){
					try { return JSON.parse(t); }
					catch(e){ throw new Error('پاسخ نامعتبر از سرور:\n' + t.slice(0, 400)); }
				});
		}

		function paintRow(step, result){
			var tr = document.querySelector('tr[data-step="'+step+'"]');
			if (!tr || !result) return;
			tr.querySelector('.gs-status').textContent  = ICON[result.status] || result.status;
			tr.querySelector('.gs-message').textContent = result.message || '—';
		}

		function paintReport(rows){
			if (!rows) return;
			var tb = document.querySelector('#gs-wizard-report tbody');
			tb.innerHTML = rows.map(function(r){
				return '<tr><td style="width:280px;">'+r.label+'</td><td>'+(ICON[r.status]||r.status)+'</td></tr>';
			}).join('');
		}

		function refresh(){
			post('sti_gs_wizard_state').then(function(res){
				if (!res.success) return;
				Object.keys(res.data.state || {}).forEach(function(k){ paintRow(k, res.data.state[k]); });
				paintReport(res.data.report);
			}).catch(function(e){ alert(e.message); });
		}

		document.querySelectorAll('.gs-run-step').forEach(function(btn){
			btn.addEventListener('click', function(){
				var tr   = btn.closest('tr');
				var step = tr.getAttribute('data-step');
				btn.disabled = true;
				tr.querySelector('.gs-status').textContent = '⏳ در حال اجرا';
				post('sti_gs_wizard_step', { step: step, channel_id: channelId() }).then(function(res){
					btn.disabled = false;
					if (!res.success) { alert(res.data && res.data.message ? res.data.message : 'خطا'); tr.querySelector('.gs-status').textContent = '🔴 خطا'; return; }
					paintRow(step, res.data.result);
					paintReport(res.data.report);
					if (res.data.result && res.data.result.status === 'running') {
						setTimeout(refresh, 5000);
					}
				}).catch(function(e){ btn.disabled = false; alert(e.message); });
			});
		});

		document.getElementById('gs-wizard-refresh').addEventListener('click', refresh);
		document.getElementById('gs-wizard-close-run').addEventListener('click', function(){
			post('sti_gs_wizard_close_run', { channel_id: channelId() }).then(function(res){
				alert(res.data && res.data.message ? res.data.message : 'انجام شد');
				refresh();
			}).catch(function(e){ alert(e.message); });
		});
		document.getElementById('gs-wizard-reset').addEventListener('click', function(){
			if (!confirm('نتیجه تمام مرحله‌ها پاک شود؟')) return;
			post('sti_gs_wizard_reset').then(function(){ location.reload(); });
		});

		setInterval(refresh, 10000);
	})();
	</script>
	<?php endif; ?>
</div>
