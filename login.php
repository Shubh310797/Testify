<?php
session_start();
include 'config/db.php';

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = $_POST['username'];
    $password = md5($_POST['password']); // ✅ MD5 convert
    // ✅ username + password dono ek saath check karo
    $sql = "SELECT * FROM users WHERE username = ? AND password = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // ✅ INACTIVE CHECK — yahi fix hai
        if (isset($user['status']) && $user['status'] === 'inactive') {
            $error = "Aapka account inactive kar diya gaya hai. Admin se sampark karein.";
        } else {
            $_SESSION['user'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            // 🔀 Role-based redirect
            if ($user['role'] == 'admin') {
                header("Location: dash/admin_dash.php");
            } elseif ($user['role'] == 'developer') {
                header("Location: dash/user_dash.php");
            } elseif ($user['role'] == 'qa') {
                header("Location: dash/user_dash.php");
            } 
            exit();
        }

    } else {
        $error = "Invalid username or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TestiFy-Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/login.css"/>
</head>
<body>

  <!-- Background blobs -->
  <div class="bg-blob bg-blob-1"></div>
  <div class="bg-blob bg-blob-2"></div>
  <div class="bg-blob bg-blob-3"></div>

  <!-- Floating book SVGs -->
  <div class="float-icon" style="--rot:-15deg">
    <svg width="60" height="60" viewBox="0 0 40 40" fill="none">
      <rect x="4" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
      <rect x="8" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.1)"  stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
      <line x1="12" y1="14" x2="28" y2="14" stroke="rgba(255,255,255,.5)" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="19" x2="28" y2="19" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="24" x2="22" y2="24" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  </div>
  <div class="float-icon" style="--rot:20deg">
    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
      <rect x="4" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
      <rect x="8" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.1)"  stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
      <line x1="12" y1="14" x2="28" y2="14" stroke="rgba(255,255,255,.5)" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="19" x2="28" y2="19" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  </div>
  <div class="float-icon" style="--rot:-10deg">
    <svg width="35" height="35" viewBox="0 0 40 40" fill="none">
      <rect x="4" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
      <rect x="8" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.1)"  stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
      <line x1="12" y1="14" x2="28" y2="14" stroke="rgba(255,255,255,.5)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  </div>
  <div class="float-icon" style="--rot:15deg">
    <svg width="50" height="50" viewBox="0 0 40 40" fill="none">
      <rect x="4" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
      <rect x="8" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.1)"  stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
      <line x1="12" y1="14" x2="28" y2="14" stroke="rgba(255,255,255,.5)" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="19" x2="28" y2="19" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="24" x2="22" y2="24" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  </div>

  <!-- Paper plane -->
  <div class="plane">
    <svg width="36" height="36" viewBox="0 0 36 36" fill="none">
      <path d="M3 18L33 4L20 33L16 20L3 18Z" fill="rgba(255,255,255,.9)" stroke="rgba(255,255,255,.6)" stroke-width="1"/>
      <path d="M16 20L22 14" stroke="rgba(255,255,255,.6)" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
  </div>

  <!-- Main card -->
  <div class="card">

    <!-- Left branding panel -->
    <div class="panel-left">
      <div class="phone-wrap">
        <div class="phone">
          <div class="phone-badge"><span>Bug</span></div>
        </div>
        <div class="cloud"></div>
      </div>
      <h1>TestiFy</h1>
      <p>Welcome to the website</p>
      <div class="dots">
        <div class="dot active"></div>
        <div class="dot"></div>
        <div class="dot"></div>
      </div>
    </div>

    <!-- Right login panel -->
    <div class="panel-right">
      <h2>User Login</h2>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form method="POST" action="">

        <div class="input-group">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
            <circle cx="11" cy="7" r="4" stroke="#6b7fa3" stroke-width="1.5"/>
            <path d="M3 20c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#6b7fa3" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <input
            type="text"
            name="username"
            placeholder="Username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            autocomplete="username"
            required
          />
        </div>

        <div class="input-group">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
            <rect x="4" y="10" width="14" height="10" rx="2" stroke="#6b7fa3" stroke-width="1.5"/>
            <path d="M7 10V7a4 4 0 018 0v3" stroke="#6b7fa3" stroke-width="1.5" stroke-linecap="round"/>
            <circle cx="11" cy="15" r="1.5" fill="#6b7fa3"/>
          </svg>
          <input
            type="password"
            name="password"
            placeholder="Password"
            autocomplete="current-password"
            required
          />
        </div>

        <div class="row-remember">
          <label>
            <input
              type="checkbox"
              name="remember"
              <?= isset($_POST['remember']) ? 'checked' : '' ?>
            />
            Remember me
          </label>
          <a href="forgot.php" class="forgot">Forgot password?</a>
        </div>

        <button type="submit" class="btn-login">Login</button>

      </form>
    </div>

  </div>

</body>
</html>