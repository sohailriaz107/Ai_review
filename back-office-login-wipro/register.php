<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__.'/include/connect.php';
session_start();
$error = '';


// ---------- Register ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if ($name === '' || $email === '' || $pass === '') {
        $error = 'Please fill in all required fields.';
    } else {
        // Check if email already exists
        $check = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
        $check->bind_param('s', $email);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = 'Email already registered. Please login.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $mysqli->prepare('INSERT INTO users (name,email,password,phone) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $name, $email, $hash, $phone);
            if ($stmt->execute()) {
                // Regenerate session ID for security
                session_regenerate_id(true);
                $_SESSION['user_id'] = $stmt->insert_id;
                header('Location: index.php');
                exit;
            } else {
                $error = $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <div class="login-card">
        <h1>Register</h1>
        <p class="subtitle">AI - Review</p>
        <?php if (!empty($error)): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="name" placeholder="Full Name" required>
            </div>
            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="E‑mail" required>
            </div>
            <div class="input-group">
                <i class="fas fa-phone"></i>
                <input type="text" name="phone" placeholder="Phone (optional)" >
            </div>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Password" required>
            </div>
            <button type="submit">Register</button>
        </form>
        <p>Already have an account? <a href="login.php">Login</a></p>
    </div>
</body>
</html>
