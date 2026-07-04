// WP-Ultra-MCP roadmap tracker — zero-dependency local UI over docs/ROADMAP.md
// Run:  node docs/roadmap-tracker.mjs   →  http://localhost:4488
// Clicking a task toggles `- [ ]` ↔ `- [x]` directly in ROADMAP.md (file = source of truth).

import { createServer } from 'node:http';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROADMAP = join(dirname(fileURLToPath(import.meta.url)), 'ROADMAP.md');
const PORT = Number(process.env.PORT) || 4488;

const TASK_RE = /^- \[( |x|X)\] (.*)$/;

function parseTasks() {
  const lines = readFileSync(ROADMAP, 'utf8').split(/\r?\n/);
  const sections = [];
  let current = null;
  lines.forEach((line, i) => {
    const h = line.match(/^## (.*)$/);
    if (h) {
      current = { title: h[1], tasks: [] };
      sections.push(current);
      return;
    }
    const t = line.match(TASK_RE);
    if (t && current) {
      current.tasks.push({ line: i, done: t[1].toLowerCase() === 'x', text: t[2] });
    }
  });
  return sections;
}

function toggle(lineNo) {
  const raw = readFileSync(ROADMAP, 'utf8');
  const eol = raw.includes('\r\n') ? '\r\n' : '\n';
  const lines = raw.split(/\r?\n/);
  const m = (lines[lineNo] ?? '').match(TASK_RE);
  if (!m) return false;
  const done = m[1].toLowerCase() === 'x';
  lines[lineNo] = `- [${done ? ' ' : 'x'}] ${m[2]}`;
  writeFileSync(ROADMAP, lines.join(eol), 'utf8');
  return true;
}

const HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WP-Ultra-MCP Roadmap</title>
<style>
  :root { --bg:#0f1117; --card:#181b24; --line:#262a36; --text:#e6e8ee; --dim:#8b90a0;
          --accent:#7c9cff; --done:#4ade80; }
  * { box-sizing:border-box; margin:0; }
  body { background:var(--bg); color:var(--text);
         font:15px/1.5 "Segoe UI",system-ui,sans-serif; padding:28px 16px 80px; }
  .wrap { max-width:860px; margin:0 auto; }
  h1 { font-size:22px; margin-bottom:4px; }
  .sub { color:var(--dim); font-size:13px; margin-bottom:18px; }
  .bar { height:10px; background:var(--line); border-radius:6px; overflow:hidden; margin:10px 0 4px; }
  .bar i { display:block; height:100%; background:linear-gradient(90deg,var(--accent),var(--done));
           transition:width .35s; }
  .count { font-size:13px; color:var(--dim); margin-bottom:24px; }
  section { background:var(--card); border:1px solid var(--line); border-radius:12px;
            padding:14px 16px; margin-bottom:16px; }
  h2 { font-size:16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:baseline; }
  h2 small { color:var(--dim); font-weight:normal; font-size:12px; }
  .task { display:flex; gap:10px; align-items:flex-start; padding:8px 8px; border-radius:8px;
          cursor:pointer; user-select:none; }
  .task:hover { background:#1f2330; }
  .copy { flex:0 0 auto; margin-left:auto; margin-top:1px; border:1px solid var(--line);
          background:#232735; color:var(--dim); border-radius:6px; padding:2px 8px;
          font-size:12px; cursor:pointer; opacity:0; transition:opacity .15s; }
  .task:hover .copy { opacity:1; }
  .copy:hover { color:var(--text); border-color:var(--accent); }
  .copy.ok { color:var(--done); border-color:var(--done); opacity:1; }
  .box { flex:0 0 20px; height:20px; margin-top:2px; border:2px solid var(--dim); border-radius:6px;
         display:grid; place-items:center; font-size:13px; color:transparent; transition:all .15s; }
  .task.done .box { background:var(--done); border-color:var(--done); color:#0f1117; }
  .task.done .txt { text-decoration:line-through; color:var(--dim); }
  .txt b, .txt code { color:var(--accent); }
  .task.done .txt b, .task.done .txt code { color:var(--dim); }
  code { background:#232735; padding:1px 5px; border-radius:4px; font-size:13px; }
  footer { color:var(--dim); font-size:12px; margin-top:24px; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🗺️ WP-Ultra-MCP Roadmap</h1>
  <div class="sub">Click a task to mark it complete — saved instantly into <code>docs/ROADMAP.md</code></div>
  <div class="bar"><i id="fill" style="width:0%"></i></div>
  <div class="count" id="count"></div>
  <div id="app">Loading…</div>
  <footer>File is the source of truth · edits here rewrite the checkbox lines only</footer>
</div>
<script>
function md(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;')
          .replace(/\\*\\*(.+?)\\*\\*/g,'<b>$1</b>')
          .replace(/\\*(.+?)\\*/g,'<i>$1</i>')
          .replace(/\`(.+?)\`/g,'<code>$1</code>');
}
async function load() {
  const sections = await (await fetch('/api/tasks')).json();
  let total = 0, done = 0;
  const app = document.getElementById('app');
  app.innerHTML = '';
  for (const sec of sections) {
    if (!sec.tasks.length) continue;
    const secDone = sec.tasks.filter(t => t.done).length;
    total += sec.tasks.length; done += secDone;
    const el = document.createElement('section');
    el.innerHTML = '<h2>' + md(sec.title) + '<small>' + secDone + '/' + sec.tasks.length + '</small></h2>';
    for (const t of sec.tasks) {
      const row = document.createElement('div');
      row.className = 'task' + (t.done ? ' done' : '');
      row.innerHTML = '<div class="box">✓</div><div class="txt">' + md(t.text) + '</div>'
                    + '<button class="copy" title="Copy task text">📋 copy</button>';
      row.onclick = async () => {
        await fetch('/api/toggle', { method:'POST', headers:{'Content-Type':'application/json'},
                                     body: JSON.stringify({ line: t.line }) });
        load();
      };
      const btn = row.querySelector('.copy');
      btn.onclick = async (e) => {
        e.stopPropagation();
        const plain = t.text.replace(/\\*\\*/g,'').replace(/\`/g,'');
        try {
          await navigator.clipboard.writeText(plain);
        } catch {
          const ta = document.createElement('textarea');
          ta.value = plain; document.body.appendChild(ta);
          ta.select(); document.execCommand('copy'); ta.remove();
        }
        btn.textContent = '✓ copied'; btn.classList.add('ok');
        setTimeout(() => { btn.textContent = '📋 copy'; btn.classList.remove('ok'); }, 1200);
      };
      el.appendChild(row);
    }
    app.appendChild(el);
  }
  document.getElementById('fill').style.width = total ? (100*done/total) + '%' : '0%';
  document.getElementById('count').textContent = done + ' / ' + total + ' complete';
}
load();
</script>
</body>
</html>`;

createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(HTML);
  } else if (req.method === 'GET' && req.url === '/api/tasks') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(parseTasks()));
  } else if (req.method === 'POST' && req.url === '/api/toggle') {
    let body = '';
    req.on('data', c => (body += c));
    req.on('end', () => {
      let ok = false;
      try { ok = toggle(JSON.parse(body).line); } catch { /* bad payload */ }
      res.writeHead(ok ? 200 : 400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok }));
    });
  } else {
    res.writeHead(404); res.end();
  }
}).listen(PORT, () => console.log(`Roadmap tracker → http://localhost:${PORT}`));
