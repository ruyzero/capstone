<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Login | RHU-MIS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap only for reset & alerts (no BS components used) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --grad-a:#1ccfcf;   /* left teal */
      --grad-b:#189e9c;   /* middle teal */
      --grad-c:#2c8a88;   /* right teal */
      --pill:#ffffff;
      --shadow: 0 10px 24px rgba(0,0,0,.12);
      --text:#0b0b0b;
      --muted:#6b7280;
      --btn:#317a75;
      --btn-hover:#2a6b66;
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body{
      margin:0;
      color:#fff;
      background: linear-gradient(125deg, var(--grad-a), var(--grad-b) 45%, var(--grad-c));
      min-height:100vh;
      display:grid;
      place-items:center;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }

    /* Top chrome */
    .topbar{
      position: fixed; inset: 20px 24px auto 24px;
      display:flex; align-items:center; justify-content:space-between;
      pointer-events:none;  /* let clicks pass through except on links/icons */
    }
    .brand-left{ font-weight:600; line-height:1.15; letter-spacing:.2px; pointer-events:auto; }
    .brand-right{ display:flex; align-items:center; gap:18px; pointer-events:auto; }
    .hamburger{ width:28px; height:20px; position:relative; }
    .hamburger span, .hamburger::before, .hamburger::after{
      content:""; position:absolute; left:0; right:0; height:2px; background:#fff; border-radius:2px;
    }
    .hamburger span{ top:9px; }
    .hamburger::before{ top:0; }
    .hamburger::after{ bottom:0; }

    /* Center stack */
    .stack{
      width: min(92vw, 440px);
      display:flex; flex-direction:column; align-items:center;
      gap:14px;
    }

    .med-logo{
      width:54px; height:54px; border-radius:14px;
      display:grid; place-items:center;
      background: rgba(255,255,255,.18);
      box-shadow: 0 6px 16px rgba(0,0,0,.10) inset, 0 6px 14px rgba(0,0,0,.12);
      backdrop-filter: blur(2px);
    }
    .med-logo svg{ opacity:.95; }

    /* Input pills */
    .field-wrap{
      position: relative; width:100%;
    }
    .field{
      width:100%;
      height:56px;
      border:none;
      border-radius:28px;
      background: var(--pill);
      color: var(--text);
      padding: 0 20px 0 64px;
      box-shadow: var(--shadow);
      font-size:15.5px;
      outline: none;
    }
    .field::placeholder{ color:#9aa3ad; }
    .field:focus{ box-shadow: 0 0 0 3px rgba(255,255,255,.35), var(--shadow); }

    .icon{
      position:absolute; left:14px; top:50%; transform: translateY(-50%);
      width:36px; height:36px; border-radius:999px; background:#fff;
      display:grid; place-items:center; color:#7c8b95;
      border:1px solid #e6e9ee;
    }

    /* Button */
    .btn-login{
      width:100%;
      height:56px;
      border:none; border-radius:28px;
      background: var(--btn);
      color:#eaf9f8; font-weight:600; letter-spacing:.3px;
      box-shadow: var(--shadow);
      transition: filter .15s ease, transform .02s ease, background .2s ease;
    }
    .btn-login:hover{ background: var(--btn-hover); filter:brightness(1.02); }
    .btn-login:active{ transform: translateY(1px); }

    .messages{ width:min(92vw, 440px); margin-top:8px; }

    @media (max-width:560px){
      .brand-left{ font-size:15px; }
      .brand-right span{ display:none; }
    }
  </style>
</head>
<body>

  <!-- top bar -->
  <div class="topbar">
    <div class="brand-left">
      <div>Prenatal Care</div>
      <div>Information System</div>
    </div>
    <div class="brand-right">
      <span style="font-weight:600">RHU-MIS</span>
      <div class="hamburger" aria-label="menu" role="button" tabindex="0"><span></span></div>
    </div>
  </div>

  <!-- center login -->
  <div class="stack">
    <div class="med-logo" aria-hidden="true">
      <!-- medical cross with pulse -->
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
        <path d="M9 3h6a2 2 0 0 1 2 2v2h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-2v2a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h2V5a2 2 0 0 1 2-2z" fill="#7ee1dc" opacity=".85"/>
        <path d="M6.5 12h3l1.2-3 2.6 7 1.4-4h2.8" stroke="#0b6f69" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <form action="login.php" method="POST" style="width:100%;">
      <div class="field-wrap mb-3">
        <div class="icon"><i class="bi bi-person"></i></div>
        <input id="username" name="username" type="text" class="field" placeholder="Username" required autofocus>
      </div>
      <div class="field-wrap mb-3">
        <div class="icon"><i class="bi bi-lock"></i></div>
        <input id="password" name="password" type="password" class="field" placeholder="Password" required>
      </div>
      <button type="submit" class="btn-login">Login</button>
    </form>

    <!-- messages -->
    <div class="messages">
      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger py-2 px-3 mb-2">
          <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
      <?php endif; ?>
      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success py-2 px-3">
          <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // submit on Enter from any field (already default), but also support clicking the "hamburger" to focus user field
    document.querySelector('.hamburger')?.addEventListener('click', () => {
      document.getElementById('username')?.focus();
    });
  </script>
</body>
</html>
