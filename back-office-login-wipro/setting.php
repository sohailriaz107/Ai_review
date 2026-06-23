<?php
session_start();
require_once __DIR__.'/../include/connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = $_SESSION['user_id'];
$success = '';
$error = '';
$pass_success = '';
$pass_error = '';

// Fetch current user info
$u_stmt = $mysqli->prepare("SELECT name, email, phone FROM users WHERE id=?");
$u_stmt->bind_param('i', $uid);
$u_stmt->execute();
$u_stmt->bind_result($uname, $uemail, $uphone);
$u_stmt->fetch();
$u_stmt->close();

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');

    if ($new_name === '' || $new_email === '') {
        $error = "Name and Email are required.";
    } else {
        $chk = $mysqli->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $chk->bind_param('si', $new_email, $uid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error = "This email is already in use by another account.";
        } else {
            $upd = $mysqli->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?");
            $upd->bind_param('sssi', $new_name, $new_email, $new_phone, $uid);
            if ($upd->execute()) {
                $success = "Profile updated successfully!";
                $uname = $new_name;
                $uemail = $new_email;
                $uphone = $new_phone;
            } else {
                $error = "Failed to update profile.";
            }
        }
        $chk->close();
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if ($current_pass === '' || $new_pass === '' || $confirm_pass === '') {
        $pass_error = "All password fields are required.";
    } elseif ($new_pass !== $confirm_pass) {
        $pass_error = "New passwords do not match.";
    } elseif (strlen($new_pass) < 6) {
        $pass_error = "New password must be at least 6 characters.";
    } else {
        $pw_stmt = $mysqli->prepare("SELECT password FROM users WHERE id=?");
        $pw_stmt->bind_param('i', $uid);
        $pw_stmt->execute();
        $pw_stmt->bind_result($hashed_pass);
        $pw_stmt->fetch();
        $pw_stmt->close();

        if (!password_verify($current_pass, $hashed_pass)) {
            $pass_error = "Current password is incorrect.";
        } else {
            $new_hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd_pw = $mysqli->prepare("UPDATE users SET password=? WHERE id=?");
            $upd_pw->bind_param('si', $new_hashed, $uid);
            if ($upd_pw->execute()) {
                $pass_success = "Password changed successfully!";
            } else {
                $pass_error = "Failed to change password.";
            }
            $upd_pw->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .settings-container {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 30px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .page-title {
            grid-column: 1 / -1;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e5e5;
        }

        .page-title h1 {
            margin: 0 0 5px;
            font-size: 1.8rem;
            color: #111;
            
        }
        .page-title p {
            margin: 0;
            color: #777;
        }

        /* Profile Sidebar Card */
        .profile-sidebar {
            background: #fff;
            border-radius: 16px;
            padding: 40px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            text-align: center;
            height: fit-content;
            position: relative;
            overflow: hidden;
        }
        
        .profile-sidebar::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 120px;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            z-index: 0;
        }

        .profile-avatar-wrap {
            position: relative;
            z-index: 1;
            margin: 0 auto 20px;
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: #fff;
            padding: 5px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.8rem;
            color: #0d6efd;
            font-weight: 700;
        }

        .profile-sidebar h2 {
            position: relative;
            z-index: 1;
            margin: 0 0 5px;
            color: #222;
            font-size: 1.4rem;
        }

        .profile-badge {
            position: relative;
            z-index: 1;
            display: inline-block;
            background: #e6f7ff;
            color: #0d6efd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        .profile-contact {
            position: relative;
            z-index: 1;
            text-align: center;
            border-top: 1px dashed #eee;
        }

        .profile-contact p {
            margin: 0 0 12px;
            color: #555;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-contact p i {
            color: #0d6efd;
            width: 18px;
            text-align: center;
        }

        /* Forms Area */
        .form-card {
            background: #fff;
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            margin-bottom: 30px;
        }

        .form-card h3 {
            margin: 0 0 25px;
            font-size: 1.25rem;
            color: #111;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .form-card h3 i {
            color: #0d6efd;
            background: #f0f7ff;
            padding: 8px;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #333;
            background: #fafafa;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #0d6efd;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13,110,253,0.1);
        }

        .btn-submit {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(13,110,253,0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .btn-danger:hover {
            box-shadow: 0 6px 15px rgba(220,53,69,0.3);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-error { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }

        @media (max-width: 992px) {
            .settings-container { grid-template-columns: 1fr; }
            .profile-sidebar { max-width: 400px; margin: 0 auto; width: 100%; }
             .page-title h1 {
            margin: 0 0 5px;
            font-size: 1.8rem;
            color: #111;
            text-align: center;
            
        }
        .page-title p {
            margin: 0;
            color: #777;
            text-align: center;
        }
        }
        @media (max-width: 576px) {
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body class="admin">
    <?php include '../include/sidebar.php'; ?>
    <main class="content">
        <div class="settings-container">
            
            <div class="page-title">
                <h1>Settings</h1>
                <p>Manage your account profile and security preferences.</p>
            </div>

            <!-- Left Sidebar Profile -->
            <div>
                <div class="profile-sidebar">
                    <div class="profile-avatar-wrap">
                        <div class="profile-avatar">
                            <?= strtoupper(substr($uname, 0, 1)) ?>
                        </div>
                    </div>
                    <h2><?= htmlspecialchars($uname) ?></h2>
                    <span class="profile-badge">Administrator</span>
                    
                    <div class="profile-contact">
                        <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($uemail) ?></p>
                        <?php if ($uphone): ?>
                            <p><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($uphone) ?></p>
                        <?php endif; ?>
                        <p><i class="fas fa-shield-alt"></i> Secure Account</p>
                    </div>
                </div>
            </div>

            <!-- Right Forms Area -->
            <div>
                <!-- Personal Info Card -->
                <div class="form-card">
                    <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($uname) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($uemail) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Phone Number <span style="color:#aaa;font-weight:400;font-size:0.8rem;">(Optional)</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($uphone ?? '') ?>" placeholder="e.g. +92 300 1234567">
                        </div>

                        <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Profile</button>
                    </form>
                </div>

                <!-- Password Card -->
                <div class="form-card">
                    <h3><i class="fas fa-lock"></i> Security & Password</h3>
                    
                    <?php if (!empty($pass_success)): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $pass_success ?></div>
                    <?php endif; ?>
                    <?php if (!empty($pass_error)): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $pass_error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-control" required placeholder="Minimum 6 characters">
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat new password">
                            </div>
                        </div>

                        <button type="submit" class="btn-submit btn-danger"><i class="fas fa-key"></i> Update Password</button>
                    </form>
                </div>
            </div>

        </div>
    </main>
</body>
</html>
