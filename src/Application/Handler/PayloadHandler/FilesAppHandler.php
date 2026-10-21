<?php

declare(strict_types=1);

namespace Semitexa\Files\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Files\Application\Payload\Request\FilesAppPayload;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * Renders the Files dialog body — the OS's project-first explorer.
 *
 * Opened directly it shows the user's PROJECTS (Weave Project nodes, via
 * GET /os/weave/projects — no filesystem scanning); picking a project opens a
 * classic folder/file explorer CONFINED to that project's root. There is no
 * "all files" escape: the Weave graph, not the host filesystem, is the map of
 * the user's world. `?path=` deep-links (a Workspace folder-node click) open
 * the explorer rooted at that path directly.
 *
 * Standalone HTML because the Focus zone embeds it as an iframe; file listing
 * and preview talk to the local bridge (/list, /read, /open) client-side, so
 * the projects screen works even when the bridge is down — only entering a
 * project needs it.
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
  /* Theme tokens — dark defaults; [data-mode=light] flips the palette. A token
     that names itself (--text:var(--text)) is a cycle, invalid, and silently
     drops the colour: the dark values are written out. The app
     resolves the OS theme itself (fetch /os/preferences + the shell's auto rule),
     so it works identically in web iframes and OS-mode native windows. */
  :root{color-scheme:dark;--bg:#161622;--text:#dbe7ff;--text-2:#cdd9ee;--text-3:#a8b4cc;--mute:#8d9bb8;--dim:#5d6b86;
    --line-rgb:148,163,184;--accent:#37b7ff;--accent-rgb:55,183,255;--folder:#eab308}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--text:#1d2a38;--text-2:#243447;--text-3:#3b4c61;--mute:#55677e;--dim:#7c8ba0;
    --line-rgb:100,116,139;--accent:#1e7fb8;--accent-rgb:30,127,184;--folder:#b48206}
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column;font-size:14px}
  .bar{display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid rgba(var(--line-rgb),.18);min-height:44px}
  .crumb{flex:1;display:flex;align-items:center;gap:2px;flex-wrap:wrap;font-size:12.5px;overflow:hidden}
  .crumb .seg{color:var(--text-3);cursor:pointer;padding:2px 5px;border-radius:6px;white-space:nowrap}
  .crumb .seg:hover{background:rgba(var(--line-rgb),.14);color:var(--text)}
  .crumb .seg.cur{color:var(--text);font-weight:600;cursor:default}
  .crumb .sep{color:var(--dim)}
  .act{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:8px;border:1px solid rgba(var(--accent-rgb),.4);
       background:rgba(var(--accent-rgb),.12);color:var(--accent);font:600 12.5px 'IBM Plex Sans';cursor:pointer;white-space:nowrap}
  .act:hover{background:rgba(var(--accent-rgb),.22)}
  .act[hidden]{display:none}
  .main{flex:1;display:flex;min-height:0}
  /* ---- projects start screen ---- */
  .projects{flex:1;overflow:auto;padding:22px;align-content:start;display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
  .pcard{display:flex;flex-direction:column;gap:9px;padding:16px;border-radius:14px;border:1px solid rgba(var(--line-rgb),.16);
    background:rgba(var(--line-rgb),.06);cursor:pointer;transition:transform .15s,border-color .15s,background .15s}
  .pcard:hover{transform:translateY(-2px);border-color:rgba(var(--accent-rgb),.45);background:rgba(var(--accent-rgb),.07)}
  .pcard .top{display:flex;align-items:center;gap:10px}
  .pcard .pico{display:flex;color:var(--accent);flex:0 0 auto}
  .pcard .pname{font-weight:600;font-size:15px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pcard .pmeta{font-size:12px;color:var(--mute);min-height:15px}
  .pcard .ppath{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pcard.nopath .ppath{font-family:'IBM Plex Sans',system-ui,sans-serif;font-style:italic}
  .phead{grid-column:1/-1;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--dim);padding:2px 2px 0}
  .psearch{grid-column:1/-1;display:flex;align-items:center;gap:9px;padding:9px 13px;border-radius:11px;
    border:1px solid rgba(var(--line-rgb),.22);background:rgba(var(--line-rgb),.06)}
  .psearch:focus-within{border-color:rgba(var(--accent-rgb),.55);background:rgba(var(--accent-rgb),.05)}
  .psearch svg{color:var(--dim);flex:0 0 auto}
  .psearch input{flex:1;border:none;outline:none;background:transparent;color:var(--text);font:inherit;font-size:14px}
  .psearch input::placeholder{color:var(--dim)}
  .pnone{grid-column:1/-1;color:var(--dim);font-size:13px;padding:10px 2px}
  /* ---- explorer: folders (left) | splitter | files (right) ---- */
  .nav{width:280px;min-width:160px;max-width:70%;flex:0 0 auto;overflow:auto;padding:6px}
  .split{flex:0 0 6px;cursor:col-resize;background:transparent;border-left:1px solid rgba(var(--line-rgb),.14);transition:background .15s;position:relative}
  .split::after{content:'';position:absolute;top:0;bottom:0;left:-5px;right:-5px;cursor:col-resize} /* wider invisible hit zone */
  .split:hover,.split.on{background:rgba(var(--accent-rgb),.25)}
  .files{flex:1;min-width:0;overflow:auto;padding:6px;display:flex;flex-direction:column}
  .paneh{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--dim);padding:6px 9px 4px}
  .row{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:8px;cursor:pointer;color:var(--text-2)}
  .row:hover{background:rgba(var(--line-rgb),.12)}
  .row.sel{background:rgba(var(--accent-rgb),.16);color:var(--text)}
  .row .ico{flex:0 0 auto;display:flex;color:var(--mute)}
  .row.dir .ico{color:var(--folder)}
  .row .nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .row .sz{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--dim)}
  .pvhead{display:flex;align-items:center;gap:9px;padding:7px 9px;border-bottom:1px solid rgba(var(--line-rgb),.14)}
  .pvhead .nm{flex:1;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}
  .pvhead button{border:none;background:transparent;color:var(--text-3);cursor:pointer;font:600 12.5px 'IBM Plex Sans';padding:4px 8px;border-radius:7px}
  .pvhead button:hover{background:rgba(var(--line-rgb),.14);color:var(--text)}
  .preview{flex:1;overflow:auto;padding:0}
  .preview pre{margin:0;padding:16px;font-family:'IBM Plex Mono',monospace;font-size:12.5px;line-height:1.6;color:var(--text-2);white-space:pre-wrap;word-break:break-word}
  .hint{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:8px;color:var(--dim);text-align:center;padding:24px;font-size:13px}
  .err{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:10px;color:var(--mute);text-align:center;padding:30px}
  .err b{color:var(--text)} .err code{font-family:'IBM Plex Mono',monospace;background:rgba(var(--line-rgb),.14);padding:2px 7px;border-radius:6px;color:var(--text-3);font-size:12px}
  .empty{color:var(--dim);padding:14px;font-size:13px}
  .status{font-size:12px;color:#5eead4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:34%;opacity:0;transition:opacity .25s}
  .status.on{opacity:1}
  .status.err{color:#f87171;font-weight:600}
  /* ---- context menu ---- */
  .ctx{position:fixed;z-index:90;min-width:190px;padding:5px;border-radius:11px;border:1px solid rgba(var(--line-rgb),.24);
    background:var(--bg);box-shadow:0 14px 34px rgba(0,0,0,.35)}
  .ctx button{display:flex;align-items:center;gap:9px;width:100%;padding:7px 10px;border:none;border-radius:7px;
    background:transparent;color:var(--text-2);font:inherit;font-size:13px;text-align:left;cursor:pointer}
  .ctx button:hover{background:rgba(var(--accent-rgb),.14);color:var(--text)}
  .ctx button.danger:hover{background:rgba(244,63,94,.14);color:#f87171}
  .ctx .sep{height:1px;margin:5px 8px;background:rgba(var(--line-rgb),.18)}
  /* ---- tiny modal (name input / confirm) ---- */
  .mback{position:fixed;inset:0;z-index:95;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center}
  .mbox{width:min(360px,86vw);padding:16px;border-radius:13px;border:1px solid rgba(var(--line-rgb),.24);background:var(--bg);
    display:flex;flex-direction:column;gap:12px;box-shadow:0 18px 44px rgba(0,0,0,.4)}
  .mbox h3{margin:0;font-size:14px;font-weight:600;color:var(--text)}
  .mbox p{margin:0;font-size:13px;color:var(--mute);word-break:break-word}
  .mbox input{padding:8px 11px;border-radius:9px;border:1px solid rgba(var(--line-rgb),.3);background:transparent;color:var(--text);font:inherit;outline:none}
  .mbox input:focus{border-color:rgba(var(--accent-rgb),.6)}
  .mrow{display:flex;justify-content:flex-end;gap:8px}
  .mbtn{padding:7px 14px;border-radius:9px;border:1px solid rgba(var(--line-rgb),.3);background:transparent;color:var(--text-3);font:600 12.5px 'IBM Plex Sans';cursor:pointer}
  .mbtn.primary{border-color:rgba(var(--accent-rgb),.5);background:rgba(var(--accent-rgb),.14);color:var(--accent)}
  .mbtn.danger{border-color:rgba(244,63,94,.45);background:rgba(244,63,94,.12);color:#f87171}
</style></head>
<body>
  <div class="bar"><div class="crumb" id="crumb"></div><span class="status" id="status"></span></div>
  <div class="main" id="main"></div>
<script src="__FILES_APP_JS__"></script>
</body></html>
HTML;

        // The explorer's script is a static asset (fingerprinted, cached
        // immutable); nothing executable is left inline.
        $html = strtr($html, ['__FILES_APP_JS__' => AssetManager::getUrl('js/files-app.js', 'files')]);

        return $resource
            ->setContent(CspNonce::stamp($html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
