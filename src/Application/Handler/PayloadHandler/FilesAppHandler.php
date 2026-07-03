<?php

declare(strict_types=1);

namespace Semitexa\Files\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Files\Application\Payload\Request\FilesAppPayload;

/**
 * Renders the Files dialog body: a self-contained file browser (breadcrumb +
 * folder/file list + preview) that talks to the local bridge (/list, /read,
 * /open) client-side. Standalone HTML because the Focus zone embeds it as an
 * iframe; reads `?path=` to open at a specific folder.
 */
#[AsPayloadHandler(payload: FilesAppPayload::class, resource: ResourceResponse::class)]
final class FilesAppHandler implements TypedHandlerInterface
{
    public function handle(FilesAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Files</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:#161622;color:#dbe7ff;display:flex;flex-direction:column;font-size:14px}
  .bar{display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid rgba(148,163,184,.18);min-height:44px}
  .crumb{flex:1;display:flex;align-items:center;gap:2px;flex-wrap:wrap;font-size:12.5px;overflow:hidden}
  .crumb .seg{color:#a8b4cc;cursor:pointer;padding:2px 5px;border-radius:6px;white-space:nowrap}
  .crumb .seg:hover{background:rgba(148,163,184,.14);color:#dbe7ff}
  .crumb .seg.cur{color:#dbe7ff;font-weight:600;cursor:default}
  .crumb .sep{color:#5d6b86}
  .act{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:8px;border:1px solid rgba(55,183,255,.4);
       background:rgba(55,183,255,.12);color:#37b7ff;font:600 12.5px 'IBM Plex Sans';cursor:pointer;white-space:nowrap}
  .act:hover{background:rgba(55,183,255,.22)}
  .main{flex:1;display:flex;min-height:0}
  .list{width:46%;min-width:220px;overflow:auto;border-right:1px solid rgba(148,163,184,.14);padding:6px}
  .row{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:8px;cursor:pointer;color:#cdd9ee}
  .row:hover{background:rgba(148,163,184,.12)}
  .row.sel{background:rgba(55,183,255,.16);color:#eaf2ff}
  .row .ico{flex:0 0 auto;display:flex;color:#8d9bb8}
  .row.dir .ico{color:#eab308}
  .row .nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .row .sz{font-family:'IBM Plex Mono',monospace;font-size:11px;color:#5d6b86}
  .preview{flex:1;overflow:auto;padding:0}
  .preview pre{margin:0;padding:16px;font-family:'IBM Plex Mono',monospace;font-size:12.5px;line-height:1.6;color:#cdd9ee;white-space:pre-wrap;word-break:break-word}
  .hint{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:8px;color:#5d6b86;text-align:center;padding:24px;font-size:13px}
  .err{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:10px;color:#8d9bb8;text-align:center;padding:30px}
  .err b{color:#dbe7ff} .err code{font-family:'IBM Plex Mono',monospace;background:rgba(148,163,184,.14);padding:2px 7px;border-radius:6px;color:#a8b4cc;font-size:12px}
  .empty{color:#5d6b86;padding:14px;font-size:13px}
</style></head>
<body>
  <div class="bar"><div class="crumb" id="crumb"></div><button class="act" id="edit" title="Open this folder in a code editor">Open in editor</button></div>
  <div class="main">
    <div class="list" id="list"></div>
    <div class="preview" id="preview"><div class="hint">Pick a file to preview it here.</div></div>
  </div>
<script>
(function(){
  var BRIDGE='http://127.0.0.1:8777';
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
  var FOLDER='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';
  var FILE='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/></svg>';
  var list=document.getElementById('list'), preview=document.getElementById('preview'), crumb=document.getElementById('crumb');
  var cur='', root='';
  function fmtSize(n){ if(n<1024)return n+' B'; if(n<1048576)return (n/1024).toFixed(0)+' KB'; return (n/1048576).toFixed(1)+' MB'; }
  function showErr(){
    list.innerHTML='';
    preview.innerHTML='<div class="err">'+FOLDER+'<div><b>Can’t reach the desktop bridge.</b></div><div>Run <code>bridged.py</code> on the machine with your files, then reopen.</div></div>';
    crumb.innerHTML='<span class="seg cur">Files</span>';
  }
  function renderCrumb(d){
    var rel = d.path === d.root ? '' : d.path.slice(d.root.length).replace(/^\//,'');
    var parts = rel === '' ? [] : rel.split('/');
    var html='<span class="seg" data-path="'+esc(d.root)+'">'+esc(d.root.split('/').pop()||'/')+'</span>';
    var acc=d.root;
    parts.forEach(function(p,i){ acc+='/'+p; var last=i===parts.length-1;
      html+='<span class="sep">/</span><span class="seg'+(last?' cur':'')+'" data-path="'+esc(acc)+'">'+esc(p)+'</span>'; });
    crumb.innerHTML=html;
  }
  function renderList(d){
    var html='';
    if(d.parent){ html+='<div class="row dir" data-path="'+esc(d.parent)+'" data-type="dir"><span class="ico">'+FOLDER+'</span><span class="nm">..</span></div>'; }
    if(!d.entries.length && !d.parent){ html+='<div class="empty">Empty folder.</div>'; }
    d.entries.forEach(function(e){
      var fp = d.path.replace(/\/$/,'')+'/'+e.name;
      html+='<div class="row '+(e.type==='dir'?'dir':'file')+'" data-path="'+esc(fp)+'" data-type="'+e.type+'">'
        +'<span class="ico">'+(e.type==='dir'?FOLDER:FILE)+'</span><span class="nm">'+esc(e.name)+'</span>'
        +(e.type==='file'?'<span class="sz">'+fmtSize(e.size)+'</span>':'')+'</div>';
    });
    if(!html) html='<div class="empty">Empty folder.</div>';
    list.innerHTML=html;
  }
  function load(path){
    fetch(BRIDGE+'/list?path='+encodeURIComponent(path||'')).then(function(r){ if(!r.ok) throw 0; return r.json(); })
      .then(function(d){ cur=d.path; root=d.root; renderCrumb(d); renderList(d); })
      .catch(function(){ showErr(); });
  }
  function openFile(path, name){
    Array.prototype.forEach.call(list.querySelectorAll('.row'),function(r){ r.classList.toggle('sel', r.dataset.path===path); });
    preview.innerHTML='<div class="hint">Loading…</div>';
    fetch(BRIDGE+'/read?path='+encodeURIComponent(path)).then(function(r){ return r.json(); })
      .then(function(d){
        if(d.binary || d.content==null){ preview.innerHTML='<div class="hint">'+esc(name)+' can’t be previewed (binary or unreadable).</div>'; return; }
        preview.innerHTML='<pre>'+esc(d.content)+(d.truncated?'\n\n… (truncated)':'')+'</pre>';
      }).catch(function(){ preview.innerHTML='<div class="hint">Couldn’t read this file.</div>'; });
  }
  list.addEventListener('click',function(e){
    var row=e.target.closest('.row'); if(!row) return;
    if(row.dataset.type==='dir') load(row.dataset.path);
    else openFile(row.dataset.path, row.querySelector('.nm').textContent);
  });
  crumb.addEventListener('click',function(e){ var s=e.target.closest('.seg'); if(s && s.dataset.path && !s.classList.contains('cur')) load(s.dataset.path); });
  document.getElementById('edit').addEventListener('click',function(){
    if(!cur) return;
    fetch(BRIDGE+'/open?path='+encodeURIComponent(cur)+'&with=code').catch(function(){});
  });
  var start=new URLSearchParams(location.search).get('path')||'';
  load(start);
})();
</script>
</body></html>
HTML;

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
