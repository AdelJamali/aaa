<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
$bridge = STI_Agent_Bridge::instance();
$enabled = STI_Agent_Bridge::is_enabled();
$max_jobs = intval( get_option( 'sti_agent_max_jobs', 10 ) );
$ip_list = get_option( 'sti_agent_ip_allowlist', '' );
$api_key = get_option( 'sti_agent_api_key', '' );
$secret = get_option( 'sti_agent_secret', '' );
$last_req = get_option( 'sti_agent_last_request', '' );
$mappings = STI_Agent_Bridge::get_mappings();
$new_creds = get_transient( 'sti_agent_new_creds' );
if ( $new_creds ) { delete_transient( 'sti_agent_new_creds' ); }
$page_num = max( 1, intval( $_GET['job_page'] ?? 1 ) );
$st_filter = sanitize_text_field( $_GET['sf'] ?? '' );
global $wpdb;
$table = STI_Agent_Bridge::table_name();
$per = 15;
$off = ( $page_num - 1 )* $per; if ( $st_filter ) { $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $st_filter ) ); $jobs = $wpdb->get_results( $wpdb->prepare( "SELECT *FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
$st_filter, $per, $off
), ARRAY_A );
} else {
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); $jobs = $wpdb->get_results( $wpdb->prepare( "SELECT *FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
$per, $off
), ARRAY_A );
}
$pages = max( 1, ceil( $total / $per ) );
$all_cats = STI_Category::get_all();
$sl = array(
'created'=>'🔵 ایجاد','uploading_image'=>'📤 تصویر','image_ready'=>'🖼 آماده',
'uploading_file'=>'📤 فایل','file_ready'=>'📦 آماده','verifying'=>'🔍 تأیید',
'transferring'=>'📡 FTP','building'=>'🔨 ساخت','draft_created'=>'✅ ساخته',
'failed'=>'❌ خطا','cancelled'=>'🗑 لغو',
);
?>
<div class="wrap sti-wrap">
<div class="sti-shell">
<?php include __DIR__ . '/partials-tabs.php'; ?>
<div class="sti-content">
<div class="sti-header"><h1><span class="dashicons dashicons-cloud-upload"></span> Agent Bridge</h1></div>
<p class="desc">ارتباط Agent Python (VPS) با Golden Importer — فایل‌های بالای ۲۰ مگابایت از تلگرام دریافت و روی هاست دانلود ذخیره می‌شوند.</p>
<?php if ( $new_creds ) : ?>
<div class="notice notice-warning" style="padding:15px;border-left-color:#ffc107;">
<h3 style="margin-top:0;">⚠️ کلیدهای جدید — فقط یک بار!</h3>
<p><strong>API Key:</strong> <code style="user-select:all;word-break:break-all;"><?php echo esc_html( $new_creds['api_key'] ); ?></code></p>
<p><strong>Secret:</strong> <code style="user-select:all;word-break:break-all;"><?php echo esc_html( $new_creds['secret'] ); ?></code></p>
<p>در فایل <code>.env</code> سرور Agent کپی کنید.</p>
</div>
<?php endif; ?>
<!-- Settings -->
<div class="sti-panel">
<h2>⚙️ تنظیمات</h2>
<form method="post">
<?php wp_nonce_field( 'sti_agent_settings' ); ?>
<div class="sti-field">
<label class="sti-toggle"><input type="checkbox" name="agent_enabled" <?php checked( $enabled ); ?>> فعال</label>
</div>
<div class="sti-field"><label>حداکثر Job فعال</label><input type="number" name="max_jobs" value="<?php echo esc_attr( $max_jobs ); ?>" min="1" max="100" style="width:80px"></div>
<div class="sti-field"><label>IP Allowlist (خالی = همه مجاز)</label><textarea name="ip_allowlist" rows="2" dir="ltr" class="large-text"><?php echo esc_textarea( $ip_list ); ?></textarea></div>
<h3>🗂️ Topic Mappings</h3>
<p class="desc">هر Forum Topic تلگرام → یک دسته‌بندی افزونه + پوشه ذخیره. <strong>دسته‌بندی باید از قبل در صفحه «دسته‌بندی‌ها» ساخته شده باشد.</strong></p>
<div class="sti-table-wrap">
<table class="sti-table" id="tm-tbl">
<thead><tr><th>Topic ID</th><th>عنوان</th><th>دسته‌بندی</th><th>Term ID</th><th>پوشه</th><th></th></tr></thead>
<tbody>
<?php foreach ( $mappings as $i => $m ) : ?>
<tr>
<td><input type="number" name="tm[<?php echo $i; ?>][topic_id]" value="<?php echo esc_attr( $m['topic_id'] ); ?>" style="width:90px"></td>
<td><input type="text" name="tm[<?php echo $i; ?>][topic_title]" value="<?php echo esc_attr( $m['topic_title'] ?? '' ); ?>"></td>
<td><select name="tm[<?php echo $i; ?>][category_id]"><option value="0">— خودکار —</option><?php foreach ( $all_cats as $c ) : ?><option value="<?php echo (int) $c->id; ?>" <?php selected( (int)($m['category_id']??0), (int)$c->id ); ?>><?php echo esc_html( $c->telegram_label ); ?></option><?php endforeach; ?></select></td>
<td><input type="number" name="tm[<?php echo $i; ?>][woo_term_id]" value="<?php echo esc_attr( $m['woo_term_id'] ?? '' ); ?>" style="width:80px"></td>
<td><input type="text" name="tm[<?php echo $i; ?>][storage_folder]" value="<?php echo esc_attr( $m['storage_folder'] ?? '' ); ?>" dir="ltr"></td>
<td><button type="button" onclick="this.closest('tr').remove()" class="sti-btn danger" style="padding:2px 8px">✕</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<button type="button" class="sti-btn secondary" id="add-tm">+ افزودن</button>
<script>
var tmI=<?php echo count($mappings); ?>;
document.getElementById('add-tm').onclick=function(){
var cats=<?php echo wp_json_encode(array_map(function($c){return array('id'=>$c->id,'l'=>$c->telegram_label);}, $all_cats)); ?>;
var opts='<option value="0">— خودکار —</option>';
cats.forEach(function(c){opts+='<option value="'+[c.id+'">'+c.l+'</option>';});
document.querySelector('#tm-tbl tbody').insertAdjacentHTML('beforeend','<tr><td><input type="number" name="tm['+tmI+'][topic_id]" style="width:90px"></td><td><input type="text" name="tm['+tmI+'][topic_title]"></td><td><select name="tm['+tmI+'][category_id]">'+opts+'</select></td><td><input type="number" name="tm['+tmI+'][woo_term_id]" style="width:80px"></td><td><input type="text" name="tm['+tmI+'][storage_folder]" dir="ltr"></td><td><button type="button" onclick="this.closest(\'tr\').remove()" class="sti-btn danger" style="padding:2px 8px">✕</button></td></tr>');
tmI++;
};
</script>
<p class="submit"><button type="submit" name="sti_agent_save" class="sti-btn">💾 ذخیره</button></p>
</form>
</div>
<!-- Security -->
<div class="sti-panel">
<h2>🔐 امنیت API</h2>
<table class="form-table">
<tr><th>API Key:</th><td><?php echo $api_key ? '<code>'.esc_html(substr($api_key,0,16)).'...</code> ✅' : '<span style="color:red">ندارد</span>'; ?></td></tr>
<tr><th>Secret:</th><td><?php echo $secret ? '<code>'.esc_html(substr($secret,0,16)).'...</code> ✅' : '<span style="color:red">ندارد</span>'; ?></td></tr>
<tr><th>آخرین درخواست:</th><td><?php echo $last_req ?: 'هنوز'; ?></td></tr>
</table>
<form method="post">
<?php wp_nonce_field( 'sti_agent_creds' ); ?>
<button type="submit" name="sti_agent_gen" class="sti-btn secondary" onclick="return confirm('کلیدهای قبلی غیرفعال می‌شوند.')">🔄 ساخت/تغییر کلیدها</button>
</form>
</div>
<!-- Jobs -->
<div class="sti-panel">
<h2>📋 Agent Jobs</h2>
<form method="get" style="margin-bottom:10px">
<input type="hidden" name="page" value="sti-agent-bridge">
<select name="sf"><option value="">همه</option><?php foreach ($sl as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($st_filter,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select>
<button class="sti-btn secondary">فیلتر</button>
<span style="margin-right:10px"><?php echo $total; ?> مورد</span>
</form>
<div class="sti-table-wrap">
<table class="sti-table">
<thead><tr><th>ID</th><th>File Code</th><th>وضعیت</th><th>دسته</th><th>محصول</th><th>تصویر</th><th>فایل</th><th>تاریخ</th><th>عملیات</th></tr></thead>
<tbody>
<?php if ( empty( $jobs ) ) : ?>
<tr><td colspan="9" style="text-align:center">خالی</td></tr>
<?php else : foreach ( $jobs as $j ) :
$cat = $j['category_id'] ? STI_Category::get( $j['category_id'] ) : null;
?>
<tr>
<td><?php echo (int)$j['id']; ?></td>
<td><strong><?php echo esc_html($j['file_code']); ?></strong><br><small style="color:#999"><?php echo esc_html(substr($j['job_uuid'],0,8)); ?>…</small></td>
<td><span class="sti-badge <?php echo $j['status']==='draft_created'?'published':($j['status']==='failed'?'error':''); ?>"><?php echo esc_html($sl[$j['status']]??$j['status']); ?></span><?php if($j['last_error']): ?><br><small style="color:red" title="<?php echo esc_attr($j['last_error']); ?>">⚠️</small><?php endif; ?></td>
<td><?php echo $cat ? esc_html($cat->telegram_label) : '—'; ?></td>
<td><?php if($j['product_id']): ?><a href="<?php echo get_edit_post_link($j['product_id']); ?>">#<?php echo (int)$j['product_id']; ?></a><?php else: ?>—<?php endif; ?></td>
<td><?php echo (int)$j['image_chunks_received']; ?>/<?php echo (int)$j['image_chunks_expected']; ?></td>
<td><?php echo (int)$j['file_chunks_received']; ?>/<?php echo (int)$j['file_chunks_expected']; ?><?php if($j['file_size_bytes']): ?><br><small><?php echo size_format($j['file_size_bytes']); ?></small><?php endif; ?></td>
<td><small><?php echo esc_html($j['created_at']); ?></small></td>
<td>
<?php if('failed'===$j['status']): ?><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=sti-agent-bridge&sti_ag_retry='.$j['job_uuid']),'sti_ag_retry'); ?>" class="sti-btn secondary" style="padding:2px 8px">🔄</a><?php endif; ?>
<?php if(!in_array($j['status'],array('draft_created','cancelled'))): ?><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=sti-agent-bridge&sti_ag_cancel='.$j['job_uuid']),'sti_ag_cancel'); ?>" class="sti-btn danger" style="padding:2px 8px" onclick="return confirm('لغو؟')">❌</a><?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<?php if ( $pages > 1 ) : ?><div class="sti-pagination" style="margin-top:10px"><?php for($p=1;$p<=$pages;$p++): ?><a href="<?php echo add_query_arg(array('job_page'=>$p,'sf'=>$st_filter)); ?>" class="<?php echo $p===$page_num?'current':''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<!-- Endpoints -->
<div class="sti-panel">
<h2>📖 API Endpoints</h2>
<div class="sti-table-wrap">
<table class="sti-table">
<thead><tr><th>Method</th><th>Endpoint</th><th>توضیح</th></tr></thead>
<tbody>
<tr><td>POST</td><td><code>/wp-json/golden-importer/v1/agent/jobs</code></td><td>ایجاد Job جدید</td></tr>
<tr><td>POST</td><td><code>.../agent/jobs/{uuid}/chunks</code></td><td>آپلود Chunk (binary body)</td></tr>
<tr><td>POST</td><td><code>.../agent/jobs/{uuid}/complete-image</code></td><td>تکمیل تصویر</td></tr>
<tr><td>POST</td><td><code>.../agent/jobs/{uuid}/complete-file</code></td><td>تکمیل فایل + FTP + ساخت محصول</td></tr>
<tr><td>GET</td><td><code>.../agent/jobs/{uuid}</code></td><td>وضعیت Job (برای Resume)</td></tr>
<tr><td>GET</td><td><code>.../agent/health</code></td><td>Health Check</td></tr>
</tbody>
</table>
</div>
</div>
</div>
</div>
</div>
