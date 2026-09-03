<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$channels = STI_GS_Channel::all();
$state    = STI_GS_Test_Wizard::state();
$report   = STI_GS_Test_Wizard::report();
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">🧪 تست خودکار</h1>
		<p class="gi-h1-sub">مرحله‌ها را به ترتیب بزنید. هیچ SQL، Console یا ویرایش فایلی لازم نیست.</p>
	</div>

	<?php if ( empty( $channels ) ) : ?>
		<div class="gi-notice gi-notice--warning" style="margin:var(--gi-s4) 0;">
			اول از تب «کانال‌ها» یک کانال اضافه کنید.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan' ) ); ?>">رفتن به کانال‌ها</a>
		</div>
	<?php else :
		$locked = STI_GS_Test_Wizard::locked_channel();
	?>

	<div class="gi-bento">

		<div class="gi-card gi-span-12">
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;padding:var(--gi-s4);">
				<label class="gi-field" style="margin:0;">
					<span class="gi-field-label"><strong>کانال تست</strong></span>
					<select id="gs-wizard-channel" style="min-width:300px;" <?php echo $locked ? 'disabled' : ''; ?>>
						<?php foreach ( $channels as $ch ) : ?>
							<option value="<?php echo (int) $ch['id']; ?>" <?php selected( $locked, (int) $ch['id'] ); ?>>
								<?php echo esc_html( ( $ch['title'] ?: $ch['identifier'] ) . ' — ' . $ch['identifier'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( $locked ) : ?>
					<input type="hidden" id="gs-wizard-channel-locked" value="<?php echo (int) $locked; ?>">
					<span class="gi-card-sub" style="font-weight:700;">🔒 تا «شروع دوباره» روی همین کانال قفل است.</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<h2 class="gi-card-title">مرحله‌ها</h2>
				<span class="gi-card-sub">به ترتیب اجرا کنید — وضعیت به‌صورت زنده به‌روز می‌شود</span>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive" id="gs-wizard-table">
					<thead><tr>
						<th scope="col" style="width:44px;">#</th>
						<th scope="col" style="width:230px;">مرحله</th>
						<th scope="col" style="width:140px;">وضعیت</th>
						<th scope="col">نتیجه</th>
						<th scope="col" style="width:120px;"></th>
					</tr></thead>
					<tbody>
						<?php $i = 1; foreach ( STI_GS_Test_Wizard::STEPS as $key => $label ) :
							$st = $state[ $key ]['status'] ?? 'pending'; ?>
							<tr data-step="<?php echo esc_attr( $key ); ?>">
								<td data-label="#" class="gi-nums"><?php echo $i++; ?></td>
								<td data-label="مرحله"><strong><?php echo esc_html( $label ); ?></strong></td>
								<td data-label="وضعیت" class="gs-status"><?php echo esc_html( $st ); ?></td>
								<td data-label="نتیجه" class="gs-message"><?php echo esc_html( $state[ $key ]['message'] ?? '—' ); ?></td>
								<td data-label="اجرا"><button class="gi-btn gi-btn--primary gi-btn--sm gs-run-step">اجرا</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="gi-card gi-card--flush gi-span-12">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<h2 class="gi-card-title">گزارش نهایی</h2>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table" id="gs-wizard-report">
					<tbody>
						<?php foreach ( $report as $r ) : ?>
							<tr><td style="font-weight:700;"><?php echo esc_html( $r['label'] ); ?></td>
							    <td><?php echo esc_html( $r['status'] ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;padding:var(--gi-s4);border-top:1px solid var(--gi-border);">
				<button class="gi-btn gi-btn--subtle" id="gs-wizard-refresh">⟳ به‌روزرسانی وضعیت</button>
				<button class="gi-btn gi-btn--ghost" id="gs-wizard-close-run">بستن Run باز</button>
				<button class="gi-btn gi-btn--ghost" id="gs-wizard-reset">شروع دوباره</button>
			</div>
		</div>

	</div>

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
				return '<tr><td style="font-weight:700;">'+r.label+'</td><td>'+(ICON[r.status]||r.status)+'</td></tr>';
			}).join('');
		}

		function refresh(){
			if (document.hidden) { return; }
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
