<?php
require_once __DIR__ . '/../inc/initialize.php';

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energie · Anmelden</title>
    <link rel="stylesheet" href="/energie/styles/style.css">
    <style>
        .login-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 60px);
            padding: 2rem;
        }
        .login-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 360px;
        }
        .login-card h2 {
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 0.35rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 0.95rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .btn-login {
            width: 100%;
            padding: 0.65rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .btn-login:hover { opacity: 0.9; }
        .alert {
            padding: 0.65rem 0.9rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-danger { background: #4a1a1a; color: #fc8181; border: 1px solid #742a2a; }
        .alert-info   { background: #1a2a4a; color: #63b3ed; border: 1px solid #2a4a6a; }
    </style>
</head>
<body>
<header>
    <span>⚡</span>
    <h1>Energie</h1>
</header>
<div class="login-wrap">
    <div class="login-card">
        <h2>Anmelden</h2>
        <?php foreach ($alerts as [$type, $msg]): ?>
            <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                <?= $msg ?>
            </div>
        <?php endforeach; ?>
        <form method="post" action="authentication.php">
            <?= csrf_input() ?>
            <div class="form-group">
                <label for="login-username">Benutzername</label>
                <input type="text" id="login-username" name="login-username"
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-password">Kennwort</label>
                <input type="password" id="login-password" name="login-password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Anmelden</button>
        </form>
    </div>
</div>
</body>
</html>
