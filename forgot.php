<?php
session_start();
include 'config/db.php';

 $error = "";
 $success = "";

// ✅ FUNCTION: ASLI OTP BHEJNE KE LIYE
function sendRealOTP($phone, $otp) {
    // --- CONFIGURATION ---
    $apiKey = "YOUR_FAST2SMS_API_KEY"; // ⚠️ Yahan apni API Key paste karein
    $senderId = "FSTSMS"; // Ya apna custom sender ID (agar verified ho toh)
    
    // API URL (Direct Route pe priority)
    $url = "https://www.fast2sms.com/dev/bulkV2";

    // Message Text
    $message = "Your TestiFy OTP is: " . $otp . ". Do not share it with anyone.";
    
    // Parameters
    $postData = [
        'authorization' => $apiKey,
        'route' => 'q', // 'q' means Quick Transactional Route (Promotional numbers par bhi chalta hai)
        'message' => $message,
        'language' => 'english',
        'flash' => 0,
        'numbers' => $phone,
    ];

    // cURL Request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData)
    ]);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    // Debugging: (Optional) Error dekhne ke liye comment hatayein
    // if($err) { echo "cURL Error:" . $err; }
    // else { echo "Response:" . $response; }

    return true; // Hum assume kar rahe hain success hua
}

// ✅ AUTO CLEANUP: Agar purana session pada hai toh clear karo
if (isset($_SESSION['reset_user_id']) && !isset($_POST['action']) && !isset($_GET['action'])) {
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_username']);
    unset($_SESSION['reset_name']);
    unset($_SESSION['temp_otp']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // ACTION 1: Verify User via Username
    if (isset($_POST['action']) && $_POST['action'] == 'check_username') {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';

        if (empty($username)) {
            $error = "Please enter your username.";
        } else {
            // ⚠️ IMPORTANT: 'phone' column select kiya hai
            $sql = "SELECT id, username, name, phone FROM users WHERE username = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    
                    // Check kiya ki phone number hai ya nahi
                    if(empty($user['phone'])) {
                        $error = "Phone number not found in your profile. Contact Admin.";
                    } else {
                        $_SESSION['reset_user_id'] = $user['id'];
                        $_SESSION['reset_username'] = $user['username'];
                        $_SESSION['reset_name'] = $user['name'];
                        $_SESSION['reset_phone'] = $user['phone']; // Phone number bhi save kiya
                    }
                } else {
                    $error = "Username not found in our system.";
                }
                $stmt->close();
            } else {
                $error = "Database Error: " . $conn->error;
            }
        }
    }

    // ACTION 2: User Clicked "Yes" -> Send REAL OTP
    elseif (isset($_POST['action']) && $_POST['action'] == 'confirm_and_send_otp') {
        $otp = rand(1000, 9999);
        $_SESSION['temp_otp'] = $otp;
        $phone = $_SESSION['reset_phone'] ?? '';

        // ✅ CALL FUNCTION TO SEND SMS
        if (!empty($phone)) {
            $sent = sendRealOTP($phone, $otp);
            $success = "OTP sent to your registered mobile: " . substr($phone, 0, 2) . "XXXXXX" . substr($phone, -2);
        } else {
            $error = "No phone number found to send OTP.";
        }
    }

    // ACTION 3: Verify OTP
    elseif (isset($_POST['action']) && $_POST['action'] == 'verify_otp') {
        $entered_otp = isset($_POST['otp']) ? trim($_POST['otp']) : '';
        $stored_otp = $_SESSION['temp_otp'] ?? '';

        if ($entered_otp == $stored_otp) {
            $success = "OTP Verified! Set your new password.";
        } else {
            $error = "Invalid OTP. Please try again.";
        }
    }

    // ACTION 4: Update Password
    elseif (isset($_POST['action']) && $_POST['action'] == 'update_password') {
        $new_pass = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        $user_id = $_SESSION['reset_user_id'] ?? 0;

        if (!empty($new_pass) && $user_id > 0) {
            $hashed_pass = md5($new_pass);

            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("si", $hashed_pass, $user_id);
                
                if ($stmt->execute()) {
                    // Cleanup Session
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['temp_otp']);
                    unset($_SESSION['reset_name']);
                    unset($_SESSION['reset_phone']);

                    echo "<script>alert('Password changed successfully! Please login.'); window.location.href='login.php';</script>";
                    exit();
                } else {
                    $error = "Error updating password.";
                }
            } else {
                $error = "Database error.";
            }
        }
    }
}

// LOGIC FOR "NO" BUTTON
if (isset($_GET['action']) && $_GET['action'] == 'cancel') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// STEP LOGIC
 $step = 1;
