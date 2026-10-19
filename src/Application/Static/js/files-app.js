(function(){
  // Follow the OS theme: the pref lives server-side; 'auto' resolves with the
  // SAME rule as the shell (prefers-color-scheme, else dark 19:00–07:00).
  function applyMode(mode){
    var eff=(mode==='light'||mode==='dark')?mode:(function(){
      try{ if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'; }catch(e){}
      var h=new Date().getHours(); return (h>=19||h<7)?'dark':'light';
    })();
    var el=document.documentElement;
    if(el.getAttribute('data-mode')!==eff){ el.setAttribute('data-mode',eff); el.style.colorScheme=eff; }
  }
  function syncMode(){
    fetch('/os/preferences',{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();}).then(function(d){ applyMode((d&&d.theme_mode)||'auto'); })
      .catch(function(){});
  }
  syncMode(); window.addEventListener('focus', syncMode); setInterval(syncMode, 15000);

  var BRIDGE='http://127.0.0.1:8777';
  // The bridge gates every side-effecting call behind a shared token that only
  // loopback pages (this app) can obtain from /token. Fetched once, attached
  // as a header on every bridge call via bridgeFetch.
  var BRIDGE_TOKEN=null;
  function bridgeAuth(){
    if(BRIDGE_TOKEN!==null) return Promise.resolve(BRIDGE_TOKEN);
    return fetch(BRIDGE+'/token').then(function(r){
      // A 403 here carries a parseable JSON error body — r.ok is the only
      // signal that separates it from a real token grant.
      if(!r.ok) throw new Error('token refused');
      return r.json();
    }).then(function(d){
      if(!d || typeof d.token!=='string' || !d.token) throw new Error('no token');
      BRIDGE_TOKEN=d.token;
      return BRIDGE_TOKEN;
    })
      // Don't cache a failure (bridge not up yet / old bridge without
      // /token / refused) — leave BRIDGE_TOKEN null so the next call retries.
      .catch(function(){ return ''; });
  }
  function bridgeFetch(path, opts){
    return bridgeAuth().then(function(t){
      opts=opts||{}; var h=opts.headers||{}; if(t){ h['X-Bridge-Token']=t; } opts.headers=h;
      return fetch(BRIDGE+path, opts);
    });
  }
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
  var FOLDER='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>';
  var FILE='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/></svg>';
  var PROJ_ICO='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="26" height="26"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
  var main=document.getElementById('main'), crumb=document.getElementById('crumb');
  // proj = the open project: {title, root}. cur/selFile only mean anything in
  // the explorer; lastListing feeds "back to files" after a preview.
  var proj=null, cur='', selFile=null, statusTimer=null, userFiles='', lastListing=null;
  function fmtSize(n){ if(n<1024)return n+' B'; if(n<1048576)return (n/1024).toFixed(0)+' KB'; return (n/1048576).toFixed(1)+' MB'; }
  // Success is teal, failure is RED — one green line for both trained the user
  // to read "Operation failed" as success (the create-silently-does-nothing bug).
  function toast(msg, isError){
    var el=document.getElementById('status');
    el.textContent=msg; el.classList.add('on'); el.classList.toggle('err', !!isError);
    clearTimeout(statusTimer); statusTimer=setTimeout(function(){ el.classList.remove('on'); },isError?7000:4500);
  }
  // ---------- projects start screen (the Weave, not the filesystem) ----------
  function countsLine(c){
    var order=['folder','file','note','task','person','topic','event','app'];
    var label={folder:'folders',file:'files',note:'notes',task:'tasks',person:'people',topic:'topics',event:'events',app:'apps'};
    var parts=[];
    order.forEach(function(k){ if(c && c[k]) parts.push(c[k]+' '+(c[k]===1?label[k].replace(/s$/,'').replace('people','person'):label[k])); });
    Object.keys(c||{}).forEach(function(k){ if(order.indexOf(k)===-1) parts.push(c[k]+' '+k); });
    return parts.slice(0,3).join(' · ');
  }
  function showProjects(){
    loadSeq++; proj=null; cur=''; selFile=null;
    crumb.innerHTML='<span class="seg cur">Projects</span>';
    main.innerHTML='<div class="projects"><div class="hint" style="grid-column:1/-1">Loading your projects…</div></div>';
    fetch('/os/weave/projects',{headers:{'Accept':'application/json'}})
      .then(function(r){ if(!r.ok) throw 0; return r.json(); })
      .then(function(d){
        userFiles=(d&&d.user_files)||'';
        var ps=(d&&d.projects)||[];
        if(!ps.length){
          main.innerHTML='<div class="err">'+PROJ_ICO+'<div><b>No projects in your world yet.</b></div>'
            +'<div>Tell the assistant what you’re working on — e.g. “remember that I’m working on Aurora” —<br>and the project will appear here.</div></div>';
          return;
        }
        var SEARCH='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>';
        function cardsHTML(q){
          q=(q||'').toLowerCase();
          var html='', shown=0;
          ps.forEach(function(p,i){
            if(q && p.title.toLowerCase().indexOf(q)===-1 && (p.path||'').toLowerCase().indexOf(q)===-1) return;
            shown++;
            var meta=countsLine(p.counts);
            var pathLine=p.path?esc(p.path):'no folder attached yet';
            html+='<div class="pcard'+(p.path?'':' nopath')+'" data-i="'+i+'">'
              +'<div class="top"><span class="pico">'+PROJ_ICO+'</span><span class="pname" title="'+esc(p.title)+'">'+esc(p.title)+'</span></div>'
              +'<div class="pmeta">'+esc(meta)+'</div>'
              +'<div class="ppath" title="'+esc(p.path||'')+'">'+pathLine+'</div>'
              +'</div>';
          });
          if(!shown) html='<div class="pnone">No project matches “'+esc(q)+'”.</div>';
          return html;
        }
        main.innerHTML='<div class="projects">'
          +'<div class="psearch">'+SEARCH+'<input id="psearch" type="text" placeholder="Search projects…" autocomplete="off"></div>'
          +'<div class="phead">Your projects</div>'
          +'<div style="display:contents" id="pcards">'+cardsHTML('')+'</div>'
          +'</div>';
        var grid=main.querySelector('.projects'), input=document.getElementById('psearch');
        input.addEventListener('input',function(){ document.getElementById('pcards').innerHTML=cardsHTML(input.value.trim()); });
        // Type-to-filter (the Focus-launcher habit): any printable key lands in
        // the search box, Escape clears it.
        input.focus();
        document.addEventListener('keydown',function(e){
          if(!document.getElementById('psearch')) return; // left the projects view
          if(e.key==='Escape'){ input.value=''; document.getElementById('pcards').innerHTML=cardsHTML(''); input.focus(); return; }
          if(document.activeElement!==input && e.key.length===1 && !e.ctrlKey && !e.metaKey && !e.altKey) input.focus();
        });
        grid.addEventListener('click',function(e){
          var card=e.target.closest('.pcard'); if(!card) return;
          var p=ps[+card.dataset.i]; if(!p) return;
          if(p.path) openProject(p.title, p.path);
          else showNoFolder(p);
        });
      })
      .catch(function(){
        main.innerHTML='<div class="err">'+PROJ_ICO+'<div><b>Couldn’t load your projects.</b></div><div>The OS is unreachable — try reopening Files.</div></div>';
      });
  }
  // A project that exists in the world but has no folder yet — offer to create
  // its folder right here (mkdirp on the bridge + attach in the Weave), or
  // point at the attach flows for an EXISTING folder.
  function showNoFolder(p){
    loadSeq++; proj=null;
    crumb.innerHTML='<span class="seg" id="home">Projects</span><span class="sep">/</span><span class="seg cur">'+esc(p.title)+'</span>';
    main.innerHTML='<div class="err">'+PROJ_ICO+'<div><b>“'+esc(p.title)+'” has no folder attached yet.</b></div>'
      +'<div><button class="act" id="mkproj">Create a folder for this project…</button></div>'
      +'<div>Or attach an existing one: right-click it in another project → “＋ My world”,<br>or ask the assistant: “attach ~/path/to/folder to '+esc(p.title)+'”.</div></div>';
    document.getElementById('mkproj').addEventListener('click', function(){
      // Suggested home: SEMITEXA_USER_FILES if designated, else <bridge root>/Projects.
      var base=userFiles?Promise.resolve(userFiles.replace(/\/$/,'')):(
        bridgeFetch('/list?path=').then(function(r){ return r.json(); }).then(function(d){ return d.root; })
      );
      Promise.resolve(base).then(function(b){
        askName('Folder for “'+p.title+'”', b+'/Projects/'+p.title, function(full){
          bridgeFetch('/fs/mkdirp',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({path:full})})
            .then(function(r){ return r.json(); })
            .then(function(d){
              if(!d.ok){ toast(d.error==='denied'?'Denied: outside the bridge root (check SEMITEXA_FILES_ROOT).':'Couldn’t create: '+(d.error||'?'), true); return; }
              // Hang the new folder off the project in the Weave, then open it.
              fetch('/os/weave/attach',{method:'POST',headers:{'Content-Type':'application/json'},
                body:JSON.stringify({path:d.path,kind:'folder',connect_to:p.title})})
                .then(function(r){ return r.json(); })
                .then(function(a){
                  if(a.ok){ try{ (window.parent||window).postMessage({type:'os:weave-changed'},'*'); }catch(e){} }
                  toast(a.ok?'Folder created and attached.':'Folder created; attach failed: '+(a.error||'?'), !a.ok);
                  openProject(p.title, d.path);
                })
                .catch(function(){ toast('Folder created; couldn’t attach it to the project.', true); openProject(p.title, d.path); });
            })
            .catch(function(){ toast('Bridge is not running — start bridged.py on this machine.', true); });
        });
      }).catch(function(){ toast('Bridge is not running — start bridged.py on this machine.', true); });
    });
  }

  // ---------- explorer (classic two-pane view, CONFINED to the project root) --
  // Left pane = folder navigation, right pane = the current folder's FILES
  // (clicking a file swaps the right pane to a preview with a back header).
  // The splitter between them drags; its position persists across sessions.
  function within(path){ return path===proj.root || (path+'/').indexOf(proj.root+'/')===0; }
  function openProject(title, root){
    proj={title:title, root:root.replace(/\/$/,'')||root};
    main.innerHTML='<div class="nav" id="nav"></div><div class="split" id="split"></div><div class="files" id="files"></div>';
    try{ var w=parseInt(localStorage.getItem('semitexa_files_split')||'',10); if(w>=160) document.getElementById('nav').style.width=w+'px'; }catch(e){}
    wirePanes();
    bindSplit();
    load(proj.root);
  }
  function showErr(){
    var nav=document.getElementById('nav'), files=document.getElementById('files');
    if(nav) nav.innerHTML='';
    if(files) files.innerHTML='<div class="err">'+FOLDER+'<div><b>Can’t reach the desktop bridge.</b></div><div>Run <code>bridged.py</code> on the machine with your files, then reopen.</div></div>';
  }
  function renderCrumb(){
    var rel = cur === proj.root ? '' : cur.slice(proj.root.length).replace(/^\//,'');
    var parts = rel === '' ? [] : rel.split('/');
    var html='<span class="seg" id="home">Projects</span><span class="sep">/</span>'
      +'<span class="seg'+(parts.length?'':' cur')+'" data-path="'+esc(proj.root)+'">'+esc(proj.title)+'</span>';
    var acc=proj.root;
    parts.forEach(function(p,i){ acc+='/'+p; var last=i===parts.length-1;
      html+='<span class="sep">/</span><span class="seg'+(last?' cur':'')+'" data-path="'+esc(acc)+'">'+esc(p)+'</span>'; });
    crumb.innerHTML=html;
  }
  function renderNav(d){
    var nav=document.getElementById('nav');
    var html='<div class="paneh">Folders</div>';
    // ".." stops at the PROJECT root — the project is the world's edge here,
    // not the host filesystem (strictly projects, no escape hatch).
    if(d.parent && cur!==proj.root && within(d.parent)){
      html+='<div class="row dir" data-path="'+esc(d.parent)+'" data-type="dir"><span class="ico">'+FOLDER+'</span><span class="nm">..</span></div>';
    }
    var dirs=0;
    d.entries.forEach(function(e){
      if(e.type!=='dir') return;
      dirs++;
      var fp = d.path.replace(/\/$/,'')+'/'+e.name;
      html+='<div class="row dir" data-path="'+esc(fp)+'" data-type="dir"><span class="ico">'+FOLDER+'</span><span class="nm">'+esc(e.name)+'</span></div>';
    });
    if(!dirs && cur===proj.root) html+='<div class="empty">No subfolders.</div>';
    nav.innerHTML=html;
  }
  function renderFiles(d){
    var files=document.getElementById('files');
    var html='<div class="paneh">Files</div>', n=0;
    d.entries.forEach(function(e){
      if(e.type==='dir') return;
      n++;
      var fp = d.path.replace(/\/$/,'')+'/'+e.name;
      html+='<div class="row file" data-path="'+esc(fp)+'" data-type="file"><span class="ico">'+FILE+'</span><span class="nm">'+esc(e.name)+'</span><span class="sz">'+fmtSize(e.size)+'</span></div>';
    });
    if(!n) html+='<div class="empty">No files in this folder.</div>';
    files.innerHTML=html;
  }
  // Every /list call takes a ticket; only the newest one, for the project still
  // open, may touch the DOM. A slower, older answer (or one that lands after
  // the user went back to Projects) is dropped instead of repainting the
  // explorer with another folder — and steering New file/folder there.
  var loadSeq=0;
  function load(path){
    if(!within(path)) path=proj.root;
    var seq=++loadSeq, forProj=proj;
    function current(){ return seq===loadSeq && proj===forProj; }
    bridgeFetch('/list?path='+encodeURIComponent(path)).then(function(r){ if(!r.ok) throw 0; return r.json(); })
      .then(function(d){
        if(!current()) return;
        // All-or-nothing: both panes must exist before anything is written.
        if(!document.getElementById('nav') || !document.getElementById('files')) return;
        if(!d || typeof d.path!=='string' || !within(d.path)) throw 0;
        cur=d.path; selFile=null; lastListing=d; renderCrumb(); renderNav(d); renderFiles(d);
      })
      .catch(function(){ if(!current()) return; renderCrumb(); showErr(); });
  }
  // Preview takes over the RIGHT pane; the header's ✕ goes back to the files.
  function openFile(path, name){
    var files=document.getElementById('files');
    selFile=path;
    files.innerHTML='<div class="pvhead"><span class="nm">'+esc(name)+'</span><button id="pv-close">✕ Files</button></div>'
      +'<div class="preview" id="preview"><div class="hint">Loading…</div></div>';
    document.getElementById('pv-close').addEventListener('click', function(){
      selFile=null;
      if(lastListing) renderFiles(lastListing); else load(cur);
    });
    var preview=document.getElementById('preview');
    bridgeFetch('/read?path='+encodeURIComponent(path)).then(function(r){ return r.json(); })
      .then(function(d){
        if(d.binary || d.content==null){ preview.innerHTML='<div class="hint">'+esc(name)+' can’t be previewed (binary or unreadable).</div>'; return; }
        preview.innerHTML='<pre>'+esc(d.content)+(d.truncated?'\n\n… (truncated)':'')+'</pre>';
      }).catch(function(){ preview.innerHTML='<div class="hint">Couldn’t read this file.</div>'; });
  }
  // The splitter: drag left/right to rebalance the panes; width persists.
  function bindSplit(){
    var split=document.getElementById('split'), nav=document.getElementById('nav');
    split.addEventListener('mousedown', function(e){
      e.preventDefault();
      split.classList.add('on');
      var startX=e.clientX, startW=nav.getBoundingClientRect().width;
      function move(ev){
        var w=Math.max(160, Math.min(window.innerWidth*0.7, startW+(ev.clientX-startX)));
        nav.style.width=w+'px';
      }
      function up(){
        window.removeEventListener('mousemove',move); window.removeEventListener('mouseup',up);
        split.classList.remove('on');
        try{ localStorage.setItem('semitexa_files_split', String(Math.round(nav.getBoundingClientRect().width))); }catch(err){}
      }
      window.addEventListener('mousemove',move); window.addEventListener('mouseup',up);
    });
  }
  // ---------- context menu + file operations (the bridge's /fs/* API) ----------
  function closeCtx(){ var m=document.querySelector('.ctx'); if(m) m.remove(); }
  document.addEventListener('click', closeCtx);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeCtx(); });
  function ctxMenu(x, y, items){
    closeCtx();
    var m=document.createElement('div'); m.className='ctx';
    items.forEach(function(it){
      if(it==='-'){ var s=document.createElement('div'); s.className='sep'; m.appendChild(s); return; }
      var b=document.createElement('button');
      if(it.danger) b.className='danger';
      b.textContent=it.label;
      b.addEventListener('click', function(ev){ ev.stopPropagation(); closeCtx(); it.run(); });
      m.appendChild(b);
    });
    document.body.appendChild(m);
    var r=m.getBoundingClientRect();
    m.style.left=Math.min(x, window.innerWidth-r.width-8)+'px';
    m.style.top=Math.min(y, window.innerHeight-r.height-8)+'px';
  }
  function modal(build){
    var back=document.createElement('div'); back.className='mback';
    var box=document.createElement('div'); box.className='mbox';
    back.appendChild(box); document.body.appendChild(back);
    function close(){ back.remove(); }
    back.addEventListener('click', function(e){ if(e.target===back) close(); });
    build(box, close);
    return close;
  }
  function askName(title, initial, cb){
    modal(function(box, close){
      box.innerHTML='<h3>'+esc(title)+'</h3><input type="text" value="'+esc(initial)+'">'
        +'<div class="mrow"><button class="mbtn" data-m="cancel">Cancel</button><button class="mbtn primary" data-m="ok">OK</button></div>';
      var inp=box.querySelector('input');
      inp.focus(); inp.select();
      function submit(){ var v=inp.value.trim(); if(!v) return; close(); cb(v); }
      inp.addEventListener('keydown', function(e){ if(e.key==='Enter') submit(); if(e.key==='Escape') close(); });
      box.querySelector('[data-m=ok]').addEventListener('click', submit);
      box.querySelector('[data-m=cancel]').addEventListener('click', close);
    });
  }
  function askConfirm(title, text, cb){
    modal(function(box, close){
      box.innerHTML='<h3>'+esc(title)+'</h3><p>'+esc(text)+'</p>'
        +'<div class="mrow"><button class="mbtn" data-m="cancel">Cancel</button><button class="mbtn danger" data-m="ok">Move to trash</button></div>';
      box.querySelector('[data-m=ok]').addEventListener('click', function(){ close(); cb(); });
      box.querySelector('[data-m=cancel]').addEventListener('click', close);
      box.querySelector('[data-m=ok]').focus();
    });
  }
  function fsOp(endpoint, body, okMsg){
    if(body.path===''){ toast('Folder not loaded — reopen the project first.', true); return; }
    var forProj=proj;
    bridgeFetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(!d.ok){
          var msg=d.error==='exists'?'Already exists.':(d.error==='bad-name'?'Invalid name.'
            :(d.error==='denied'?'Denied: outside the bridge root (check SEMITEXA_FILES_ROOT).':'Operation failed: '+(d.error||'?')));
          toast(msg, true);
          return;
        }
        toast(okMsg);
        // The operation succeeded; reload only if the user is still in the project it ran in.
        if(proj && proj===forProj) load(cur);
      })
      .catch(function(){ toast('Bridge is not running — start bridged.py on this machine.', true); });
  }
  function rowMenu(e, path, type, name){
    var items=[];
    if(type==='dir'){
      items.push({label:'Open', run:function(){ load(path); }});
    } else {
      items.push({label:'Preview', run:function(){ openFile(path, name); }});
    }
    items.push('-');
    items.push({label:'Rename…', run:function(){ askName('Rename “'+name+'”', name, function(v){ fsOp('/fs/rename',{path:path,name:v},'Renamed.'); }); }});
    items.push({label:'Archive (zip)', run:function(){ fsOp('/fs/archive',{path:path},'Archived.'); }});
    if(type!=='dir' && /\.zip$/i.test(name)){
      items.push({label:'Extract here', run:function(){ fsOp('/fs/extract',{path:path},'Extracted.'); }});
    }
    items.push({label:'＋ My world', run:function(){
      fetch('/os/weave/attach',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({path:path,kind:type==='dir'?'folder':'file'})})
        .then(function(r){ return r.json(); })
        .then(function(d){
          toast(d.ok?'Added to your world.':(d.error||'Couldn’t attach.'), !d.ok);
          if(d.ok){ try{ (window.parent||window).postMessage({type:'os:weave-changed'},'*'); }catch(e){} }
        })
        .catch(function(){ toast('Couldn’t attach.', true); });
    }});
    items.push('-');
    items.push({label:'Move to trash', danger:true, run:function(){
      askConfirm('Delete “'+name+'”?', 'It will be moved to the OS trash ('+(type==='dir'?'the whole folder':'the file')+'), not erased.', function(){
        fsOp('/fs/delete',{path:path},'Moved to trash.');
      });
    }});
    ctxMenu(e.clientX, e.clientY, items);
  }
  function blankMenu(e){
    ctxMenu(e.clientX, e.clientY, [
      {label:'New file…', run:function(){ askName('New file', 'untitled.md', function(v){ fsOp('/fs/newfile',{path:cur,name:v},'File created.'); }); }},
      {label:'New folder…', run:function(){ askName('New folder', 'folder', function(v){ fsOp('/fs/mkdir',{path:cur,name:v},'Folder created.'); }); }},
      '-',
      {label:'Refresh', run:function(){ load(cur); }},
    ]);
  }
  function wirePanes(){
    ['nav','files'].forEach(function(id){
      var pane=document.getElementById(id);
      pane.addEventListener('click',function(e){
        var row=e.target.closest('.row'); if(!row) return;
        if(row.dataset.type==='dir') load(row.dataset.path);
        else openFile(row.dataset.path, row.querySelector('.nm').textContent);
      });
      pane.addEventListener('contextmenu',function(e){
        e.preventDefault();
        var row=e.target.closest('.row');
        if(row && row.querySelector('.nm').textContent!=='..'){
          rowMenu(e, row.dataset.path, row.dataset.type, row.querySelector('.nm').textContent);
        } else if(!row){
          blankMenu(e);
        }
      });
    });
  }
  crumb.addEventListener('click',function(e){
    if(e.target.id==='home'){ showProjects(); return; }
    var s=e.target.closest('.seg');
    if(s && s.dataset.path && !s.classList.contains('cur') && proj) load(s.dataset.path);
  });
  // Entry: a ?path deep-link (a Workspace folder-node click) opens the explorer
  // right there; opened directly, Files starts at your projects.
  var start=new URLSearchParams(location.search).get('path')||'';
  if(start){ openProject(start.split('/').pop()||start, start); }
  else showProjects();
})();
