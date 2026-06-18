<?php
require_once __DIR__.'/include/connect.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Handle AJAX Save Settings within this file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_settings') {
    header('Content-Type: application/json');
    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    $keywords = $data['keywords'] ?? '';
    $languages = $data['languages'] ?? '';
    $default_tone = $data['default_tone'] ?? '';

    $stmt = $mysqli->prepare("SELECT id FROM businesses WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $update_stmt = $mysqli->prepare("UPDATE businesses SET keywords=?, languages=?, default_tone=? WHERE user_id=?");
        $update_stmt->bind_param('sssi', $keywords, $languages, $default_tone, $user_id);
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Data saved successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Please configure your business first on the Business page.']);
    }
    exit;
}

/*  Get user name and business info  */
$stmt = $mysqli->prepare("SELECT u.name as uname, b.name as bname, b.category, b.qr_image, b.google_review_link, b.keywords, b.languages, b.default_tone 
                          FROM users u 
                          LEFT JOIN businesses b ON u.id = b.user_id 
                          WHERE u.id=? ORDER BY b.id DESC LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($uname, $bname, $bcategory, $qr_image, $google_link, $db_keywords, $db_languages, $db_tone);
$stmt->fetch();

$active_langs = explode(',', $db_languages ?? 'English');
$db_tone = $db_tone ?? 'Friendly';

// Close the previous statement to prevent "Commands out of sync"
$stmt->close();

// Get user token
$user_token = '';
$token_stmt = $mysqli->prepare("SELECT token FROM review_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$token_stmt->bind_param('i', $_SESSION['user_id']);
$token_stmt->execute();
$token_res = $token_stmt->get_result();
if ($token_row = $token_res->fetch_assoc()) {
    $user_token = $token_row['token'];
}
$token_stmt->close();

// Fallback: If user has a business but no token yet, generate one
if (!empty($bname) && empty($user_token)) {
    $user_token = sprintf('%012d', mt_rand(100000000000, 999999999999));
    $get_b = $mysqli->prepare("SELECT id FROM businesses WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $get_b->bind_param('i', $_SESSION['user_id']);
    $get_b->execute();
    $b_res = $get_b->get_result();
    if ($b_row = $b_res->fetch_assoc()) {
        $bid = $b_row['id'];
        $ins = $mysqli->prepare("INSERT INTO review_tokens (user_id, business_id, token) VALUES (?, ?, ?)");
        $ins->bind_param('iis', $_SESSION['user_id'], $bid, $user_token);
        $ins->execute();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="admin">
    <?php include 'include/sidebar.php'; ?>
    <main class="content">
        <!-- Top Promo Banner could go here -->
        
        <div class="dashboard-grid">
            <!-- Left Side: QR Code Section -->
            <div class="dash-card qr-section">
                <div style="color: #1890ff; font-size: 3rem; margin-bottom: 10px;">
                    <i class="fas fa-comment-dots"></i>
                </div>
                <h3>Ai-Review</h3>
                <p style="color: #888; font-size: 0.9rem; margin-bottom: 20px;">AI-Powered Review Assistant</p>
                
                <?php if (!empty($qr_image)): ?>
                    <img src="<?= htmlspecialchars($qr_image) ?>" alt="QR Code">
                    <br>
                    <a href="<?= htmlspecialchars($qr_image) ?>" download="QRCode_<?= htmlspecialchars($bname) ?>.png" style="display:inline-block; margin-top:10px; padding:8px 15px; background:#1890ff; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;"><i class="fas fa-download"></i> Download QR</a>
                <?php else: ?>
                    <div style="padding: 40px 20px; background: #f5f5f5; border-radius: 12px; margin-bottom: 15px;">
                        <i class="fas fa-qrcode" style="font-size: 3rem; color: #ccc; margin-bottom: 10px;"></i>
                        <p style="color: #888; font-size: 0.9rem;">No QR Code generated yet.<br><a href="business.php">Configure Business</a></p>
                    </div>
                <?php endif; ?>
                
                <p style="margin-top: 15px; font-weight: 500;">Scan to Leave a Review ⭐</p>
                <h2 style="margin: 10px 0; color: #111;"><?= !empty($bname) ? htmlspecialchars($bname) : 'Your Business Name' ?></h2>
                <p style="color: #888; font-size: 0.9rem;"><?= !empty($bcategory) ? htmlspecialchars($bcategory) : 'Category' ?></p>
                
                <?php
                // Determine base URL dynamically
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $domain = $_SERVER['HTTP_HOST'];
                if ($user_token) {
                    $review_url_link = $protocol . "://" . $domain . "/AI-review/review/" . $user_token;
                } else {
                    $review_url_link = $protocol . "://" . $domain . "/AI-review/review-generation.php?id=" . $_SESSION['user_id'];
                }
                ?>
                <p style="margin-top: 15px; font-weight: 600; cursor: pointer;" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($review_url_link) ?>'); alert('Link copied!');">Copy Url <i class="far fa-copy"></i></p>
                
                <div class="warning">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> Request reviews only from genuine customers who have visited your business or used your services. Do not share your review QR code or link with friends, family, employees, or anyone without a real customer experience. Non-genuine reviews may be removed by Google and could impact your Business Profile.
                </div>
            </div>

            <!-- Right Side: Settings Section -->
            <div class="settings-section">
                <div class="dash-card" style="position: relative;">
                    <div id="section-msg" style="display:none; position: absolute; top: 25px; right: 25px; background: #28a745; color: white; padding: 6px 15px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; box-shadow: 0 4px 10px rgba(40, 167, 69, 0.2);">
                        <i class="fas fa-check-circle"></i> Data saved successfully
                    </div>
                    <h3>Keywords</h3>
                    <p style="color: #888; font-size: 0.85rem; margin-bottom: 10px;">Add At least 2-3 Keyword for Better Ranking.</p>
                    <div class="input-group" style="margin:0;">
                        <input type="text" id="keywords-input" placeholder="cafe,food,jaipur,best cafe" value="<?= htmlspecialchars($db_keywords ?? '') ?>" style="padding-left: 15px;">
                    </div>
                </div>

                <div class="dash-card">
                    <h3>Choose Language</h3>
                    <div class="pills-group" id="language-pills">
                        <button type="button" class="pill <?= in_array('English', $active_langs) ? 'active' : '' ?>">English</button>
                        <button type="button" class="pill <?= in_array('Hindi', $active_langs) ? 'active' : '' ?>">Hindi</button>
                        <button type="button" class="pill <?= in_array('Hinglish', $active_langs) ? 'active' : '' ?>">Hinglish</button>
                    </div>
                </div>

                <div class="dash-card">
                    <h3>Default Tone</h3>
                    <div class="pills-group" id="tone-pills">
                        <button type="button" class="pill <?= $db_tone === 'Professional' ? 'active' : '' ?>">Professional <?= $db_tone === 'Professional' ? '<i class="fas fa-check"></i>' : '' ?></button>
                        <button type="button" class="pill <?= $db_tone === 'Friendly' ? 'active' : '' ?>">Friendly <?= $db_tone === 'Friendly' ? '<i class="fas fa-check"></i>' : '' ?></button>
                        <button type="button" class="pill <?= $db_tone === 'Enthusiastic' ? 'active' : '' ?>">Enthusiastic <?= $db_tone === 'Enthusiastic' ? '<i class="fas fa-check"></i>' : '' ?></button>
                    </div>
                </div>

                <button id="btn-save-settings" class="btn-save">Save</button>
            </div>
        </div>
       
    </main>

    <script>
        // Language Pills: Multi-select
        const langPills = document.querySelectorAll('#language-pills .pill');
        langPills.forEach(pill => {
            pill.addEventListener('click', function() {
                this.classList.toggle('active');
            });
        });

        // Tone Pills: Single-select
        const tonePills = document.querySelectorAll('#tone-pills .pill');
        tonePills.forEach(pill => {
            pill.addEventListener('click', function() {
                // Remove active class and checkmark from all tone pills
                tonePills.forEach(p => {
                    p.classList.remove('active');
                    const icon = p.querySelector('i.fa-check');
                    if (icon) icon.remove();
                });
                
                // Add active class and checkmark to the clicked one
                this.classList.add('active');
                this.innerHTML += ' <i class="fas fa-check"></i>';
            });
        });

        // AJAX Save Settings
        document.getElementById('btn-save-settings').addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            btn.disabled = true;

            const keywords = document.getElementById('keywords-input').value;
            
            const activeLangs = [];
            document.querySelectorAll('#language-pills .pill.active').forEach(p => {
                activeLangs.push(p.innerText.trim());
            });

            const activeToneEl = document.querySelector('#tone-pills .pill.active');
            const defaultTone = activeToneEl ? activeToneEl.innerText.trim() : 'Friendly';

            fetch('index.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ keywords: keywords, languages: activeLangs.join(','), default_tone: defaultTone })
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                if (data.success) {
                    const msg = document.getElementById('section-msg');
                    msg.style.display = 'block';
                    setTimeout(() => {
                        msg.style.display = 'none';
                    }, 2000);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('An error occurred while saving.');
            });
        });
    </script>
</body>
</html>