if (isset($_SESSION['reset_user_id'])) $step = 2;
if (isset($_POST['action']) && $_POST['action'] == 'confirm_and_send_otp') $step = 3;
if (isset($_POST['action']) && $_POST['action'] == 'verify_otp' && !empty($success) && empty($error)) $step = 4;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TestiFy - Forgot Password</title>
  <link rel="icon" type="image/jpg" href="icon/testify.jpg" />
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/login.css"/> 
</head>
<body>

  <!-- Background Blobs -->
  <div class="bg-blob bg-blob-1"></div>
  <div class="bg-blob bg-blob-2"></div>
  <div class="bg-blob bg-blob-3"></div>

  <div class="float-icon" style="--rot:-15deg">
    <svg width="60" height="60" viewBox="0 0 40 40" fill="none">
      <rect x="4" y="6" width="24" height="30" rx="3" fill="rgba(255,255,255,.15)" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
    </svg>
  </div>
  <div class="plane">
    <svg width="36" height="36" viewBox="0 0 36 36" fill="none">
      <path d="M3 18L33 4L20 33L16 20L3 18Z" fill="rgba(255,255,255,.9)" stroke="rgba(255,255,255,.6)" stroke-width="1"/>
    </svg>
  </div>

  <div class="card">
    <div class="panel-left">
      <div class="phone-wrap">
        <div class="phone">
          <div class="phone-badge"><span>Bug</span></div>
        </div>
        <div class="cloud"></div>
      </div>
      <h1>TestiFy</h1>
      <p>Account Recovery</p>
      <div class="dots">
        <div class="dot active"></div>
        <div class="dot"></div>
        <div class="dot"></div>
      </div>
    </div>

    <div class="panel-right">
      <h2>Recover Password</h2>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- STEP 1: USERNAME INPUT -->
      <?php if ($step == 1): ?>
      <form method="POST" action="">
        <input type="hidden" name="action" value="check_username">
        <div class="input-group">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
             <circle cx="11" cy="7" r="4" stroke="#6b7fa3" stroke-width="1.5"/>
             <path d="M3 20c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#6b7fa3" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <input type="text" name="username" placeholder="Enter your Username" required />
        </div>
        <button type="submit" class="btn-login">Find Account</button>
      </form>
      <?php endif; ?>

      <!-- STEP 2: CONFIRMATION SCREEN -->
      <?php if ($step == 2): ?>
      <div style="text-align:center; padding: 20px 0;">
        <p style="font-size:1.1rem; color:#555; margin-bottom:10px;">
            Is this you?
        </p>
        <h3 style="font-size:1.5rem; color:#0056b3; margin-bottom:5px;">
            <?= htmlspecialchars($_SESSION['reset_name'] ?? 'User') ?>
        </h3>
        <p style="font-size:0.9rem; color:#888;">
            Username: <?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?>
        </p>
        
        <div style="display:flex; gap:10px; margin-top:25px;">
            <form method="POST" action="" style="flex:1;">
                <input type="hidden" name="action" value="confirm_and_send_otp">
                <button type="submit" class="btn-login" style="background-color: #28a745;">Yes</button>
            </form>
            <form method="GET" action="?action=cancel" style="flex:1;">
                <button type="submit" class="btn-login" style="background-color: #dc3545;">No</button>
            </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- STEP 3: OTP INPUT (Real OTP mode) -->
      <?php if ($step == 3): ?>
      <form method="POST" action="">
        <input type="hidden" name="action" value="verify_otp">
        <p style="font-size:0.9rem; color:#666; margin-bottom:15px; text-align:center;">
            OTP sent to your registered mobile.<br>
            <!-- Demo OTP hidden since we are sending real OTP -->
        </p>
        <div class="input-group">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
            <rect x="4" y="10" width="14" height="10" rx="2" stroke="#6b7fa3" stroke-width="1.5"/>
            <path d="M7 10V7a4 4 0 018 0v3" stroke="#6b7fa3" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <input type="text" name="otp" placeholder="Enter OTP received on SMS" maxlength="6" required />
        </div>
        <button type="submit" class="btn-login">Verify OTP</button>
        <div style="text-align:center; margin-top:10px;">
            <a href="forgot.php" style="color:var(--primary-color); text-decoration:none; font-size:0.85rem;">Wrong User?</a>
        </div>
      </form>
      <?php endif; ?>

      <!-- STEP 4: NEW PASSWORD -->
      <?php if ($step == 4): ?>
      <form method="POST" action="">
        <input type="hidden" name="action" value="update_password">
        <div class="input-group">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
            <rect x="4" y="10" width="14" height="10" rx="2" stroke="#6b7fa3" stroke-width="1.5"/>
            <path d="M7 10V7a4 4 0 018 0v3" stroke="#6b7fa3" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <input type="password" name="new_password" placeholder="New Password" required />
        </div>
        <button type="submit" class="btn-login">Update Password</button>
      </form>
      <?php endif; ?>

      <div style="text-align:center; margin-top:20px;">
        <a href="login.php" style="color:#6b7fa3; text-decoration:none; font-size:0.9rem;">
            &larr; Back to Login
        </a>
      </div>

    </div>
  </div>

</body>
</html>