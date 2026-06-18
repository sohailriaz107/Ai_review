<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/include/connect.php';
require_once __DIR__.'/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $bid = intval($_POST['business_id'] ?? 0);
        // Delete QR image
        $stmt = $mysqli->prepare("SELECT qr_image FROM businesses WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $bid, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($b = $res->fetch_assoc()) {
            if (!empty($b['qr_image']) && file_exists(__DIR__ . '/' . $b['qr_image'])) {
                @unlink(__DIR__ . '/' . $b['qr_image']);
            }
        }
        $stmt->close();
        
        $del_tokens = $mysqli->prepare("DELETE FROM review_tokens WHERE business_id = ? AND user_id = ?");
        $del_tokens->bind_param('ii', $bid, $user_id);
        $del_tokens->execute();
        
        $del_stmt = $mysqli->prepare("DELETE FROM businesses WHERE id = ? AND user_id = ?");
        $del_stmt->bind_param('ii', $bid, $user_id);
        if ($del_stmt->execute()) {
            $success = "Business deleted successfully.";
        } else {
            $error = "Failed to delete business.";
        }
    } 
    elseif ($action === 'add' || $action === 'edit') {
        $bid = intval($_POST['business_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $subcategory = trim($_POST['subcategory'] ?? '');
        $google_review_link = trim($_POST['google_review_link'] ?? '');
        
        if ($name === '' || $category === '' || $google_review_link === '') {
            $error = "Name, Category, and Google Review Link are required.";
        } else {
            if ($action === 'add') {
                $insert_stmt = $mysqli->prepare("INSERT INTO businesses (user_id, name, city, category, subcategory, google_review_link) VALUES (?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param('isssss', $user_id, $name, $city, $category, $subcategory, $google_review_link);
                if ($insert_stmt->execute()) {
                    $bid = $mysqli->insert_id;
                    
                    // Generate token
                    $user_token = sprintf('%012d', mt_rand(100000000000, 999999999999));
                    $ins_token = $mysqli->prepare("INSERT INTO review_tokens (user_id, token, business_id) VALUES (?, ?, ?)");
                    $ins_token->bind_param('isi', $user_id, $user_token, $bid);
                    $ins_token->execute();
                    
                    // Generate QR
                    try {
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                        $domain = $_SERVER['HTTP_HOST'];
                        $review_url = $protocol . "://" . $domain . "/AI-review/review/" . $user_token;

                        $qrCode = new QrCode($review_url);
                        $writer = new PngWriter();
                        $qrResult = $writer->write($qrCode);
                        
                        $filename = 'qr_' . $user_id . '_' . $bid . '_' . time() . '.png';
                        $filepath = __DIR__ . '/uploads/qrcodes/' . $filename;
                        if (!is_dir(__DIR__ . '/uploads/qrcodes/')) {
                            mkdir(__DIR__ . '/uploads/qrcodes/', 0777, true);
                        }
                        $qrResult->saveToFile($filepath);
                        $qr_image_path = 'uploads/qrcodes/' . $filename;
                        
                        $upd_qr = $mysqli->prepare("UPDATE businesses SET qr_image = ? WHERE id = ?");
                        $upd_qr->bind_param('si', $qr_image_path, $bid);
                        $upd_qr->execute();
                    } catch (Exception $e) {
                        $error = "Business added but QR code failed: " . $e->getMessage();
                    }
                    if (!$error) $success = "Business added successfully.";
                } else {
                    $error = "Error adding business.";
                }
            } elseif ($action === 'edit' && $bid > 0) {
                $update_stmt = $mysqli->prepare("UPDATE businesses SET name=?, city=?, category=?, subcategory=?, google_review_link=? WHERE id=? AND user_id=?");
                $update_stmt->bind_param('sssssii', $name, $city, $category, $subcategory, $google_review_link, $bid, $user_id);
                if ($update_stmt->execute()) {
                    $success = "Business updated successfully.";
                } else {
                    $error = "Error updating business.";
                }
            }
        }
    }
}

// Fetch all businesses
$stmt = $mysqli->prepare("SELECT b.*, t.token FROM businesses b LEFT JOIN review_tokens t ON b.id = t.business_id WHERE b.user_id = ? ORDER BY b.id DESC");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$businesses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$categories = [
    "Food & Beverage", "Retail & Shopping", "Beauty & Personal Care", "Healthcare & Medical",
    "Professional Services", "IT & Technology", "Automobile", "Home & Construction",
    "Education & Training", "Travel & Hospitality", "Events & Media", "Fitness & Sports",
    "Manufacturing & Wholesale", "Pet & Animal Services", "Local Services", "Lifestyle & Misc"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Businesses – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; color: #333; font-weight: 600; }
        td { color: #555; }
        .action-btn { padding: 8px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; color: #fff; text-decoration: none; display: inline-block; }
        .btn-edit { background: #ffc107; color: #333; }
        .btn-del { background: #dc3545; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center; }
        .modal-content { background: #fff; width: 100%; max-width: 500px; padding: 25px; border-radius: 16px; position: relative;margin:10px; }
        .close-modal { position: absolute; top: 15px; right: 20px; font-size: 1.5rem; cursor: pointer; color: #888; }
        .add-btn { background: #1890ff; color: white; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; font-size: 1rem; font-weight: 600; margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; }
    </style>
</head>
<body class="admin">
    <?php include 'include/sidebar.php'; ?>
    <main class="content">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <h1>Manage Your Businesses</h1>
                <p>Add and manage multiple business profiles.</p>
            </div>
            <button class="add-btn" onclick="openModal('add')"><i class="fas fa-plus"></i> Add Business</button>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="alert success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>City</th>
                        <th>QR Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($businesses) > 0): ?>
                        <?php foreach($businesses as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['name']) ?></strong><br><small style="color:#888;">Token: <?= htmlspecialchars($b['token'] ?? 'N/A') ?></small></td>
                            <td><?= htmlspecialchars($b['category']) ?></td>
                            <td><?= htmlspecialchars($b['city']) ?></td>
                            <td>
                                <?php if ($b['qr_image']): ?>
                                    <img src="<?= htmlspecialchars($b['qr_image']) ?>" width="50" style="border-radius:4px;border:1px solid #ddd; margin-bottom: 5px;">
                                    <br>
                                    <a href="<?= htmlspecialchars($b['qr_image']) ?>" download="QRCode_<?= htmlspecialchars($b['name']) ?>.png" style="font-size: 0.85rem; color: #1890ff; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-download"></i> Download</a>
                                <?php else: ?>
                                    <span style="color:#aaa;">No QR</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="action-btn btn-edit" onclick="openModal('edit', <?= htmlspecialchars(json_encode($b)) ?>)"><i class="fas fa-edit"></i> Edit</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this business?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="business_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="action-btn btn-del"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No businesses added yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal -->
    <div class="modal" id="businessModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-bottom: 20px; color: #111;">Add Business</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="business_id" id="businessId" value="0">
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Business Name *</label>
                    <input type="text" name="name" id="bName" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">City</label>
                    <input type="text" name="city" id="bCity" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Category *</label>
                        <select name="category" id="bCategory" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Select</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Subcategory</label>
                        <select name="subcategory" id="bSubcategory" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Select</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 25px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Google Review Link *</label>
                    <input type="url" name="google_review_link" id="bGoogle" required placeholder="https://..." style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>

                <button type="submit" class="btn-save" style="border-radius: 8px;">Save Business</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(action, data = null) {
            document.getElementById('businessModal').style.display = 'flex';
            document.getElementById('formAction').value = action;
            if (action === 'edit' && data) {
                document.getElementById('modalTitle').innerText = 'Edit Business';
                document.getElementById('businessId').value = data.id;
                document.getElementById('bName').value = data.name;
                document.getElementById('bCity').value = data.city;
                document.getElementById('bCategory').value = data.category;
                document.getElementById('bSubcategory').value = data.subcategory;
                document.getElementById('bGoogle').value = data.google_review_link;
            } else {
                document.getElementById('modalTitle').innerText = 'Add Business';
                document.getElementById('businessId').value = '0';
                document.getElementById('bName').value = '';
                document.getElementById('bCity').value = '';
                document.getElementById('bCategory').value = '';
                document.getElementById('bSubcategory').value = '';
                document.getElementById('bGoogle').value = '';
            }
        }
        function closeModal() {
            document.getElementById('businessModal').style.display = 'none';
        }
    </script>
</body>
</html>
