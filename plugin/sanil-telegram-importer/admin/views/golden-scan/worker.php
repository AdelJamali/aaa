<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$stats = STI_GS_Auto_Worker::stats();
$queue = class_exists( 'STI_GS_Publish_Queue' ) ? STI_GS_Publish_Queue::stats() : null;
$today = $stats['today'] ?? array();
?>
<div class="gi-console" dir="rtl">
	<?php include STI_PATH . 'admin/views/golden-scan/partial-subnav.php'; ?>

	<div class="gi-console-head">
		<h1 class="gi-h1">⚙️ Queue / پردازش خودکار</h1>
		<p class="gi-h1-sub">وقتی روشن باشد، Sessionها بدون هیچ کلیکی خودشان جلو می‌روند. هر <span class="gi-nums"><?php echo (int) round( $stats['interval'] / 60 ); ?></span> دقیقه، <span class="gi-nums"><?php echo (int) $stats['batch']; ?></span> مورد، هرکدام یک مرحله.</p>
	</div>

	<!-- ===== STI-TMP-DIAG v1 — READ-ONLY — Watcher Runtime Diagnostic — REMOVE AFTER AUDIT ===== -->
	<!-- Pure observation: pass-through wrappers on addEventListener/fetch + capture-phase click probe.
	     No AJAX action, no DB, no state change, no modification of any existing handler/logic. -->
	<div id="gs-wt-diag" style="margin:0 0 16px;border:2px solid #f59e0b;border-radius:12px;background:#fffbeb;direction:rtl;">
		<details open>
			<summary style="cursor:pointer;font-weight:700;padding:10px 14px;font-size:14px;">🧪 Watcher Runtime Diagnostic <span style="font-weight:400;font-size:11px;opacity:.7;">(موقت · فقط‌خواندنی · بدون هیچ اثر بر سیستم)</span></summary>
			<div style="padding:0 14px 12px;font-size:12px;line-height:1.9;">
				<p style="margin:2px 0 8px;opacity:.85;">زنجیره تحت تست: HTML ← Script ← bind() ← click ← handler ← AJAX(fetch) ← PHP ← Watcher. برای تست پویا <strong>دکمهٔ واقعی</strong> (▶/⏸ شروع/توقف پایش یا 🔄 اجرای فوری یک چرخه) را کلیک کن؛ ردیای زنده و «اولین نقطهٔ شکست» همین‌جا محاسبه می‌شود. باز کردن کنسول لازم نیست.</p>
				<div id="gs-wt-diag-static"></div>
				<div style="margin-top:8px;font-weight:700;">ردیای زنده (REG / CLICK / FETCH / RESP):</div>
				<div id="gs-wt-diag-trace" style="max-height:220px;overflow:auto;border:1px solid #e7cf8f;border-radius:8px;background:#fff;padding:6px 8px;margin-top:4px;"></div>
				<div id="gs-wt-diag-verdict" dir="ltr" style="margin-top:8px;"></div>
				<button type="button" id="gs-wt-diag-reset" style="margin-top:8px;font-size:11px;padding:4px 12px;border:1px solid #d1a53a;background:#fff;border-radius:8px;cursor:pointer;">↺ پاک‌کردن ردیای پویا</button>
			</div>
		</details>
	</div>
	<script>
	/* STI-TMP-DIAG v1 — read-only runtime audit of the Watcher button chain. REMOVE AFTER AUDIT. */
	(function(){
	'use strict';
	try{
		var S=document.getElementById('gs-wt-diag-static'),
		    T=document.getElementById('gs-wt-diag-trace'),
		    V=document.getElementById('gs-wt-diag-verdict');
		if(!S||!T||!V){return;}
		/* line references of the audited chain — worker.php: 517/520 buttons, 747 <script>, 749-751 post(), 755 bind helper, 758/763/771/776 binds;
		   class-sti-admin.php: 100 enqueue / 101 localize; class-gs-test-wizard.php: 76 toggle handler / 86 run handler */
		var LN={toggleBtn:517,runBtn:520,scriptTag:747,postFn:749,postNonce:750,fetchLine:751,bindHelper:755,bindWt:758,bindWtRun:763,bindWdRun:771,bindWdRevive:776,adminEnqueue:100,adminLocalize:101,phpToggle:76,phpRun:86};
		var elT=document.getElementById('gs-wt-toggle'),
		    elR=document.getElementById('gs-wt-run'),
		    events=[],selfProbe=false,settled=false,lastClickSti=null;
		function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
		function ts(){var d=new Date(),p=function(n,l){n=String(n);while(n.length<(l||2)){n='0'+n;}return n;};return p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds())+'.'+p(d.getMilliseconds(),3);}
		function truncate(s,n){s=String(s);return s.length>n?s.slice(0,n)+' ...(+ '+(s.length-n)+' chars)':s;}
		function stiSnap(){var t=typeof window.STI,n='(n/a)',u='(n/a)';if(t==='object'&&window.STI){n=window.STI.nonce===undefined?'(undefined)':String(window.STI.nonce);u=window.STI.ajaxUrl===undefined?'(undefined)':String(window.STI.ajaxUrl);}return{t:t,n:n,u:u};}
		function rec(tag,a,b,meta){events.push({t:ts(),tag:tag,a:a,b:b||'',meta:meta||null});renderAll();}
		function find(tag,id){for(var i=0;i<events.length;i++){var e=events[i];if(e.tag===tag&&(!id||e.a===id)){return e;}}return null;}
		function findMany(tag){var r=[];for(var i=0;i<events.length;i++){if(events[i].tag===tag){r.push(events[i]);}}return r;}
		function regNonProbe(id){for(var i=0;i<events.length;i++){var e=events[i];if(e.tag==='REG'&&e.a===id&&e.b.indexOf('[diag-probe]')<0){return e;}}return null;}
		function srow(q,ok,val){return '<div style="border-bottom:1px dashed #e7cf8f;padding:2px 0;">'+q+' → <b style="color:'+(ok?'#15803d':'#b91c1c')+';">'+(ok?'YES':'NO')+'</b> <span dir="ltr" style="font-family:ui-monospace,Consolas,monospace;font-size:11px;">'+esc(val)+'</span></div>';}

		/* ---------- observer 1: addEventListener registrations (pass-through) ---------- */
		var WATCH_IDS=['gs-wt-toggle','gs-wt-run','gs-wd-run','gs-wd-revive'];
		var origAdd=EventTarget.prototype.addEventListener;
		EventTarget.prototype.addEventListener=function(type,fn,opts){
			try{
				var t=this;
				if(t&&t.nodeType===1){
					var id=(typeof t.id==='string')?t.id:'';
					var isFlag=false;
					try{isFlag=t.classList?t.classList.contains('gs-flag'):false;}catch(e0){}
					if(WATCH_IDS.indexOf(id)>-1||isFlag){
						rec('REG',id||'gs-flag','type='+type+(selfProbe?' [diag-probe]':'')+(opts&&(opts===true||opts.capture)?' [capture]':''));
					}
				}
			}catch(e1){}
			return origAdd.apply(this,arguments);
		};

		/* ---------- observer 2: fetch calls + responses (pass-through) ---------- */
		var origFetch=window.fetch;
		window.fetch=function(url,opts){
			var u='?',body='';
			try{u=String(url&&url.url?url.url:url);}catch(e0){}
			try{if(opts&&opts.body){body=String(opts.body);}}catch(e1){}
			var meta={url:u,method:(opts&&opts.method)||'GET',body:body,action:''};
			var am=body.match(/(?:^|&)action=([^&]*)/);if(am){meta.action=am[1];}
			rec('FETCH',u,'method='+meta.method+' action='+(meta.action||'?')+' body='+truncate(body,300),meta);
			var p;
			try{p=origFetch.apply(this,arguments);}
			catch(err){rec('FETCH-THROW','synchronous throw before dispatch',String(err&&err.message||err));throw err;}
			return p.then(function(res){
				try{
					res.clone().text().then(function(txt){
						var ct='';try{ct=res.headers.get('content-type')||'';}catch(e2){}
						var isJson=false,success=null,msg='';
						try{var j=JSON.parse(txt);isJson=true;if(j&&typeof j.success==='boolean'){success=j.success;}if(j&&j.data&&j.data.message){msg=String(j.data.message);}}catch(e3){}
						rec('RESP',res.status+' '+(ct||'no-ct'),(isJson?('json=yes success='+success+(msg?(' message='+msg):'')):'json=NO')+' raw='+truncate(txt,500),{status:res.status,json:isJson,success:success,msg:msg,raw:txt});
					}).catch(function(){});
				}catch(e4){}
				return res;
			},function(err){
				rec('FETCH-REJECT','request failed before response (network/transport)',String(err&&err.message||err));
				throw err;
			});
		};

		/* ---------- observer 3: capture-phase click probe (Q7) ---------- */
		function probe(el,label){
			if(!el){return;}
			selfProbe=true;
			try{
				el.addEventListener('click',function(ev){
					try{
						var top='?';
						if((ev.clientX||ev.clientY)&&document.elementFromPoint){
							var f=document.elementFromPoint(ev.clientX,ev.clientY);
							top=f?(f.id?('id='+f.id):(f.tagName?f.tagName.toLowerCase():'?')):'none';
						}else{top='(no coords)';}
						var pe='?';try{pe=getComputedStyle(el).pointerEvents;}catch(e2){}
						lastClickSti=stiSnap();
						rec('CLICK',label,'topAtPoint='+top+' disabled='+el.disabled+' pointer-events='+pe+' | STI@click: typeof='+lastClickSti.t+' nonce='+lastClickSti.n+' ajaxUrl='+lastClickSti.u);
					}catch(e3){}
				},true);
			}finally{selfProbe=false;}
		}
		probe(elT,'gs-wt-toggle');
		probe(elR,'gs-wt-run');

		/* ---------- rendering ---------- */
		function renderStatic(){
			var st=stiSnap();
			var far=0;
			if(regNonProbe('gs-wt-toggle')){far=LN.bindWt;}
			if(regNonProbe('gs-wt-run')){far=LN.bindWtRun;}
			if(regNonProbe('gs-wd-run')){far=LN.bindWdRun;}
			if(regNonProbe('gs-wd-revive')){far=LN.bindWdRevive;}
			var rt=regNonProbe('gs-wt-toggle');
			var h='';
			h+=srow('۱. آیا #gs-wt-toggle در DOM وجود دارد؟',!!elT,elT?('disabled='+elT.disabled+' data-on="'+(elT.dataset?elT.dataset.on:'?')+'"'):'getElementById → null');
			h+=srow('۲. آیا #gs-wt-run در DOM وجود دارد؟',!!elR,elR?('disabled='+elR.disabled):'getElementById → null');
			h+=srow('۳. آیا STI در زمان اجرای script وجود دارد؟',st.t==='object','typeof STI = '+st.t+' (at load)');
			h+=srow('۴. آیا STI.nonce مقدار دارد؟',!!(st.n&&st.n!=='(undefined)'),st.n);
			h+=srow('مکمل: STI.ajaxUrl / window.ajaxurl',!!(st.u&&st.u!=='(undefined)'),st.u+' / '+(typeof window.ajaxurl==='string'&&window.ajaxurl?window.ajaxurl:'(undefined)'));
			h+=srow('۵. آیا Script مربوط به Watcher اجرا شده است؟',settled?(far>0):(far>0),settled?(far?('executed up to line '+far+' (last REG there)'):'NOT executed — zero REG records (parse error / script absent)'):'script section not reached yet…');
			h+=srow('۶. آیا bind() واقعاً listener ثبت کرده؟',!!rt,rt?('registered @'+rt.t+' — '+rt.b):(settled?'no non-probe REG record for click':'awaiting bind() call…'));
			S.innerHTML=h;
		}
		function renderTrace(){
			if(!events.length){T.innerHTML='<div dir="ltr" style="opacity:.55;font-family:ui-monospace,monospace;font-size:11px;">— no records yet. Click the REAL button (toggle / run) to trace the chain…</div>';return;}
			var h='';
			for(var i=0;i<events.length;i++){var e=events[i];h+='<div dir="ltr" style="font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:pre-wrap;word-break:break-all;line-height:1.5;">['+e.t+'] <b>'+e.tag+'</b> '+esc(e.a)+(e.b?(' — '+esc(e.b)):'')+'</div>';}
			T.innerHTML=h;
		}
		function verdict(){
			function out(P,E,F,Lx,N){
				V.innerHTML='<div style="border-top:2px solid #b45309;padding-top:8px;font-family:ui-monospace,Consolas,monospace;font-size:12px;line-height:1.8;white-space:pre-wrap;word-break:break-all;"><b>FIRST BROKEN POINT:</b>\n'+esc(P)+'\n\n<b>EVIDENCE:</b>\n'+esc(E)+'\n\n<b>FILE:</b>\n'+esc(F)+'\n\n<b>LINE:</b>\n'+esc(Lx)+'\n\n<b>NEXT ACTION:</b>\n'+esc(N)+'</div>';
			}
			var cl=null,fc,rp,rj,rt;
			for(var i=0;i<events.length;i++){if(events[i].tag==='CLICK'){cl=events[i];break;}}
			fc=findMany('FETCH');rp=findMany('RESP');rj=findMany('FETCH-REJECT');if(!rj.length){rj=findMany('FETCH-THROW');}
			rt=regNonProbe('gs-wt-toggle');
			var act='';
			if(fc.length&&fc[fc.length-1].meta&&fc[fc.length-1].meta.action){act=fc[fc.length-1].meta.action;}
			var phpRef=act.indexOf('run')>-1?LN.phpRun:LN.phpToggle;
			if(!elT){
				out('HTML — element #gs-wt-toggle is NOT rendered in the DOM','getElementById("gs-wt-toggle") returned null on page load','admin/views/golden-scan/worker.php',LN.toggleBtn,'check the PHP condition that wraps the Watcher card (the if/endif block above the button); the script section is fine — there is simply nothing to bind.');
				return;
			}
			if(settled&&!cl&&!rt){
				out('bind() / script execution — no click listener registered on #gs-wt-toggle','no non-probe REG record for gs-wt-toggle after full page load; the bind() helper silently skips when the element is missing','admin/views/golden-scan/worker.php',LN.bindWt+' (helper: '+LN.bindHelper+')','verify the <script> block (line '+LN.scriptTag+') is present in the rendered DOM and no earlier error aborted it; confirm the element existed BEFORE the script ran.');
				return;
			}
			if(!cl){
				out('(none yet) — NO BROKEN POINT DETECTED; awaiting click','static checks Q1..Q6 are in the table above','—','—','click the REAL button (▶/⏸ or 🔄) — this box recomputes automatically after each event.');
				return;
			}
			if(!fc.length){
				var cs=lastClickSti||stiSnap();
				if(cs.t!=='object'){
					out('handler — ReferenceError: STI is not defined (dies at first statement of post(), before any fetch)','CLICK reached the element but fetch() was never called; typeof STI at click = '+cs.t,'admin/views/golden-scan/worker.php (STI.nonce reference) + root cause: admin/class-sti-admin.php',LN.postNonce+' (root: '+LN.adminLocalize+')','STI is wp_localize_script-ed on the "sti-admin" handle (admin.js, footer). Verify that script actually loads on THIS page (if the console renders in an iframe / partial / early-exit render, the footer never runs → STI never exists).');
					return;
				}
				if(cs.u==='(undefined)'&&!(typeof window.ajaxurl==='string'&&window.ajaxurl)){
					out('handler — fetch() called with undefined URL (STI.ajaxUrl missing AND window.ajaxurl missing)','CLICK reached the element; STI exists but ajaxUrl is undefined and the ajaxurl fallback is undefined; no FETCH record','admin/views/golden-scan/worker.php',LN.fetchLine,'inspect how STI is localized on this page (class-sti-admin.php line '+LN.adminLocalize+'); the fetch target must be admin-ajax.php.');
					return;
				}
				out('handler — exception between click and fetch()','CLICK reached the element but fetch() was never called while STI/nonce/ajaxUrl look valid','admin/views/golden-scan/worker.php',LN.bindWt+'..'+LN.fetchLine,'inspect the handler body (currently: read dataset.on, call post()) for the thrown value; this branch is unlikely given current code.');
				return;
			}
			if(rj.length){
				out('AJAX — request dispatched but never reached the server (network/transport failure)','FETCH '+fc[fc.length-1].a+' → '+rj[0].b,'URL from FETCH record (admin-ajax.php target)','—','Per the rule: request did not complete — do NOT inspect PHP yet. Verify URL reachability/proxy/redirects for that exact URL (e.g. proxy rewriting POSTs, or login-redirect loop).');
				return;
			}
			if(!rp.length){
				out('(in flight) — request sent, response not captured yet','FETCH '+fc[fc.length-1].a+' dispatched; awaiting response','—','—','wait a moment — the RESP record appears automatically; if it never does, treat as network/timeout case.');
				return;
			}
			var m=rp[rp.length-1].meta||{};
			if(m.status!==200){
				out('PHP/AJAX endpoint — HTTP '+m.status+' from admin-ajax.php','action='+(act||'?')+' → HTTP '+m.status+'; raw body: '+truncate(m.raw||'?',300),'includes/golden-scan/class-gs-test-wizard.php',LN.phpToggle+' / '+LN.phpRun,'Per the rule: AJAX was sent, PHP answered with an error status. 400 → check_ajax() failed (nonce/capability); 500 → PHP fatal. Inspect the endpoint + its nonce check.');
				return;
			}
			if(!m.json){
				out('PHP — HTTP 200 but response is NOT JSON (JSON.parse in post() will throw; toggle handler has no .catch() → silent)','action='+(act||'?')+' → HTTP 200, body is not JSON: '+truncate(m.raw||'?',300),'includes/golden-scan/class-gs-test-wizard.php (ajax_watcher_toggle / ajax_watcher_run)','L'+LN.phpToggle+' / L'+LN.phpRun,'Per the rule: AJAX sent, PHP did not answer properly — jump to the PHP endpoint. Non-JSON on 200 = HTML output (login redirect? cached page? buffered warning/fatal?). Inspect the endpoint output path on the host.');
				return;
			}
			if(m.success===false){
				out('PHP business logic — handler executed and returned success:false','action='+(act||'?')+' → HTTP 200 JSON success=false, message: '+(m.msg||'(none)'),'includes/golden-scan/class-gs-test-wizard.php','L'+phpRef,'read the message (most likely check_ajax() nonce/capability failure) and inspect that endpoint on the host.');
				return;
			}
			out('NO BROKEN POINT in the chain — HTML→script→bind→click→handler→AJAX→PHP all verified OK','action='+(act||'?')+' → HTTP 200 JSON success=true, message: '+(m.msg||'(none)')+'; toggle handler will now location.reload()','STI_GS_Channel_Watcher::set_enabled() / run() (PHP layer)','—','If the Watcher state did NOT change after the reload → per the rule enter toggle()/run() at the PHP layer (stage 3). Otherwise the chain is healthy.');
		}
		function renderAll(){
			try{renderStatic();}catch(e0){}
			try{renderTrace();}catch(e1){}
			try{verdict();}catch(e2){}
		}
		function settle(){if(!settled){settled=true;renderAll();}}
		if(document.readyState==='complete'){setTimeout(settle,400);}
		else{window.addEventListener('load',settle);setTimeout(settle,2000);}
		var rb=document.getElementById('gs-wt-diag-reset');
		if(rb){rb.addEventListener('click',function(){events.length=0;lastClickSti=null;renderAll();});}
		renderAll();
	}catch(topErr){}
	})();
	</script>

	<!-- ===== STI-CHAIN-AUDIT v1 (10.12.3) — Real Automation Chain Test — read-only ===== -->
	<div id="gs-ca-card" style="margin:0 0 16px;border:2px solid #2563eb;border-radius:12px;background:#eff6ff;direction:rtl;">
		<div style="padding:12px 14px;">
			<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
				<h2 style="margin:0;font-size:16px;">🩺 تست واقعی زنجیره اتوماسیون</h2>
				<span style="font-size:11px;opacity:.7;">Internal Runtime Diagnostic — روی Runtime واقعی همین سایت</span>
			</div>
			<p style="margin:6px 0 10px;font-size:12px;opacity:.85;">با یک کلیک، زنجیره کامل (Profile ← Profile Item ← Message ← Selection ← Watcher ← AJAX ← create_sessions ← Session ← Pipeline ← Worker ← Telegram ← Fiber) با دادهٔ واقعی خوانده می‌شود — <strong>فقط خواندنی</strong> (SELECT / get_option): هیچ Session، Profile، Queue، Worker یا Watcher تغییر نمی‌کند.</p>
			<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<button type="button" id="gs-ca-run" class="gi-btn gi-btn--danger" style="background:#2563eb;border-color:#2563eb;color:#fff;">🩺 اجرای تست Runtime</button>
				<button type="button" id="gs-ca-opt-toggle" class="gi-btn gi-btn--subtle">⚠️ تست واقعی Toggle</button>
				<button type="button" id="gs-ca-opt-run" class="gi-btn gi-btn--subtle">⚠️ تست واقعی Watcher Run</button>
			</div>
			<p style="margin:8px 0 0;font-size:11px;color:#b45309;">دو دکمهٔ ⚠️ اختیاری هستند و خودکار اجرا نمی‌شوند؛ <strong>Runtime واقعی را اجرا می‌کنند و ممکن است state/queue را تغییر دهند</strong> — فقط با تأیید صریح شما.</p>
			<div id="gs-ca-result" style="display:none;margin-top:12px;"></div>
		</div>
	</div>
	<script>
	/* STI-CHAIN-AUDIT v1 (10.12.3) — renders the runtime chain audit in-page. */
	(function(){
	'use strict';
	try{
		var runBtn=document.getElementById('gs-ca-run'),
		    optT=document.getElementById('gs-ca-opt-toggle'),
		    optR=document.getElementById('gs-ca-opt-run'),
		    panel=document.getElementById('gs-ca-result');
		if(!runBtn||!panel){return;}

		/* PART 4 — DOM check (بعد از render کامل صفحه). */
		var dom={card:'MISSING',toggle:'MISSING',run:'MISSING',sti:'?',checked:false};
		function domCheck(){
			var t=document.getElementById('gs-wt-toggle'),
			    r=document.getElementById('gs-wt-run');
			dom.toggle=t?'FOUND':'MISSING';
			dom.run=r?'FOUND':'MISSING';
			if(t&&t.closest){var c=t.closest('.gi-card');dom.card=c?(c.offsetParent===null?'FOUND (hidden)':'FOUND'):'MISSING (no .gi-card)';}
			else{dom.card=t?'FOUND (no card wrapper)':'MISSING';}
			dom.sti=(typeof window.STI==='object'&&window.STI)?'defined':'UNDEFINED';
			dom.checked=true;
		}
		if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',domCheck);}
		else{domCheck();}

		function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
		function post(action,data){
			var nonce=(typeof window.STI==='object'&&window.STI)?window.STI.nonce:null,
			    url=(typeof window.STI==='object'&&window.STI&&window.STI.ajaxUrl)?window.STI.ajaxUrl:(typeof window.ajaxurl==='string'?window.ajaxurl:null);
			if(!nonce||!url){
				return Promise.resolve({ok:false,meta:{status:'NO_TRANSPORT',json:null,raw:'STI/nonce/ajaxUrl در دسترس نیست (STI='+((typeof window.STI==='object'&&window.STI)?'object':'undefined')+', nonce='+(nonce===null?'null':esc(nonce))+') — این خود یک یافتهٔ Runtime است: localize در این صفحه emit نشده.'}});
			}
			var b=new URLSearchParams(Object.assign({action:action,nonce:nonce},data||{}));
			return fetch(url,{method:'POST',credentials:'same-origin',body:b})
				.then(function(r){return r.text().then(function(t){return {status:r.status,text:t};});})
				.then(function(o){
					try{return {ok:true,meta:{status:o.status,json:JSON.parse(o.text),raw:o.text.slice(0,400)}};}
					catch(e){return {ok:true,meta:{status:o.status,json:null,raw:o.text.slice(0,400)}};}
				})
				.catch(function(e){return {ok:false,meta:{status:'FETCH_ERROR',json:null,raw:String(e&&e.message||e)}};});
		}
		function kvTable(o,depth){
			depth=depth||0;
			if(typeof o!=='object'||o===null){return '<span dir="ltr">'+esc(o)+'</span>';}
			if(Array.isArray(o)){
				if(!o.length){return '<span dir="ltr" style="opacity:.6">[]</span>';}
				var h='<div style="margin:2px 0 2px '+(depth*12)+'px;">';
				for(var i=0;i<Math.min(o.length,30);i++){
					h+='<div dir="ltr" style="font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:pre-wrap;word-break:break-all;">'+(typeof o[i]==='object'&&o[i]!==null?'['+i+'] ':esc(i)+': ')+kvTable(o[i],depth+1)+'</div>';
				}
				if(o.length>30){h+='<div style="opacity:.5;font-size:11px;">… '+(o.length-30)+' more</div>';}
				return h+'</div>';
			}
			var h2='<table style="border-collapse:collapse;width:100%;margin:4px 0;">';
			for(var k in o){
				if(!Object.prototype.hasOwnProperty.call(o,k)){continue;}
				h2+='<tr><td style="vertical-align:top;padding:1px 8px 1px 0;white-space:nowrap;" dir="ltr"><b style="font-family:ui-monospace,monospace;font-size:11px;">'+esc(k)+'</b></td><td style="vertical-align:top;font-size:11px;">'+kvTable(o[k],depth+1)+'</td></tr>';
			}
			return h2+'</table>';
		}
		function section(title,body,open){
			return '<details '+(open?'open':'')+' style="margin:8px 0;border:1px solid #c7d7f5;border-radius:8px;background:#fff;"><summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:700;">'+esc(title)+'</summary><div style="padding:0 10px 8px;">'+body+'</div></details>';
		}
		function renderAudit(res){
			var d=res.data||{};
			var rows=d.rows||[];
			/* PART 4 — inject DOM results into the Watcher UI row + recompute first break: */
			var uiRow=null,firstBreak=null;
			for(var i=0;i<rows.length;i++){
				if(rows[i].name==='Watcher UI'){
					uiRow=rows[i];
					var uiOk=(dom.toggle==='FOUND'&&dom.run==='FOUND'&&dom.card.indexOf('FOUND')===0);
					uiRow.result=uiOk?'PASS':'FAIL';
					uiRow.evidence='Watcher card='+dom.card+' · #gs-wt-toggle='+dom.toggle+' · #gs-wt-run='+dom.run+' · STI='+dom.sti+' (at DOMContentLoaded)';
				}
			}
			for(var j=0;j<rows.length;j++){if(rows[j].result==='FAIL'){firstBreak=rows[j];break;}}
			var v=d.verdict||{};
			var h='';
			/* Headline (PART 15) */
			h+='<div style="border:2px solid #1d4ed8;border-radius:10px;background:#dbeafe;padding:10px 12px;font-size:14px;font-weight:800;margin-bottom:10px;">';
			h+='🔎 '+esc(v.headline||'(no headline)');
			h+='</div>';
			if(v.headline_detail){h+='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;white-space:pre-wrap;word-break:break-all;background:#fff;border:1px solid #c7d7f5;border-radius:8px;padding:8px 10px;margin-bottom:10px;">'+esc(v.headline_detail)+'</div>';}
			/* FIRST HARD BREAK */
			h+='<div dir="ltr" style="border:2px solid #dc2626;border-radius:10px;background:#fef2f2;padding:10px 12px;font-family:ui-monospace,Consolas,monospace;font-size:12px;line-height:1.8;white-space:pre-wrap;word-break:break-all;margin-bottom:10px;"><b>FIRST HARD BREAK</b>\n'+(firstBreak?(esc(firstBreak.letter)+' — '+esc(firstBreak.name)):'(none detected)')+'\nEvidence:\n'+(firstBreak?esc(firstBreak.evidence):'all rows PASS (or no data)')+'</div>';
			/* Final table */
			h+='<div style="overflow-x:auto;"><table style="border-collapse:collapse;width:100%;background:#fff;font-size:11px;"><thead><tr><th style="border-bottom:2px solid #1d4ed8;padding:4px 6px;text-align:right;">Chain</th><th style="border-bottom:2px solid #1d4ed8;padding:4px 6px;text-align:right;">Runtime Result</th><th style="border-bottom:2px solid #1d4ed8;padding:4px 6px;text-align:right;">Evidence</th></tr></thead><tbody>';
			for(var r=0;r<rows.length;r++){
				var rr=rows[r],col=rr.result==='FAIL'?'#b91c1c':(rr.result==='PASS'?'#15803d':'#1d4ed8');
				h+='<tr style="border-bottom:1px solid #e5eefc;"><td style="padding:4px 6px;white-space:nowrap;font-weight:700;">'+esc(rr.letter)+' · '+esc(rr.name)+'</td><td style="padding:4px 6px;white-space:nowrap;"><b dir="ltr" style="color:'+col+';">'+esc(rr.result)+'</b></td><td dir="ltr" style="padding:4px 6px;font-family:ui-monospace,monospace;font-size:10.5px;white-space:pre-wrap;word-break:break-all;max-width:420px;">'+esc(rr.evidence)+'</td></tr>';
			}
			h+='</tbody></table></div>';
			/* Details per part */
			h+=section('PART 1 — Runtime Snapshot',kvTable(d.snapshot),false);
			h+=section('PART 2 — Real Database Read',kvTable(d.db),false);
			h+=section('PART 3 — Real Watcher State (option / is_enabled / stats)',kvTable(d.watcher_state),true);
			h+=section('PART 5 — AJAX Registration (runtime hooks)',kvTable(d.ajax_registration),false);
			h+=section('PART 7 — Real Run Path Trace',kvTable(d.run_path),true);
			h+=section('PART 8 — create_sessions Read-Only Simulation',kvTable(d.simulation),true);
			/* 🔬 Selection Window Audit (10.12.4) */
			var sw=d.selection_window;
			if(sw&&typeof sw==='object'&&!(sw.error)){
				var h2='<h3 style="font-size:13px;margin:12px 0 4px;">🔬 Selection Window Audit (read-only — Rیشه‌یابی NO_ITEM)</h3>';
				h2+='<div dir="ltr" style="border:2px solid #7c3aed;border-radius:8px;background:#f5f3ff;padding:8px 10px;font-size:12px;font-weight:800;margin-bottom:8px;">'+esc(sw.verdict_break||'')+'</div>';
				h2+='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;margin-bottom:6px;">eligible_total='+sw.eligible_total+' · orphan_total='+sw.orphan_total+' · valid_total='+sw.valid_total+' · scanned_candidates='+sw.scanned_candidates+'</div>';
				if(sw.windows){
					var wt='<table style="border-collapse:collapse;background:#fff;font-size:11px;width:100%;"><thead><tr><th style="border-bottom:2px solid #7c3aed;padding:3px 6px;text-align:right;">Window</th><th style="border-bottom:2px solid #7c3aed;padding:3px 6px;">NO_ITEM</th><th style="border-bottom:2px solid #7c3aed;padding:3px 6px;">VALID</th><th style="border-bottom:2px solid #7c3aed;padding:3px 6px;">EXISTING_SESSION</th></tr></thead><tbody>';
					var wl=[['first 20','20'],['first 100','100'],['first 500','500']];
					for(var wi=0;wi<wl.length;wi++){
						var wv=sw.windows[wl[wi][1]]||{};
						wt+='<tr style="border-bottom:1px solid #ede9fe;"><td style="padding:3px 6px;font-weight:700;">'+wl[wi][0]+'</td><td style="padding:3px 6px;text-align:center;color:#b91c1c;font-weight:700;">'+(wv.NO_ITEM||0)+'</td><td style="padding:3px 6px;text-align:center;color:#15803d;font-weight:700;">'+(wv.VALID||0)+'</td><td style="padding:3px 6px;text-align:center;">'+(wv.EXISTING_SESSION||0)+'</td></tr>';
					}
					wt+='</tbody></table>';
					h2+=wt;
				}
				if(sw.orphan_types){h2+='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;margin-top:6px;">orphan_types(window) = '+esc(JSON.stringify(sw.orphan_types))+'</div>';}
				if(sw.sample_rows&&sw.sample_rows.length){
					var st='<table style="border-collapse:collapse;background:#fff;font-size:10.5px;width:100%;margin-top:6px;"><thead><tr><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">rank</th><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">profile_item.id</th><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">profile_id</th><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">message_pk (real value)</th><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">status</th><th style="border-bottom:2px solid #7c3aed;padding:3px 5px;">class</th></tr></thead><tbody>';
					for(var si=0;si<sw.sample_rows.length;si++){
						var sr=sw.sample_rows[si];
						st+='<tr style="border-bottom:1px solid #ede9fe;"><td style="padding:3px 5px;text-align:center;">'+sr.rank+'</td><td style="padding:3px 5px;text-align:center;">'+sr.id+'</td><td style="padding:3px 5px;text-align:center;">'+(sr.profile_id===null?'?':sr.profile_id)+'</td><td style="padding:3px 5px;text-align:center;font-weight:700;color:'+(sr.message_pk===0||sr.message_pk===null?'#b91c1c':'#15803d')+';">'+(sr.message_pk===null?'?':sr.message_pk)+'</td><td style="padding:3px 5px;text-align:center;">'+esc(sr.status)+'</td><td style="padding:3px 5px;text-align:center;color:'+(sr.class==='NO_ITEM'?'#b91c1c':'#15803d')+';font-weight:700;">'+esc(sr.class)+'</td></tr>';
					}
					st+='</tbody></table>';
					h2+=section('کاندیداهای ۱ تا ۱۰ (خوانده‌شده مستقیم از profile_items — بدون JOIN)',st,false);
				}
				if(sw.first_orphan||sw.first_valid){
					var cmp='<div style="display:flex;gap:8px;flex-wrap:wrap;">';
					cmp+='<pre dir="ltr" style="flex:1;min-width:260px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;"><b>BROKEN CANDIDATE</b>\n'+esc(JSON.stringify(sw.first_orphan||{},null,2))+'</pre>';
					cmp+='<pre dir="ltr" style="flex:1;min-width:260px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;"><b>HEALTHY CANDIDATE</b>\n'+esc(JSON.stringify(sw.first_valid||{},null,2))+'</pre>';
					cmp+='</div>';
					h2+=section('BROKEN vs HEALTHY (مقایسه زنجیره کامل)',cmp,true);
				}
				if(sw.reconcile){h2+=section('PART 8b — Reconcile با DB همین لحظه (بعد از Run واقعی)',kvTable(sw.reconcile),false);}
				h2+=section('📋 SELECTION AUDIT (خروجی نهایی — قابل کپی)','<pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:10px;font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-all;margin:0;">'+esc(sw.audit_text||'')+'</pre>',true);
				h+=h2;
			}
			/* 🧬 Orphan Root-Cause Audit (10.12.5) */
			var oc=d.orphan_root_cause;
			if(oc&&typeof oc==='object'&&!(oc.error)){
				var h3='<h3 style="font-size:13px;margin:12px 0 4px;">🧬 Orphan Root-Cause Audit (read-only — HOW / WHERE / WHY / WHEN)</h3>';
				h3+='<div dir="ltr" style="border:2px solid #be123c;border-radius:8px;background:#fff1f2;padding:8px 10px;font-size:12px;font-weight:800;margin-bottom:8px;">'+esc(oc.lean||'')+'</div>';
				var pp=oc.population||{};
				h3+='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;margin-bottom:6px;">total='+pp.total+' · by_status='+esc(JSON.stringify(oc.by_status||{}))+' · NO_PK(0/NULL)='+pp.no_pk+' · DANGLING='+pp.dangling+' · PROFILE_MISSING='+pp.profile_missing+' · VALID='+pp.valid+' · ORPHAN_TOTAL='+oc.orphan_total+'</div>';
				if(oc.distribution){
					var dt='<div style="display:flex;gap:8px;flex-wrap:wrap;">';
					dt+='<div style="flex:1;min-width:220px;"><b>by profile_id (top 10)</b><pre dir="ltr" style="background:#fff;border:1px solid #fecdd3;border-radius:8px;padding:6px;font-size:10px;white-space:pre-wrap;word-break:break-all;margin:4px 0 0;">'+esc(JSON.stringify(oc.distribution.by_profile||[],null,1))+'</pre></div>';
					dt+='<div style="flex:1;min-width:220px;"><b>by status / by keyword</b><pre dir="ltr" style="background:#fff;border:1px solid #fecdd3;border-radius:8px;padding:6px;font-size:10px;white-space:pre-wrap;word-break:break-all;margin:4px 0 0;">'+esc(JSON.stringify(oc.distribution.by_status||[],null,1))+'\n'+esc(JSON.stringify(oc.distribution.by_keyword||[],null,1))+'</pre></div>';
					dt+='<div style="flex:1;min-width:220px;"><b>by month (created_at)</b><pre dir="ltr" style="background:#fff;border:1px solid #fecdd3;border-radius:8px;padding:6px;font-size:10px;white-space:pre-wrap;word-break:break-all;margin:4px 0 0;">'+esc(JSON.stringify(oc.distribution.by_month||[],null,1))+'</pre></div>';
					dt+='</div>';
					h3+=dt;
				}
				var sm=oc.samples||{};
				if(sm.orphan||sm.healthy){
					var cmp2='<div style="display:flex;gap:8px;flex-wrap:wrap;">';
					cmp2+='<pre dir="ltr" style="flex:1;min-width:260px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;"><b>ORPHAN (first by id)</b>\nfirst_ids='+esc(JSON.stringify(sm.orphan_first_ids||[]))+'\n'+esc(JSON.stringify(sm.orphan||{},null,2))+'</pre>';
					cmp2+='<pre dir="ltr" style="flex:1;min-width:260px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;"><b>HEALTHY (first by id)</b>\n'+esc(JSON.stringify(sm.healthy||{},null,2))+'\n<b>HEALTHY same-profile</b>\n'+esc(JSON.stringify(sm.healthy_same_profile||null,null,2))+'</pre>';
					cmp2+='</div>';
					h3+=section('PART 5 — ORPHAN vs HEALTHY (از DB واقعی)',cmp2,true);
				}
				if(oc.timeline){
					var tl='<pre dir="ltr" style="background:#fff;border:1px solid #fecdd3;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;"><b>extremes</b>\n'+esc(JSON.stringify(oc.timeline.extremes||{},null,1))+'\n<b>daily (last 14d)</b>\n'+esc(JSON.stringify(oc.timeline.daily_14d||[],null,1))+'</pre>';
					h3+=section('PART 6/7 — Timeline + Recent Production (24h/today)',tl,false);
				}
				var sc=oc.schema||{};
				if(sc.columns){
					var sch='<pre dir="ltr" style="background:#fff;border:1px solid #fecdd3;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;">'+esc(JSON.stringify(sc.columns,null,1))+'\n'+esc(JSON.stringify(sc.indexes||[],null,1))+'</pre>';
					h3+=section('PART 1 — Schema واقعی (SHOW COLUMNS / SHOW INDEX از Runtime)',sch,false);
				}
				var sf=oc.static_facts||{};
				if(sf.write_paths){
					var wp2='<table style="border-collapse:collapse;background:#fff;font-size:10.5px;width:100%;"><thead><tr><th style="border-bottom:2px solid #be123c;padding:3px 5px;text-align:right;">SQL path</th><th style="border-bottom:2px solid #be123c;padding:3px 5px;text-align:right;">FILE / FUNCTION / LINE / CALLER / WHEN</th></tr></thead><tbody>';
					for(var wk in sf.write_paths){
						if(!Object.prototype.hasOwnProperty.call(sf.write_paths,wk)){continue;}
						wp2+='<tr style="border-bottom:1px solid #ffe4e6;"><td dir="ltr" style="padding:3px 5px;font-family:ui-monospace,monospace;vertical-align:top;">'+esc(wk)+'</td><td dir="ltr" style="padding:3px 5px;white-space:pre-wrap;word-break:break-all;vertical-align:top;">'+esc(sf.write_paths[wk])+'</td></tr>';
					}
					wp2+='</tbody></table>';
					wp2+='<div dir="ltr" style="font-size:10.5px;margin-top:6px;white-space:pre-wrap;word-break:break-all;"><b>no_other_writers:</b> '+esc(sf.no_other_writers||'')+'</div>';
					wp2+='<div dir="ltr" style="font-size:10.5px;margin-top:6px;white-space:pre-wrap;word-break:break-all;"><b>scanner_path:</b> '+esc(sf.scanner_path||'')+'</div>';
					h3+=section('PART 3/4 — Creation Paths + Scanner Chain (Static، از کد 10.12.5)',wp2,false);
				}
				h3+='<details open style="margin:8px 0;border:1px solid #fda4af;border-radius:8px;background:#fff;"><summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:700;">📋 ORPHAN AUDIT EVIDENCE (قابل کپی)</summary><div style="padding:0 10px 10px;"><pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:10px;font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-all;margin:0;">'+esc(oc.audit_text||'')+'</pre></div></details>';
				h+=h3;
			}
			/* 🎯 PHASE 1 — Root Cause Verification + Preflight + Line Forensics (10.12.6) */
			var pv=d.phase1_verification;
			if(pv&&typeof pv==='object'&&!(pv.error)){
				var h4='<h3 style="font-size:13px;margin:12px 0 4px;">🎯 PHASE 1 — Root Cause Verification + Patch Preflight + Line Forensics (read-only)</h3>';
				h4+='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;margin-bottom:6px;">room='+pv.room+' · first_valid_id='+(pv.first_valid_id===null?'NONE':pv.first_valid_id)+' · first_valid_rank='+(pv.first_valid_rank===null?'n/a':pv.first_valid_rank)+' · orphans_before_first_valid='+(pv.orphans_before===null?'n/a':pv.orphans_before)+'</div>';
				if(pv.batch_occupancy){
					var bo='<table style="border-collapse:collapse;background:#fff;font-size:11px;width:100%;"><thead><tr><th style="border-bottom:2px solid #0369a1;padding:3px 6px;">Window</th><th style="border-bottom:2px solid #0369a1;padding:3px 6px;">VALID</th><th style="border-bottom:2px solid #0369a1;padding:3px 6px;">NO_ITEM</th><th style="border-bottom:2px solid #0369a1;padding:3px 6px;">EXISTING</th></tr></thead><tbody>';
					for(var bk in pv.batch_occupancy){
						if(!Object.prototype.hasOwnProperty.call(pv.batch_occupancy,bk)){continue;}
						var bv=pv.batch_occupancy[bk];
						bo+='<tr style="border-bottom:1px solid #e0f2fe;"><td style="padding:3px 6px;font-weight:700;">'+bk+'</td><td style="padding:3px 6px;text-align:center;color:#15803d;font-weight:700;">'+bv.valid+'</td><td style="padding:3px 6px;text-align:center;color:#b91c1c;font-weight:700;">'+bv.no_item+'</td><td style="padding:3px 6px;text-align:center;">'+bv.existing+'</td></tr>';
					}
					bo+='</tbody></table>';
					h4+=bo;
				}
				h4+=section('query فعلی production + EXPLAIN (از Runtime)','<pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0;">'+esc(pv.sql_current||'')+'\n\nEXPLAIN:\n'+esc(JSON.stringify(pv.explain_current||[],null,1))+'</pre>',false);
				var pf=pv.preflight||{};
				if(pf.sql_fixed){
					var h4b='<div dir="ltr" style="font-size:11px;font-weight:700;margin:8px 0 4px;color:#0369a1;">PREFLIGHT — query «پس از Patch» (Option A) — اجرا شد ولی Patch اعمال نشد: would_create_postfix='+(pf.would_create_postfix||0)+' · existing='+(pf.existing_session_postfix||0)+'</div>';
					h4b+='<pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:8px;font-size:10.5px;white-space:pre-wrap;word-break:break-all;margin:0 0 6px;">'+esc(pf.sql_fixed||'')+'</pre>';
					var ft='<table style="border-collapse:collapse;background:#fff;font-size:10.5px;width:100%;"><thead><tr><th style="border-bottom:2px solid #0369a1;padding:3px 5px;">profile_item.id</th><th style="border-bottom:2px solid #0369a1;padding:3px 5px;">message_pk</th><th style="border-bottom:2px solid #0369a1;padding:3px 5px;">existing_session</th><th style="border-bottom:2px solid #0369a1;padding:3px 5px;">would_create</th></tr></thead><tbody>';
					for(var fi=0;fi<(pf.first_candidates||[]).length;fi++){
						var fc2=pf.first_candidates[fi];
						ft+='<tr style="border-bottom:1px solid #e0f2fe;"><td style="padding:3px 5px;text-align:center;">'+fc2.id+'</td><td style="padding:3px 5px;text-align:center;">'+fc2.message_pk+'</td><td style="padding:3px 5px;text-align:center;">'+(fc2.existing_session===null?'—':'#'+fc2.existing_session)+'</td><td style="padding:3px 5px;text-align:center;color:'+(fc2.would_create?'#15803d':'#b45309')+';font-weight:700;">'+(fc2.would_create?'YES':'no (existing)')+'</td></tr>';
					}
					ft+='</tbody></table>';
					h4b+=ft;
					h4+=h4b;
				}
				var lf=pv.line_forensics||{};
				if(lf.option_name){
					var lf2='<div dir="ltr" style="font-size:11px;font-family:ui-monospace,monospace;background:#fff;border:1px solid #bae6fd;border-radius:8px;padding:8px;white-space:pre-wrap;word-break:break-all;">option='+esc(lf.option_name)+'\nraw_value='+esc(JSON.stringify(lf.raw_value))+' (set='+lf.option_set+')\nstate()='+esc(JSON.stringify(lf['state()']))+'\ninterpret: '+esc(lf.interpret||'')+'</div>';
					lf2+=section('recent line logs (wp_sti_logs)',kvTable(lf.recent_line_logs||[]),false);
					var wts='<table style="border-collapse:collapse;background:#fff;font-size:10.5px;width:100%;margin-top:6px;"><thead><tr><th style="border-bottom:2px solid #0369a1;padding:3px 5px;text-align:right;">Transition</th><th style="border-bottom:2px solid #0369a1;padding:3px 5px;text-align:right;">FILE / LINE / CALLER</th></tr></thead><tbody>';
					var lw=lf.writers||{};
					for(var lkw in lw){if(!Object.prototype.hasOwnProperty.call(lw,lkw)){continue;}wts+='<tr style="border-bottom:1px solid #e0f2fe;"><td dir="ltr" style="padding:3px 5px;font-family:ui-monospace,monospace;vertical-align:top;">'+esc(lkw)+'</td><td dir="ltr" style="padding:3px 5px;white-space:pre-wrap;word-break:break-all;vertical-align:top;">'+esc(lw[lkw])+'</td></tr>';}
					wts+='</tbody></table>';
					lf2+='<div dir="ltr" style="font-size:10.5px;margin-top:6px;"><b>blocker:</b> '+esc(lf.worker_blocker||'')+'<br><b>start gates:</b> '+esc(lf.start_gates||'')+'</div>';
					h4+=section('PHASE 4 — Line-State Forensics (چرا line_state=STOPPED)',lf2+wts,true);
				}
				h4+='<details open style="margin:8px 0;border:1px solid #7dd3fc;border-radius:8px;background:#fff;"><summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:700;">📋 PHASE 1 EVIDENCE (قابل کپی)</summary><div style="padding:0 10px 10px;"><pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:10px;font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-all;margin:0;">'+esc(pv.audit_text||'')+'</pre></div></details>';
				h+=h4;
			}
			h+=section('PART 9 — create_from_profile_item Path',kvTable(d.session_path),false);
			h+=section('PART 10 — Real Session Check (sample ≤ 10)',kvTable(d.session_sample),true);
			h+=section('PART 11 — Real Pipeline Check (last 10)',kvTable(d.pipeline_sample),false);
			h+=section('PART 12 — Real Worker Read-Only Check',kvTable(d.worker),true);
			h+=section('PART 13 — Fiber / Memory',kvTable(d.fiber_memory),false);
			h+=section('Telegram (row G)',kvTable(d.telegram),false);
			h+='<div dir="ltr" style="font-size:10px;opacity:.55;margin-top:8px;">generated_at='+esc(d.generated_at||'')+' · plugin_version='+esc(d.version||'')+'</div>';
			panel.innerHTML=h;
			panel.style.display='block';
		}
		runBtn.addEventListener('click',function(){
			runBtn.disabled=true;
			runBtn.textContent='⏳ در حال اجرای تست Runtime…';
			panel.style.display='block';
			panel.innerHTML='<div style="font-size:12px;opacity:.7;padding:8px 0;">در حال خواندن Runtime واقعی (فقط SELECT/get_option)…</div>';
			post('sti_gs_chain_audit',{}).then(function(res){
				runBtn.disabled=false;
				runBtn.textContent='🩺 اجرای تست Runtime';
				if(res.ok&&res.meta.json&&res.meta.json.success){renderAudit(res.meta.json);}
				else{
					var m=res.meta||{};
					panel.innerHTML='<div dir="ltr" style="border:2px solid #dc2626;border-radius:10px;background:#fef2f2;padding:10px 12px;font-family:ui-monospace,monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;">AJAX transport result:\nstatus='+esc(m.status)+'\nsuccess='+esc(m.json?m.json.success:'(no json)')+'\nmessage='+esc(m.json&&m.json.data?m.json.data.message:'')+'\nraw='+esc(m.raw||'')+'</div>';
				}
			});
		});
		function optionalTest(action,label,payload){
			if(!confirm(label+' — این تست Runtime واقعی را اجرا می‌کند و ممکن است state/queue را تغییر دهد. ادامه؟')){return;}
			var box=document.createElement('div');
			box.style.cssText='margin-top:8px;border:1px solid #f59e0b;border-radius:8px;background:#fffbeb;padding:8px 10px;font-size:11px;';
			box.innerHTML='<span dir="ltr" style="font-family:ui-monospace,monospace;">⏳ ارسال ' + esc(action) + '…</span>';
			panel.appendChild(box);
			post(action,payload).then(function(res){
				var m=res.meta||{};
				box.innerHTML='<b>⚠️ نتیجهٔ تست واقعی ('+esc(action)+')</b> <span dir="ltr" style="font-family:ui-monospace,monospace;white-space:pre-wrap;word-break:break-all;">status='+esc(m.status)+'\nsuccess='+esc(m.json?m.json.success:'(no json)')+'\nmessage='+esc(m.json&&m.json.data?m.json.data.message:'')+'\nraw='+esc(m.raw||'')+'</span><div style="margin-top:4px;opacity:.8;">این درخواست endpoint واقعی را اجرا کرد — state ممکن است تغییر کرده باشد.</div>';
				if(panel.style.display==='none'){panel.style.display='block';}
			});
		}
		if(optT){optT.addEventListener('click',function(){
			var t=document.getElementById('gs-wt-toggle');
			var on=t&&t.dataset&&t.dataset.on==='1';
			optionalTest('sti_gs_watcher_toggle','تست واقعی Toggle (حالت فعلی: '+(on?'روشن':'خاموش')+' → وضعیت معکوس)',{enabled:on?'':'1'});
		});}
		if(optR){optR.addEventListener('click',function(){
			optionalTest('sti_gs_watcher_run','تست واقعی Watcher Run (یک چرخهٔ کامل اسکن/پروفایل/ساخت Session)',{});
		});}
	}catch(topErr){}
	})();
	</script>

	<div class="gi-bento">

		<!-- Worker control hero -->
		<div class="gi-card gi-hero gi-span-5">
			<div class="gi-hero-state">
				<span class="gi-dot gi-dot--<?php echo $stats['enabled'] ? 'running' : 'stopped'; ?> <?php echo $stats['enabled'] ? 'gi-pulse' : ''; ?>" aria-hidden="true"></span>
				<div>
					<div class="gi-hero-state-label" style="font-size:var(--gi-fs3);"><?php echo $stats['enabled'] ? 'Worker روشن' : 'Worker خاموش'; ?></div>
					<div class="gi-hero-state-sub">هر <span class="gi-nums"><?php echo (int) round( $stats['interval'] / 60 ); ?></span> دقیقه · <span class="gi-nums"><?php echo (int) $stats['batch']; ?></span> Session در هر دور</div>
				</div>
			</div>
			<div class="gi-hero-actions">
				<button class="gi-btn <?php echo $stats['enabled'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-w-toggle" data-on="<?php echo $stats['enabled'] ? '1' : '0'; ?>">
					<?php echo $stats['enabled'] ? '⏸ خاموش کردن' : '▶ روشن کردن'; ?>
				</button>
				<button class="gi-btn gi-btn--subtle" id="gs-w-run">اجرای فوری یک دور</button>
				<button class="gi-btn gi-btn--ghost" id="gs-w-reset">آزادسازی موارد گیرکرده</button>
				<button class="gi-btn gi-btn--ghost" id="gs-w-refresh">⟳ به‌روزرسانی</button>
			</div>
		</div>

		<!-- KPIs -->
		<div class="gi-card gi-span-7">
			<div class="gi-card-head">
				<h2 class="gi-card-title">وضعیت صف</h2>
				<span class="gi-card-sub">به‌روزرسانی زنده هر ۲۰ ثانیه</span>
			</div>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--gi-s4);">
				<div class="gi-stat gi-stat--brand"><div class="gi-stat-v gi-nums" id="gs-w-pending"><?php echo (int) $stats['pending']; ?></div><div class="gi-stat-l">در انتظار پردازش</div></div>
				<div class="gi-stat gi-stat--danger"><div class="gi-stat-v gi-nums" id="gs-w-stuck"><?php echo (int) ( $stats['stuck'] + ( $stats['review'] ?? 0 ) ); ?></div><div class="gi-stat-l">نیازمند بازبینی (NEEDS_REVIEW / فایل یافت‌نشده)</div></div>
			</div>
		</div>

		<!-- Today report — ساختار ۵ ردیفی برای JS (tbody tr td:last-child) حفظ شد -->
		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">📊 گزارش امروز</h2></div>
			<table class="gi-table" id="gs-w-today">
				<tbody>
					<tr><td>مرحله جلو رفت</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['advanced'] ?? 0 ); ?></td></tr>
					<tr><td>منتظر پاسخ ربات</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['waiting'] ?? 0 ); ?></td></tr>
					<tr><td>کامل شد</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['completed'] ?? 0 ); ?></td></tr>
					<tr><td>خطا خورد</td><td class="gi-nums" style="text-align:end;font-weight:800;color:var(--gi-danger);"><?php echo (int) ( $today['failed'] ?? 0 ); ?></td></tr>
					<tr><td>تعداد دورها</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) ( $today['ticks'] ?? 0 ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<!-- Chain mode -->
		<div class="gi-card gi-span-8">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🔗 معماری زنجیره (Chain Mode) — v10.8</h2>
			</div>
			<p class="gi-card-sub" style="font-size:var(--gi-fs1);margin-bottom:var(--gi-s4);">
				گلدن اسکن حالا تلگرام را به‌صورت زنجیره‌ای می‌بیند: <code dir="ltr" class="gi-mono">Telegram Node → Node → Node → Asset</code> (مثلاً کانال → دکمه → PartyManagerBot → دکمه → FileechBot → فایل).
				این حالت فقط روی Sessionهای <strong>تازه</strong> اثر می‌گذارد؛ Sessionهای قدیمی و کانال‌های تک‌دکمه‌ای با حالت legacy بدون تغییر کار می‌کنند.
			</p>
			<div class="gi-form-row">
				<div class="gi-field">
					<label for="gs-chain-mode">حالت پردازش</label>
					<select id="gs-chain-mode" style="min-width:280px;">
						<option value="legacy" <?php selected( $stats['chain_mode'] ?? 'auto', 'legacy' ); ?>>legacy — مسیر قدیمی Button → File (دست‌نخورده)</option>
						<option value="auto" <?php selected( $stats['chain_mode'] ?? 'auto', 'auto' ); ?>>auto — Asset → قدیم | DeepLink/Button/Bot → زنجیره (پیشنهادی)</option>
						<option value="chain" <?php selected( $stats['chain_mode'] ?? 'auto', 'chain' ); ?>>chain — همه‌چیز از زنجیره</option>
					</select>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;gap:var(--gi-s3);">
				<button class="gi-btn gi-btn--primary" id="gs-chain-save">ذخیره حالت</button>
				<span id="gs-chain-status" class="gi-card-sub"></span>
			</div>
			<details style="margin-top:var(--gi-s3);">
				<summary style="cursor:pointer;color:var(--gi-text-faint);font-weight:700;font-size:var(--gi-fs1);">توضیح حالت‌ها</summary>
				<ul style="line-height:1.9;color:var(--gi-text-muted);margin:var(--gi-s2) 0 0;padding-inline-start:20px;font-size:var(--gi-fs1);">
					<li><strong>legacy:</strong> دقیقاً رفتار نسخه‌های قبل — Resolver قدیمی، فقط <code dir="ltr">Button → File</code>.</li>
					<li><strong>auto:</strong> فایل (Asset) → همان مسیر قدیم (Matcher با اولویت CODE→NAME→CAPTION→HASH)؛ دکمه/DeepLink/ربات → زنجیره‌ی جدید با <code dir="ltr">messages.startBot</code>.</li>
					<li><strong>chain:</strong> همه‌ی مسیرها از Chain Engine می‌گذرند (برای کانال‌های چندرباتی مثل PartyManagerBot → FileechBot).</li>
				</ul>
			</details>
		</div>

		<?php
		$fails = $stats['failures'] ?? array( 'items' => array(), 'by_reason' => array() );
		if ( ! empty( $fails['by_reason'] ) ) : ?>

		<!-- Failures -->
		<div class="gi-card gi-span-8" style="border-inline-start:4px solid var(--gi-danger);">
			<div class="gi-card-head">
				<h2 class="gi-card-title">🔴 چرا خطا خورد</h2>
				<span class="gi-card-sub">گزارش «۱۵ خطا» به‌تنهایی چیزی نمی‌گوید — اینجا علت‌ها گروه‌بندی شده‌اند</span>
			</div>
			<div class="gi-table-wrap" style="border:none;border-radius:0;">
				<table class="gi-table gi-responsive">
					<thead><tr><th scope="col" style="width:90px;">تعداد</th><th scope="col">مرحله و علت</th></tr></thead>
					<tbody>
						<?php foreach ( $fails['by_reason'] as $reason => $count ) : ?>
							<tr>
								<td data-label="تعداد"><strong class="gi-nums"><?php echo (int) $count; ?></strong></td>
								<td data-label="مرحله و علت"><?php echo esc_html( $reason ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<details style="margin:var(--gi-s3) 0;">
				<summary style="cursor:pointer;font-weight:700;font-size:var(--gi-fs1);min-height:40px;display:inline-flex;align-items:center;">آخرین <?php echo count( $fails['items'] ); ?> مورد به تفکیک</summary>
				<div class="gi-table-wrap" style="margin-top:var(--gi-s2);">
					<table class="gi-table gi-responsive">
						<thead><tr><th scope="col">Session</th><th scope="col">وضعیت</th><th scope="col">مرحله</th><th scope="col">پیام</th><th scope="col">زمان</th></tr></thead>
						<tbody>
							<?php foreach ( $fails['items'] as $f ) : ?>
								<tr>
									<td data-label="Session" class="gi-nums">#<?php echo (int) $f['session_id']; ?></td>
									<td data-label="وضعیت"><code dir="ltr" style="font-size:var(--gi-fs0);"><?php echo esc_html( $f['state'] ); ?></code></td>
									<td data-label="مرحله"><?php echo esc_html( $f['stage'] ); ?></td>
									<td data-label="پیام"><?php echo esc_html( $f['message'] ); ?></td>
									<td data-label="زمان" style="white-space:nowrap;"><?php echo esc_html( $f['at'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		</div>

		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">🩺 سلامت پردازش</h2></div>
			<p class="gi-card-sub" style="font-size:var(--gi-fs1);">اگر تعداد خطا رو به افزایش است، از «گزارش‌ها» علت‌ها را ببینید. خطاهای گذرا توسط Retry/Backoff خودکار مدیریت می‌شوند و فقط موارد نهایی به Review می‌روند.</p>
			<a class="gi-btn gi-btn--subtle" style="text-decoration:none;display:inline-flex;margin-top:var(--gi-s3);" href="<?php echo esc_url( admin_url( 'admin.php?page=sti-golden-scan&gs_view=logs' ) ); ?>">📝 دیدن گزارش‌ها</a>
		</div>

		<?php endif; ?>

		<?php if ( $queue ) :
			$queued_items = STI_GS_Publish_Queue::items( 'queued', 50 );
			$done_items   = STI_GS_Publish_Queue::items( 'published', 10 );
		?>

		<!-- Publish queue -->
		<div class="gi-card gi-card--accent gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">📤 صف انتشار — قلب موفقیت خط تولید</h2>
					<span class="gi-card-sub">این صف مستقل از صفحه‌ی «صف انتشار» قدیمی است (آن صفحه مسیر ربات تلگرام را سرویس می‌دهد و محصولات گلدن اسکن را نمی‌بیند — به همین دلیل همیشه «۰ محصول» نشان می‌دهد). همه‌ی کنترل‌های لازم اینجاست.</span>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;gap:var(--gi-s3);">
				<button class="gi-btn <?php echo $queue['running'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-q-toggle" data-on="<?php echo $queue['running'] ? '1' : '0'; ?>">
					<?php echo $queue['running'] ? '⏸ توقف صف' : '▶ شروع صف'; ?>
				</button>
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">فاصله (دقیقه)</span>
					<input type="number" id="gs-q-interval" min="1" style="width:90px;" value="<?php echo (int) round( $queue['interval_min'] ); ?>">
				</label>
				<button class="gi-btn gi-btn--subtle" id="gs-q-save">💾 ذخیره</button>
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">انتشار فوری</span>
					<input type="number" id="gs-q-count" min="1" max="50" value="5" style="width:80px;">
				</label>
				<button class="gi-btn gi-btn--primary" id="gs-q-now">🚀 انتشار فوری</button>
			</div>
		</div>

		<div class="gi-card gi-span-4">
			<div class="gi-card-head"><h2 class="gi-card-title">وضعیت صف</h2></div>
			<table class="gi-table">
				<tbody>
					<tr><td>وضعیت صف</td><td style="text-align:end;font-weight:700;"><?php echo $queue['running'] ? '<span class="gi-badge gi-badge--success">🟢 روشن</span>' : '<span class="gi-badge">⚪ خاموش</span>'; ?></td></tr>
					<tr><td>در صف</td><td class="gi-nums" style="text-align:end;font-weight:800;"><?php echo (int) $queue['queued']; ?></td></tr>
					<tr><td>بدون زمان‌بندی</td>
						<td style="text-align:end;font-weight:700;">
							<?php
							$un = (int) ( $queue['unscheduled'] ?? 0 );
							echo $un > 0
								? '<span class="gi-badge gi-badge--danger">🔴 ' . $un . ' — هرگز منتشر نمی‌شوند</span>'
								: '<span class="gi-badge gi-badge--success">🟢 ۰</span>';
							?></td></tr>
					<tr><td>نوبت بعدی</td><td style="text-align:end;"><?php echo esc_html( $queue['next_at'] ?: '—' ); ?></td></tr>
					<tr><td>منتشرشده</td><td class="gi-nums" style="text-align:end;font-weight:800;color:var(--gi-success);"><?php echo (int) $queue['published']; ?></td></tr>
					<tr><td>فاصله‌ی انتشار</td><td style="text-align:end;"><span class="gi-nums"><?php echo (int) $queue['interval_min']; ?></span> دقیقه</td></tr>
					<tr><td>اجرای دستی</td>
						<td style="text-align:end;"><button class="gi-btn gi-btn--subtle gi-btn--sm" id="gs-q-run">🚀 نوبت بعدی همین حالا</button>
						<div class="gi-card-sub" style="margin-top:4px;">دکمه‌ی صفحه‌ی «صف انتشار» فقط صف قدیمی را اجرا می‌کند</div></td></tr>
					<tr><td>سقف روزانه</td><td style="text-align:end;"><?php echo $queue['daily_cap'] > 0 ? '<span class="gi-nums">' . (int) $queue['daily_cap'] . '</span> (امروز: <span class="gi-nums">' . (int) $queue['published_today'] . '</span>)' : 'بدون سقف'; ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="gi-card gi-card--flush gi-span-8">
			<div class="gi-card-head" style="padding:var(--gi-s5) var(--gi-s5) var(--gi-s3);">
				<h2 class="gi-card-title">📬 محصولات در صف <span class="gi-nums"><?php echo count( $queued_items ); ?></span></h2>
			</div>
			<?php if ( empty( $queued_items ) ) : ?>
				<div class="gi-empty" style="padding:var(--gi-s6);">
					<div class="gi-empty-ico" aria-hidden="true">📭</div>
					<div class="gi-empty-title">صف انتشار خالی است.</div>
					<div class="gi-empty-sub">محصولاتی که PRODUCT_READY شدند، اینجا صف انتشار می‌گیرند.</div>
				</div>
			<?php else : ?>
				<div class="gi-table-wrap" style="border:none;border-radius:0;">
					<table class="gi-table gi-responsive">
						<thead><tr>
							<th scope="col" style="width:90px;">محصول</th><th scope="col">عنوان</th>
							<th scope="col" style="width:140px;">دسته</th><th scope="col" style="width:100px;">قیمت</th>
							<th scope="col" style="width:160px;">نوبت انتشار</th>
						</tr></thead>
						<tbody>
							<?php foreach ( $queued_items as $it ) : ?>
								<tr>
									<td data-label="محصول" class="gi-nums">#<?php echo (int) $it['product_id']; ?></td>
									<td data-label="عنوان"><a href="<?php echo esc_url( $it['edit_link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
									<td data-label="دسته"><?php echo esc_html( $it['category'] ); ?></td>
									<td data-label="قیمت" class="gi-nums"><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
									<td data-label="نوبت انتشار" style="white-space:nowrap;"><?php echo esc_html( $it['scheduled_at'] ?: '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $done_items ) ) : ?>
				<h3 style="margin:var(--gi-s4) var(--gi-s5) var(--gi-s2);font-size:var(--gi-fs1);">✅ آخرین منتشرشده‌ها</h3>
				<div class="gi-table-wrap" style="border:none;border-radius:0;margin-bottom:var(--gi-s4);">
					<table class="gi-table">
						<tbody>
							<?php foreach ( $done_items as $it ) : ?>
								<tr>
									<td class="gi-nums" style="width:70px;">#<?php echo (int) $it['product_id']; ?></td>
									<td><a href="<?php echo esc_url( $it['view_link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ?: '—' ); ?></a></td>
									<td style="width:130px;"><?php echo esc_html( $it['category'] ); ?></td>
									<td class="gi-nums" style="width:90px;"><?php echo $it['price'] !== '' ? esc_html( number_format_i18n( (float) $it['price'] ) ) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php endif; ?>

		<!-- Rebuild -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🛠 بازسازی محصولات ساخته‌شده</h2>
					<span class="gi-card-sub">اصلاح موتور عنوان فقط روی محصولات <strong>تازه</strong> اثر دارد. محصولاتی که با منطق قبلی ساخته شده‌اند خودشان درست نمی‌شوند — این ابزار عنوان، دسته و قیمت آن‌ها را با منطق فعلی دوباره می‌سازد؛ بدون دانلود دوباره و بدون ساخت محصول تازه.</span>
				</div>
			</div>
			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;gap:var(--gi-s4);">
				<label class="gi-field" style="margin:0;"><span class="gi-field-label" style="display:block;">تعداد</span>
					<input type="number" id="gs-rb-count" min="1" max="50" value="10" style="width:90px;">
				</label>
				<label style="display:flex;gap:8px;align-items:center;min-height:44px;font-weight:600;">
					<input type="checkbox" id="gs-rb-desc"> توضیحات هم بازنویسی شود
				</label>
				<label style="display:flex;gap:8px;align-items:center;min-height:44px;font-weight:600;">
					<input type="checkbox" id="gs-rb-price" checked> قیمت هم اصلاح شود
				</label>
			</div>
			<div class="gi-flex" style="margin-top:var(--gi-s3);">
				<button class="gi-btn gi-btn--subtle" id="gs-rb-preview">👁 پیش‌نمایش تغییرات</button>
				<button class="gi-btn gi-btn--primary" id="gs-rb-apply" disabled>✅ اعمال</button>
			</div>
			<div id="gs-rb-result" class="gi-mt-5"></div>
		</div>

		<?php $wt = class_exists( 'STI_GS_Channel_Watcher' ) ? STI_GS_Channel_Watcher::stats() : null; ?>
		<?php if ( $wt ) : ?>

		<!-- Watcher -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🛰 پایش کانال (خودکارسازی کامل)</h2>
					<span class="gi-card-sub">حلقه‌ی گمشده: اسکن کانال، اجرای پروفایل‌ها و ساخت Session تا امروز دستی بودند. با روشن بودن این، مسیر «کانال → محصول منتشرشده» بدون کلیک کامل می‌شود. هر <span class="gi-nums"><?php echo (int) $wt['interval_min']; ?></span> دقیقه، حداکثر <span class="gi-nums"><?php echo (int) $wt['batch']; ?></span> Session.</span>
				</div>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--gi-s3);">
				<div class="gi-stat gi-stat--<?php echo $wt['enabled'] ? 'success' : 'muted'; ?>"><div class="gi-stat-v" style="font-size:var(--gi-fs2);"><?php echo $wt['enabled'] ? '🟢 روشن' : '⚪ خاموش'; ?></div><div class="gi-stat-l">وضعیت Watcher</div></div>
				<div class="gi-stat gi-stat--info"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $wt['ready'] ); ?></div><div class="gi-stat-l">آماده‌ی ساخت Session</div></div>
				<div class="gi-stat gi-stat--<?php echo $wt['backlog'] >= $wt['backlog_limit'] ? 'danger' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $wt['backlog']; ?> / <?php echo (int) $wt['backlog_limit']; ?></div><div class="gi-stat-l">صف ناتمام (فشار معکوس)</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo (int) $wt['created_today']; ?><?php echo $wt['daily_cap'] ? ' / ' . (int) $wt['daily_cap'] : ''; ?></div><div class="gi-stat-l">ساخته‌شده امروز</div></div>
				<div class="gi-stat gi-stat--<?php echo $wt['no_category'] ? 'warning' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo number_format_i18n( $wt['no_category'] ); ?></div><div class="gi-stat-l">بدون دسته (نادیده)</div></div>
			</div>

			<?php if ( $wt['no_category'] > 0 ) : ?>
				<div class="gi-notice gi-notice--warning" style="margin:var(--gi-s4) 0;">
					⚠ <span class="gi-nums"><?php echo number_format_i18n( $wt['no_category'] ); ?></span> Candidate به پروفایلی تعلق دارند که <strong>دسته‌ی پیش‌فرض ندارد</strong>. Watcher عمداً از آن‌ها Session نمی‌سازد — وگرنه محصول بی‌دسته و بی‌قیمت تولید می‌شود که بعداً باید بازسازی شود. در تب «پروفایل‌ها» برایشان دسته تعیین کنید.
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $wt['note'] ) ) : ?>
				<p class="gi-card-sub" style="font-size:var(--gi-fs1);">آخرین اجرا: <?php echo esc_html( $wt['note'] ); ?></p>
			<?php endif; ?>

			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button class="gi-btn <?php echo $wt['enabled'] ? 'gi-btn--danger' : 'gi-btn--success'; ?>" id="gs-wt-toggle" data-on="<?php echo $wt['enabled'] ? '1' : '0'; ?>">
					<?php echo $wt['enabled'] ? '⏸ توقف پایش' : '▶ شروع پایش'; ?>
				</button>
				<button class="gi-btn gi-btn--subtle" id="gs-wt-run">🔄 اجرای فوری یک چرخه</button>
			</div>
		</div>

		<?php endif; ?>

		<?php $rec = class_exists( 'STI_GS_Recovery' ) ? STI_GS_Recovery::stats() : null; ?>
		<?php if ( $rec ) : ?>

		<!-- Recovery -->
		<div class="gi-card gi-span-12">
			<div class="gi-card-head">
				<div>
					<h2 class="gi-card-title">🩹 خودترمیمی زیرساخت</h2>
					<span class="gi-card-sub">این لایه فقط قفل‌های رهاشده را آزاد می‌کند. تصمیم درباره‌ی مسیر زنجیره با Chain Engine است.</span>
				</div>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--gi-s3);">
				<div class="gi-stat gi-stat--<?php echo $rec['stale_locks'] ? 'warning' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['stale_locks']; ?></div><div class="gi-stat-l">قفل رهاشده (Chain)</div></div>
				<div class="gi-stat gi-stat--<?php echo $rec['orphans'] ? 'danger' : 'success'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['orphans']; ?></div><div class="gi-stat-l">یتیم (دانلود/مدیا)</div></div>
				<div class="gi-stat gi-stat--success"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['recovered_today']; ?></div><div class="gi-stat-l">ترمیم‌شده امروز</div></div>
				<div class="gi-stat gi-stat--<?php echo $rec['dead'] ? 'warning' : 'muted'; ?>"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['dead']; ?></div><div class="gi-stat-l">صف مرده</div></div>
				<div class="gi-stat"><div class="gi-stat-v gi-nums"><?php echo (int) $rec['retry_queue']; ?></div><div class="gi-stat-l">در صف تلاش دوباره</div></div>
			</div>

			<p class="gi-card-sub" style="font-size:var(--gi-fs1);margin-top:var(--gi-s4);">حالت مهلت‌گذاری:
				<?php if ( 'signal' === $rec['deadline_mode'] ) : ?>
					<span class="gi-badge gi-badge--success">🟢 سیگنال</span> — تماس قفل‌شده با خطای کنترل‌شده متوقف می‌شود و قفل بلافاصله آزاد می‌گردد.
				<?php else : ?>
					<span class="gi-badge gi-badge--warning">🟡 محدودیت زمان</span> — این هاست <code dir="ltr">pcntl</code> ندارد؛ درخواست کشته می‌شود و قفل تا انقضای TTL می‌ماند. Watchdog همان‌ها را آزاد می‌کند.
				<?php endif; ?>
			</p>

			<div class="gi-flex" style="align-items:center;flex-wrap:wrap;margin-top:var(--gi-s3);">
				<button class="gi-btn gi-btn--subtle" id="gs-wd-run">🩹 اجرای فوری Watchdog</button>
				<?php if ( $rec['dead'] > 0 ) : ?>
					<button class="gi-btn gi-btn--ghost" id="gs-wd-revive">↩ بازگرداندن صف مرده</button>
				<?php endif; ?>
			</div>

			<?php $dl = STI_GS_Recovery::dead_letters( 10 ); if ( $dl ) : ?>
				<details style="margin:var(--gi-s4) 0 0;">
					<summary style="cursor:pointer;font-weight:700;font-size:var(--gi-fs1);min-height:40px;display:inline-flex;align-items:center;">صف مرده (<?php echo count( $dl ); ?> مورد اخیر)</summary>
					<div class="gi-table-wrap" style="margin-top:var(--gi-s2);">
						<table class="gi-table gi-responsive">
							<thead><tr><th scope="col">Session</th><th scope="col">کد فایل</th><th scope="col">مرحله</th><th scope="col">دلیل</th></tr></thead>
							<tbody>
								<?php foreach ( $dl as $d ) : ?>
									<tr>
										<td data-label="Session" class="gi-nums">#<?php echo (int) $d['id']; ?></td>
										<td data-label="کد فایل" dir="ltr"><code style="font-size:var(--gi-fs0);"><?php echo esc_html( $d['file_code'] ?: '—' ); ?></code></td>
										<td data-label="مرحله"><?php echo esc_html( $d['stage'] ); ?></td>
										<td data-label="دلیل" style="font-size:var(--gi-fs0);"><?php echo esc_html( $d['error_reason'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</details>
			<?php endif; ?>

			<?php if ( class_exists( 'STI_GS_Flags' ) ) : ?>
				<h3 style="margin:var(--gi-s5) 0 var(--gi-s3);font-size:var(--gi-fs1);">کلیدهای قابلیت</h3>
				<div class="gi-table-wrap">
					<table class="gi-table">
						<tbody>
						<?php foreach ( STI_GS_Flags::definitions() as $key => $def ) : ?>
							<tr>
								<td style="width:60px;"><input type="checkbox" class="gs-flag" data-flag="<?php echo esc_attr( $key ); ?>" <?php checked( STI_GS_Flags::on( $key ) ); ?> style="width:22px;height:22px;"></td>
								<td><strong><?php echo esc_html( $def['label'] ); ?></strong><br>
									<span class="gi-card-sub"><?php echo esc_html( $def['note'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php endif; ?>

		<div class="gi-notice gi-notice--info gi-span-12">
			<strong>ترتیب درست روشن کردن:</strong> اول Worker را روشن کنید و بگذارید چند دور بچرخد و محصولات ساخته شوند. وقتی از کیفیت عنوان، دسته و قیمت مطمئن شدید، آن‌وقت صف انتشار را روشن کنید. تا صف خاموش است، هیچ محصولی منتشر نمی‌شود و همه به‌صورت پیش‌نویس می‌مانند.
		</div>

	</div><!-- /gi-bento -->

	<script>
	(function(){
		function post(action, extra){
			var body = new URLSearchParams(Object.assign({ action: action, nonce: STI.nonce }, extra || {}));
			return fetch(STI.ajaxUrl || ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.text(); })
				.then(function(t){
					try { return JSON.parse(t); }
					catch(e){ throw new Error('پاسخ نامعتبر از سرور:\n' + t.slice(0,300)); }
				});
		}
		function paint(d){
			if (!d) return;
			var pe = document.getElementById('gs-w-pending');
			var st = document.getElementById('gs-w-stuck');
			if (pe && window.GI) { window.GI.setNumber(pe, d.pending); } else if (pe) { pe.textContent = d.pending; }
			if (st && window.GI) { window.GI.setNumber(st, d.stuck + (d.review || 0)); } else if (st) { st.textContent = d.stuck + (d.review || 0); }
			var t = d.today || {};
			var rows = document.querySelectorAll('#gs-w-today tbody tr td:last-child');
			[t.advanced||0, t.waiting||0, t.completed||0, t.failed||0, t.ticks||0]
				.forEach(function(v,i){ if (rows[i]) rows[i].textContent = v; });
			var btn = document.getElementById('gs-w-toggle');
			btn.dataset.on = d.enabled ? '1' : '0';
			btn.textContent = d.enabled ? '⏸ خاموش کردن' : '▶ روشن کردن';
		}
		function refresh(){ if (!document.hidden) { post('sti_gs_worker_stats').then(function(r){ if (r.success) paint(r.data); }); } }

		document.getElementById('gs-w-toggle').addEventListener('click', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_worker_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) { paint(r.data); location.reload(); } });
		});
		document.getElementById('gs-w-run').addEventListener('click', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال اجرا...';
			post('sti_gs_worker_run_now').then(function(r){
				b.disabled = false; b.textContent = 'اجرای فوری یک دور';
				if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
				paint(r.data);
			}).catch(function(e){ b.disabled=false; b.textContent='اجرای فوری یک دور'; alert(e.message); });
		});
		document.getElementById('gs-w-reset').addEventListener('click', function(){
			if (!confirm('شمارنده‌ی تلاش همه‌ی موارد گیرکرده صفر شود؟')) return;
			post('sti_gs_worker_reset').then(function(r){
				if (r.success) { alert(r.data.message); paint(r.data); }
			});
		});
		var qRun = document.getElementById('gs-q-run');
		if (qRun) {
			qRun.addEventListener('click', function(){
				var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
				post('sti_gs_queue_run_now').then(function(r){
					b.disabled = false; b.textContent = '🚀 نوبت بعدی همین حالا';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					alert('منتشرشده تا این لحظه: ' + (r.data.published || 0) + '\nدر صف: ' + (r.data.queued || 0));
					location.reload();
				}).catch(function(e){ b.disabled=false; b.textContent='🚀 نوبت بعدی همین حالا'; alert(e.message); });
			});
		}

		function bind(id, fn){ var el = document.getElementById(id); if (el) el.addEventListener('click', fn); }

		bind('gs-q-toggle', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_queue_toggle', { enabled: on ? '' : '1' })
				.then(function(r){ if (r.success) location.reload(); });
		});

		bind('gs-q-save', function(){
			var m = document.getElementById('gs-q-interval').value;
			post('sti_gs_queue_interval', { minutes: m }).then(function(r){
				alert(r.success ? 'فاصله ذخیره شد.' : ((r.data && r.data.message) || 'خطا'));
				if (r.success) location.reload();
			});
		});

		bind('gs-chain-save', function(){
			var m = document.getElementById('gs-chain-mode').value;
			var st = document.getElementById('gs-chain-status');
			st.textContent = 'در حال ذخیره...';
			post('sti_gs_worker_chain_mode', { mode: m }).then(function(r){
				if (r.success) { st.textContent = '✓ ذخیره شد (حالت: ' + r.data.chain_mode + ').'; }
				else { st.textContent = 'خطا: ' + ((r.data && r.data.message) || 'نامشخص'); }
			}).catch(function(e){ st.textContent = 'خطا: ' + e.message; });
		});

		bind('gs-q-now', function(){
			var n = document.getElementById('gs-q-count').value;
			if (!confirm(n + ' محصول همین حالا منتشر شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال انتشار...';
			post('sti_gs_queue_publish_now', { count: n }).then(function(r){
				b.disabled = false; b.textContent = '🚀 انتشار فوری';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='🚀 انتشار فوری'; alert(e.message); });
		});

		bind('gs-rb-preview', function(){
			var b = this; b.disabled = true; b.textContent = 'در حال محاسبه...';
			post('sti_gs_rebuild_preview', { count: document.getElementById('gs-rb-count').value })
				.then(function(r){
					b.disabled = false; b.textContent = '👁 پیش‌نمایش تغییرات';
					if (!r.success) { alert((r.data && r.data.message) || 'خطا'); return; }
					var rows = r.data.rows || [];
					var html = '<div class="gi-table-wrap"><table class="gi-table gi-responsive"><thead><tr>' +
						'<th scope="col">محصول</th><th scope="col">عنوان فعلی</th><th scope="col">عنوان تازه</th>' +
						'<th scope="col">دسته</th><th scope="col">قیمت</th></tr></thead><tbody>';
					rows.forEach(function(x){
						var changed = x.after && x.after !== x.before;
						html += '<tr' + (changed ? ' class="gi-row-ok"' : '') + '>' +
							'<td data-label="محصول" class="gi-nums">#' + x.product_id + '</td>' +
							'<td data-label="عنوان فعلی" class="gi-faint">' + (x.before || '—') + '</td>' +
							'<td data-label="عنوان تازه"><strong>' + (x.after || '(بدون تغییر)') + '</strong></td>' +
							'<td data-label="دسته">' + (x.category || '—') + '</td>' +
							'<td data-label="قیمت" class="gi-nums">' + (x.price || '—') + '</td></tr>';
					});
					html += '</tbody></table></div>';
					document.getElementById('gs-rb-result').innerHTML = html;
					document.getElementById('gs-rb-apply').disabled = rows.length === 0;
				}).catch(function(e){ b.disabled=false; b.textContent='👁 پیش‌نمایش تغییرات'; alert(e.message); });
		});

		bind('gs-rb-apply', function(){
			if (!confirm('عنوان و دسته و قیمت این محصولات بازنویسی شود؟')) return;
			var b = this; b.disabled = true; b.textContent = 'در حال اعمال...';
			post('sti_gs_rebuild_apply', {
				count: document.getElementById('gs-rb-count').value,
				description: document.getElementById('gs-rb-desc').checked ? '1' : '',
				price: document.getElementById('gs-rb-price').checked ? '1' : ''
			}).then(function(r){
				b.textContent = '✅ اعمال';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='✅ اعمال'; alert(e.message); });
		});

		document.getElementById('gs-w-refresh').addEventListener('click', refresh);
		setInterval(refresh, 20000);
	})();
	</script>

	<script>
	(function(){
		function post(a, x){
			var b=new URLSearchParams(Object.assign({action:a,nonce:STI.nonce}, x||{}));
			return fetch(STI.ajaxUrl||ajaxurl,{method:'POST',credentials:'same-origin',body:b})
				.then(function(r){return r.text();})
				.then(function(t){try{return JSON.parse(t);}catch(e){throw new Error(t.slice(0,300));}});
		}
		function bind(id,fn){var e=document.getElementById(id); if(e) e.addEventListener('click',fn);}


		bind('gs-wt-toggle', function(){
			var on = this.dataset.on === '1';
			post('sti_gs_watcher_toggle', { enabled: on ? '' : '1' })
				.then(function(){ location.reload(); });
		});
		bind('gs-wt-run', function(){
			var b=this; b.disabled=true; b.textContent='در حال اجرا...';
			post('sti_gs_watcher_run').then(function(r){
				b.disabled=false; b.textContent='🔄 اجرای فوری یک چرخه';
				alert((r.data && r.data.message) || 'انجام شد');
				location.reload();
			}).catch(function(e){ b.disabled=false; b.textContent='🔄 اجرای فوری یک چرخه'; alert(e.message); });
		});
		bind('gs-wd-run', function(){
			var b=this; b.disabled=true; b.textContent='در حال بررسی...';
			post('sti_gs_watchdog_run').then(function(){location.reload();})
				.catch(function(e){b.disabled=false;b.textContent='🩹 اجرای فوری Watchdog';alert(e.message);});
		});
		bind('gs-wd-revive', function(){
			if(!confirm('همه‌ی موارد صف مرده به چرخه برگردند؟'))return;
			post('sti_gs_revive_dead').then(function(r){
				alert((r.data&&r.data.message)||'انجام شد'); location.reload();});
		});
		document.querySelectorAll('.gs-flag').forEach(function(cb){
			cb.addEventListener('change', function(){
				post('sti_gs_flag_toggle',{flag:cb.dataset.flag,enabled:cb.checked?'1':''})
					.then(function(r){ if(!r.success){alert('خطا'); cb.checked=!cb.checked;} });
			});
		});
	})();
	</script>

</div>
