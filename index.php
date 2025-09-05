<?php
declare(strict_types=1);

header_remove("X-Powered-By");

$store = __DIR__ . DIRECTORY_SEPARATOR . 'scores.json';

/** Ensure store exists & writable on first run */
function ensure_store(string $store): void {
    if (!file_exists($store)) {
        @file_put_contents($store, "[]", LOCK_EX);
        @chmod($store, 0664);
    }
}
ensure_store($store);

/** ---- Simple API for highscores ---- */
function read_scores(string $store): array {
    if (!file_exists($store)) return [];
    $raw = @file_get_contents($store);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    return $data;
}

function write_scores(string $store, array $scores): bool {
    $json = json_encode($scores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $ok = @file_put_contents($store, $json, LOCK_EX);
    return $ok !== false;
}

function sanitize_name(string $name): string {
    $name = trim($name);
    if ($name === '') return 'Anonymous';
    $name = mb_substr($name, 0, 24, 'UTF-8');
    $name = strip_tags($name);
    return $name ?: 'Anonymous';
}

function respond_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'highscores') {
    $scores = read_scores($store);
    usort($scores, function($a, $b) {
        if (($b['score'] ?? 0) !== ($a['score'] ?? 0)) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if (($b['duration'] ?? 0) !== ($a['duration'] ?? 0)) return ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0);
        return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
    });
    $scores = array_slice($scores, 0, 15);
    respond_json(['ok' => true, 'scores' => $scores]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $name = sanitize_name((string)($_POST['name'] ?? 'Anonymous'));
    $score = (int)($_POST['score'] ?? 0);
    $duration = (int)($_POST['duration'] ?? 60);
    $client_ts = (int)($_POST['client_ts'] ?? 0);

    $entry = [
        'name' => $name,
        'score' => max(0, $score),
        'duration' => max(10, min(300, $duration)),
        'client_ts' => $client_ts,
        'ts' => time()
    ];

    $scores = read_scores($store);
    $scores[] = $entry;

    usort($scores, function($a, $b) {
        if (($b['score'] ?? 0) !== ($a['score'] ?? 0)) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if (($b['duration'] ?? 0) !== ($a['duration'] ?? 0)) return ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0);
        return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
    });
    $scores = array_slice($scores, 0, 100);

    $ok = write_scores($store, $scores);

    if (!$ok) {
        $writable = is_writable($store) ? 'yes' : 'no';
        $dirWritable = is_writable(dirname($store)) ? 'yes' : 'no';
        respond_json([
            'ok' => false,
            'error' => 'WRITE_FAILED',
            'store' => $store,
            'store_exists' => file_exists($store),
            'store_writable' => $writable,
            'dir_writable' => $dirWritable,
        ], 500);
    }

    respond_json(['ok' => true, 'saved' => $entry]);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cheese Chase 🧀</title>

  <style>
  :root{
    --bg-top:#bfe6ff;--bg-mid:#d6f0ff;--bg-bottom:#eef8ff;
    --card:#fff;--surface:#f3f9ff;--muted:#4b5563;--ink:#0f172a;
    --line:#dbeafe;--line-strong:#bfdbfe;--primary:#f59e0b;
    --accent:#22c55e;--danger:#ef4444;--blue:#3b82f6;
  }
  *{box-sizing:border-box} html,body{height:100%}
  body{
    margin:0;
    background:
      radial-gradient(900px 480px at 20% 10%, #ffffff80, transparent 60%),
      radial-gradient(800px 420px at 80% 0%, #ffffff70, transparent 60%),
      radial-gradient(900px 520px at 70% 100%, #ffffff60, transparent 60%),
      linear-gradient(180deg, var(--bg-top) 0%, var(--bg-mid) 45%, var(--bg-bottom) 100%);
    color:var(--ink);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple Color Emoji","Segoe UI Emoji";
    line-height:1.5;display:grid;place-items:center;padding:16px;
  }
  .wrap{display:grid;grid-template-columns:1fr 360px;gap:16px;width:min(1100px,100%);align-items:start}
  @media (max-width:980px){.wrap{grid-template-columns:1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 10px 28px rgba(59,130,246,.12)}
  .game{padding:16px}
  #canvas{display:block;width:100%;height:auto;border-radius:12px;background:linear-gradient(180deg,#eaf6ff 0%,#d9f0ff 60%,#cfeaff 100%);border:1px solid var(--line)}
  .hud{display:grid;grid-template-columns:repeat(4,auto) 1fr;align-items:center;gap:12px 16px;padding:8px 8px 12px 8px;font-weight:700}
  .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:var(--surface);border:1px solid var(--line);color:var(--ink)}
  .pill .dot{width:8px;height:8px;border-radius:999px;display:inline-block}
  .pill.time .dot{background:var(--accent)} .pill.score .dot{background:var(--primary)}
  .pill.lives .dot{background:var(--danger)} .pill.speed .dot{background:var(--blue)}
  .controls-bar{text-align:right}
  .btn{cursor:pointer;border:1px solid var(--line-strong);background:#fff;color:var(--ink);padding:8px 12px;border-radius:10px;font-weight:800;transition:box-shadow .15s, transform .02s, background .15s}
  .btn:hover{background:#f0f7ff;box-shadow:0 2px 10px rgba(59,130,246,.18)} .btn:active{transform:translateY(1px)}
  .btn:disabled{opacity:.5;cursor:not-allowed;background:#f3f4f6;color:#9ca3af}
  .sidebar{padding:16px;display:grid;gap:16px}
  h1{margin:0;font-size:clamp(22px,2.8vw,32px);letter-spacing:.2px;display:flex;align-items:center;gap:10px;color:var(--ink)}
  h1 .cheese{font-size:1.1em;filter:drop-shadow(0 2px 6px rgba(245,158,11,.25))}
  .tip{color:var(--muted);font-size:14px}
  .leaderboard{padding:12px;background:#fff;border:1px solid var(--line);border-radius:12px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left}
  th{color:#475569;font-weight:700}
  .flex{display:flex;gap:10px;align-items:center} .grow{flex:1}
  input[type="text"]{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line-strong);background:#fff;color:var(--ink);font-weight:600;outline:none;box-shadow:0 1px 0 rgba(59,130,246,.06) inset}
  input[type="text"]::placeholder{color:#90a4c4}
  .dpad{margin-top:8px;display:grid;grid-template-columns:48px 48px 48px;grid-template-rows:48px 48px 48px;gap:8px;width:max-content;user-select:none}
  .dpad .padbtn{width:48px;height:48px;border-radius:10px;display:grid;place-items:center;background:#fff;border:1px solid var(--line-strong);box-shadow:0 1px 0 rgba(59,130,246,.06) inset;font-weight:800;cursor:pointer;touch-action:none}
  .padbtn:active{background:#eaf4ff} .padbtn.empty{opacity:0;pointer-events:none}
  .status{font-size:14px;color:var(--muted)}
  .modal{position:fixed;inset:0;display:none;place-items:center;background:rgba(30,64,175,.18);z-index:50}
  .modal.show{display:grid}
  .modal .box{width:min(520px,92vw);background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 20px 60px rgba(59,130,246,.18)}
  .modal h2{margin:0 0 6px 0;color:var(--ink)} .center{text-align:center} .heart{color:var(--danger)}
  .chip{display:inline-block;padding:4px 8px;border-radius:999px;background:var(--surface);border:1px solid var(--line);font-size:12px;color:#3b4b66;margin-left:8px}
  .footer{color:#3b4b66;font-size:12px;text-align:center;padding-top:6px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card game">
      <div class="hud">
        <div class="pill time"><span class="dot"></span> <span id="time">60</span>s</div>
        <div class="pill score"><span class="dot"></span> <span id="score">0</span> pts</div>
        <div class="pill lives"><span class="dot"></span> <span id="lives">3</span> <span class="heart">♥</span></div>
        <div class="pill speed"><span class="dot"></span> <span id="speed">4.0</span> px/tick</div>
        <div class="controls-bar">
          <button class="btn" id="btnPause">⏸️ Pause</button>
          <button class="btn" id="btnRestart">🔄 Restart</button>
        </div>
      </div>
      <canvas id="canvas" width="900" height="540" aria-label="Cheese Chase game area"></canvas>
      <div class="status">
        Controls: Arrow keys / WASD. Collect 🧀, avoid ⛔. Each cheese speeds you up a bit. Traps cost a life.
      </div>
      <div class="dpad" id="dpad" aria-hidden="true">
        <div class="padbtn empty"></div>
        <div class="padbtn" data-dir="up">▲</div>
        <div class="padbtn empty"></div>
        <div class="padbtn" data-dir="left">◀</div>
        <div class="padbtn" data-dir="down">▼</div>
        <div class="padbtn" data-dir="right">▶</div>
        <div class="padbtn empty"></div>
        <div class="padbtn empty"></div>
        <div class="padbtn empty"></div>
      </div>
    </div>

    <aside class="card sidebar">
      <h1>Cheese Chase <span class="cheese">🧀</span><span class="chip">HTML + CSS + JS + PHP</span></h1>
      <div class="tip">Beat the clock in 60 seconds. Save your best score to the server leaderboard!</div>

      <div class="leaderboard">
        <div class="flex" style="margin-bottom:8px;">
          <strong>Leaderboard</strong>
          <span class="grow"></span>
          <button class="btn" id="btnRefresh">↻ Refresh</button>
        </div>
        <table>
          <thead><tr><th>#</th><th>Name</th><th>Score</th><th>When</th></tr></thead>
          <tbody id="tbodyScores"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table>
      </div>

      <div class="flex">
        <input id="playerName" type="text" placeholder="Your name (for leaderboard)" maxlength="24" />
        <button class="btn" id="btnSave" disabled>Save score</button>
      </div>
      <div class="footer">Tip: Your name is saved locally for next time.</div>
    </aside>
  </div>

  <div class="modal" id="modal">
    <div class="box">
      <h2 class="center">Game Over!</h2>
      <p class="center">Final score: <strong id="finalScore">0</strong></p>
      <div class="center" style="margin-top: 8px;">
        <button class="btn" id="btnPlayAgain">Play again</button>
      </div>
    </div>
  </div>

  <script>
    // ===== Utility =====
    const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
    const rand = (a, b) => Math.random() * (b - a) + a;

    // ===== Canvas / World =====
    const cvs = document.getElementById('canvas');
    const ctx = cvs.getContext('2d');

    const hud = {
      time: document.getElementById('time'),
      score: document.getElementById('score'),
      lives: document.getElementById('lives'),
      speed: document.getElementById('speed')
    };

    const modal = document.getElementById('modal');
    const finalScoreEl = document.getElementById('finalScore');

    const UI = {
      pause: document.getElementById('btnPause'),
      restart: document.getElementById('btnRestart'),
      refresh: document.getElementById('btnRefresh'),
      save: document.getElementById('btnSave'),
      name: document.getElementById('playerName'),
      playAgain: document.getElementById('btnPlayAgain'),
      tbody: document.getElementById('tbodyScores'),
      dpad: document.getElementById('dpad'),
    };

    // Restore saved name
    UI.name.value = localStorage.getItem('cheese_name') || '';
    UI.save.disabled = true;

    const WORLD = {
      width: cvs.width,
      height: cvs.height,
      timeLimit: 60,
      started: false,
      paused: false,
      over: false,
    };

    const playerBaseSpeed = 4.0;

    const player = {
      x: 80, y: 80, r: 16,
      vx: 0, vy: 0,
      speed: playerBaseSpeed,
      lives: 3,
      color: '#e6f3ff',
    };

    const cheeseList = [];
    const traps = [];
    const maxCheese = 4;
    const maxTraps = 6;

    let score = 0;
    let timeLeft = WORLD.timeLimit;
    let lastTick = performance.now();

    // cache final score between screens
    let lastFinalScore = 0;
    let hasSavedLastScore = false;

    function resetGame() {
      player.x = 80; player.y = 80;
      player.vx = 0; player.vy = 0;
      player.speed = playerBaseSpeed;
      player.lives = 3;
      score = 0;
      timeLeft = WORLD.timeLimit;
      WORLD.started = false;
      WORLD.paused = false;
      WORLD.over = false;
      cheeseList.length = 0;
      traps.length = 0;
      for (let i = 0; i < maxCheese; i++) spawnCheese();
      for (let i = 0; i < maxTraps; i++) spawnTrap();
      UI.save.disabled = true;
      updateHUD();
      hideModal();
    }

    function updateHUD() {
      hud.time.textContent = Math.ceil(timeLeft);
      hud.score.textContent = score;
      hud.lives.textContent = player.lives;
      hud.speed.textContent = player.speed.toFixed(1);
      UI.pause.textContent = WORLD.paused ? '▶️ Resume' : '⏸️ Pause';
    }

    function spawnCheese() {
      const c = { x: rand(40, WORLD.width-40), y: rand(40, WORLD.height-40), r: 12 };
      cheeseList.push(c);
    }

    function spawnTrap() {
      const t = { x: rand(40, WORLD.width-40), y: rand(40, WORLD.height-40), size: 22, blink: 0 };
      traps.push(t);
    }

    // ===== Input =====
    const keys = new Set();
    window.addEventListener('keydown', (e) => {
      if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight',' '].includes(e.key)) e.preventDefault();
      if (e.key === ' ' && !WORLD.started && !WORLD.over) { WORLD.started = true; }
      keys.add(e.key.toLowerCase());
    });
    window.addEventListener('keyup', (e) => keys.delete(e.key.toLowerCase()));

    function dpadPress(dir, pressed) {
      const map = { up: 'arrowup', down: 'arrowdown', left: 'arrowleft', right: 'arrowright' };
      if (pressed) keys.add(map[dir]); else keys.delete(map[dir]);
    }

    // Mobile dpad
    UI.dpad.querySelectorAll('[data-dir]').forEach(btn => {
      const dir = btn.getAttribute('data-dir');
      const start = (e) => { e.preventDefault(); dpadPress(dir, true); };
      const end = (e) => { e.preventDefault(); dpadPress(dir, false); };
      btn.addEventListener('pointerdown', start);
      window.addEventListener('pointerup', end);
      btn.addEventListener('pointerleave', end);
    });

    // ===== Game Loop =====
    function tick() {
      const now = performance.now();
      const dt = (now - lastTick) / 16.6667; // normalize to 60 fps steps
      lastTick = now;

      if (!WORLD.paused && WORLD.started && !WORLD.over) {
        step(dt);
      }
      draw();
      requestAnimationFrame(tick);
    }

    function step(dt) {
      // Real-time countdown: 60 frames ≈ 1s
      timeLeft -= dt / 60;
      if (timeLeft <= 0) { timeLeft = 0; gameOver(); }

      // Input → velocity
      let ax = 0, ay = 0;
      if (keys.has('arrowleft') || keys.has('a')) ax -= 1;
      if (keys.has('arrowright') || keys.has('d')) ax += 1;
      if (keys.has('arrowup') || keys.has('w')) ay -= 1;
      if (keys.has('arrowdown') || keys.has('s')) ay += 1;

      const len = Math.hypot(ax, ay) || 1;
      ax /= len; ay /= len;

      player.vx = ax * player.speed;
      player.vy = ay * player.speed;

      // Move
      player.x = clamp(player.x + player.vx * dt, player.r, WORLD.width - player.r);
      player.y = clamp(player.y + player.vy * dt, player.r, WORLD.height - player.r);

      // Collisions: cheese
      for (let i = cheeseList.length - 1; i >= 0; i--) {
        const c = cheeseList[i];
        if (Math.hypot(player.x - c.x, player.y - c.y) < player.r + c.r) {
          cheeseList.splice(i, 1);
          score += 10;
          player.speed = Math.min(player.speed + 0.2, 9);
          timeLeft = Math.min(WORLD.timeLimit, timeLeft + 1.5);
          spawnCheese();
          if (traps.length < maxTraps && Math.random() < 0.4) spawnTrap();
        }
      }

      // Collisions: traps
      for (let i = traps.length - 1; i >= 0; i--) {
        const t = traps[i];
        if (pointInTriangle(player.x, player.y, trianglePoints(t))) {
          traps.splice(i, 1);
          player.lives -= 1;
          player.speed = Math.max(playerBaseSpeed, player.speed - 0.6);
          timeLeft = Math.max(0, timeLeft - 2.5);
          if (player.lives <= 0) { gameOver(); }
          else spawnTrap();
        } else {
          t.blink += dt;
        }
      }

      updateHUD();
    }

    function gameOver() {
      WORLD.over = true;
      WORLD.started = false;
      lastFinalScore = score;             // cache final score
      hasSavedLastScore = false;
      finalScoreEl.textContent = lastFinalScore;
      UI.save.disabled = (lastFinalScore <= 0);
      showModal();
    }

    // ===== Draw =====
    function draw() {
      ctx.clearRect(0, 0, cvs.width, cvs.height);

      // Background grid
      ctx.globalAlpha = 1;
      gridBG();

      for (const c of cheeseList) drawCheese(c);
      for (const t of traps) drawTrap(t);
      drawPlayer(player);
    }

    function gridBG() {
      const step = 30;
      ctx.save();
      ctx.strokeStyle = 'rgba(30, 64, 175, 0.10)';
      ctx.lineWidth = 1;
      for (let x = 0; x < cvs.width; x += step) {
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, cvs.height); ctx.stroke();
      }
      for (let y = 0; y < cvs.height; y += step) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(cvs.width, y); ctx.stroke();
      }
      ctx.restore();
    }

    function drawCheese(c) {
      ctx.save();
      const g = ctx.createRadialGradient(c.x - c.r/3, c.y - c.r/3, 2, c.x, c.y, c.r);
      g.addColorStop(0, '#fff6a5');
      g.addColorStop(1, '#ffd93d');
      ctx.fillStyle = g;
      ctx.beginPath(); ctx.arc(c.x, c.y, c.r, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = 'rgba(0,0,0,0.15)';
      ctx.beginPath(); ctx.arc(c.x - 4, c.y - 2, 2.5, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc(c.x + 5, c.y + 4, 3, 0, Math.PI*2); ctx.fill();
      ctx.restore();
    }

    function trianglePoints(t) {
      const s = t.size;
      return [
        {x: t.x, y: t.y - s/1.2},
        {x: t.x - s/1.2, y: t.y + s/1.2},
        {x: t.x + s/1.2, y: t.y + s/1.2}
      ];
    }

    function drawTrap(t) {
      const pts = trianglePoints(t);
      ctx.save();
      const pulse = 0.7 + 0.3 * Math.sin(t.blink / 4);
      ctx.fillStyle = `rgba(255, 107, 107, ${pulse})`;
      ctx.beginPath();
      ctx.moveTo(pts[0].x, pts[0].y);
      ctx.lineTo(pts[1].x, pts[1].y);
      ctx.lineTo(pts[2].x, pts[2].y);
      ctx.closePath();
      ctx.fill();
      ctx.restore();
    }

    function pointInTriangle(px, py, pts) {
      const [A,B,C] = pts;
      const v0x = C.x - A.x, v0y = C.y - A.y;
      const v1x = B.x - A.x, v1y = B.y - A.y;
      const v2x = px - A.x, v2y = py - A.y;
      const dot00 = v0x*v0x + v0y*v0y;
      const dot01 = v0x*v1x + v0y*v1y;
      const dot02 = v0x*v2x + v0y*v2y;
      const dot11 = v1x*v1x + v1y*v1y;
      const dot12 = v1x*v2x + v1y*v2y;
      const invDen = 1 / (dot00 * dot11 - dot01 * dot01 + 1e-9);
      const u = (dot11 * dot02 - dot01 * dot12) * invDen;
      const v = (dot00 * dot12 - dot01 * dot02) * invDen;
      return (u >= 0) && (v >= 0) && (u + v < 1);
    }

    function drawPlayer(p) {
      ctx.save();
      const gradient = ctx.createLinearGradient(p.x, p.y - p.r, p.x, p.y + p.r);
      gradient.addColorStop(0, '#f0f6ff');
      gradient.addColorStop(1, '#bcd3ff');
      ctx.fillStyle = gradient;
      ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI*2); ctx.fill();

      ctx.fillStyle = '#e6b3ff';
      ctx.beginPath(); ctx.arc(p.x - 12, p.y - 14, 6, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc(p.x + 12, p.y - 14, 6, 0, Math.PI*2); ctx.fill();

      ctx.fillStyle = '#333a';
      ctx.beginPath(); ctx.arc(p.x + 6, p.y + 2, 2, 0, Math.PI*2); ctx.fill();
      ctx.restore();
    }

    // ===== Saving helpers =====
    async function saveScore(scoreToSave) {
      const name = UI.name.value.trim() || 'Anonymous';
      localStorage.setItem('cheese_name', name);

      const res = await fetch(location.pathname + '?t=' + Date.now(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'save',
          name,
          score: String(scoreToSave),
          duration: String(WORLD.timeLimit),
          client_ts: String(Math.floor(Date.now()/1000))
        })
      });

      try {
        const data = await res.json();
        if (data.ok) {
          hasSavedLastScore = true;
          UI.save.disabled = true;
          refreshScores();
          return true;
        } else {
          console.error(data);
          alert('Save failed. See console.');
          return false;
        }
      } catch (e) {
        console.error(e);
        alert('Save failed (server did not return JSON).');
        return false;
      }
    }

    // ===== Buttons / UI =====
    UI.restart.addEventListener('click', async () => {
      // Optional: auto-save before a manual restart
      if (!hasSavedLastScore && lastFinalScore > 0 && WORLD.over) {
        await saveScore(lastFinalScore);
      }
      resetGame();
    });

    UI.pause.addEventListener('click', () => {
      if (WORLD.over) return;
      WORLD.paused = !WORLD.paused;
      if (!WORLD.started && !WORLD.over && !WORLD.paused) WORLD.started = true;
      updateHUD();
    });

    UI.playAgain.addEventListener('click', async () => {
      if (!hasSavedLastScore && lastFinalScore > 0) {
        await saveScore(lastFinalScore); // auto-save last score
      }
      resetGame();
      WORLD.started = true;
    });

    UI.refresh.addEventListener('click', () => refreshScores());

    UI.save.addEventListener('click', async () => {
      if (!WORLD.over) {
        alert("Finish the game first, then save your score!");
        return;
      }
      await saveScore(lastFinalScore);
    });

    function showModal() { modal.classList.add('show'); }
    function hideModal() { modal.classList.remove('show'); }

    async function refreshScores() {
      UI.tbody.innerHTML = '<tr><td colspan="4">Loading…</td></tr>';
      try {
        const res = await fetch(location.pathname + '?action=highscores&t=' + Date.now());
        const data = await res.json();
        const rows = (data.scores || []).map((s, i) => `
          <tr>
            <td>${i+1}</td>
            <td>${escapeHTML(s.name)}</td>
            <td><strong>${s.score}</strong></td>
            <td>${timeAgo(s.ts || 0)}</td>
          </tr>
        `);
        UI.tbody.innerHTML = rows.join('') || '<tr><td colspan="4">No scores yet. Be the first!</td></tr>';
      } catch (e) {
        UI.tbody.innerHTML = '<tr><td colspan="4">Failed to load scores.</td></tr>';
        console.error(e);
      }
    }

    function escapeHTML(str) {
      return (str+'').replace(/[&<>"']/g, s => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      })[s]);
    }

    function timeAgo(ts) {
      const delta = Math.max(1, Math.floor(Date.now()/1000) - ts);
      const units = [['y',31536000],['mo',2592000],['w',604800],['d',86400],['h',3600],['m',60],['s',1]];
      for (const [u, sec] of units) {
        if (delta >= sec) {
          const v = Math.floor(delta / sec);
          return `${v}${u} ago`;
        }
      }
      return 'now';
    }

    // Start
    resetGame();
    refreshScores();
    requestAnimationFrame(tick);

    // Start on first key press
    window.addEventListener('keydown', () => {
      if (!WORLD.started && !WORLD.over) WORLD.started = true;
    }, { once: true });
  </script>
</body>
</html>

