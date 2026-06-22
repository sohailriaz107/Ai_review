<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/../include/connect.php';
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
        
        // Delete business_settings
        $del_settings = $mysqli->prepare("DELETE FROM business_settings WHERE business_id = ?");
        $del_settings->bind_param('i', $bid);
        $del_settings->execute();
        
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
        $location = trim($_POST['location'] ?? '');
        $no_review = intval($_POST['no_review'] ?? 3);
        $category = trim($_POST['category'] ?? '');
        $subcategory = trim($_POST['subcategory'] ?? '');
        $google_review_link = trim($_POST['google_review_link'] ?? '');
        $allow_duplicate = isset($_POST['allow_duplicate']) ? 1 : 0;
        $keywords = trim($_POST['keywords'] ?? '');
        $languages = trim($_POST['languages'] ?? 'English');
        $default_tone = trim($_POST['default_tone'] ?? 'Friendly');
        
        if ($name === '' || $category === '' || $google_review_link === '') {
            $error = "Name, Category, and Google Review Link are required.";
        } else {
            if ($action === 'add') {
                $insert_stmt = $mysqli->prepare("INSERT INTO businesses (user_id, name, city, location, no_review, category, subcategory, google_review_link, keywords, languages, default_tone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param('isssissssss', $user_id, $name, $city, $location, $no_review, $category, $subcategory, $google_review_link, $keywords, $languages, $default_tone);
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
                        $filepath = __DIR__ . '/../review/uploads/qrcodes/' . $filename;
                        if (!is_dir(__DIR__ . '/../review/uploads/qrcodes/')) {
                            mkdir(__DIR__ . '/../review/uploads/qrcodes/', 0777, true);
                        }
                        $qrResult->saveToFile($filepath);
                        // Store absolute path from web root for correct download link
                        $qr_image_path = '/AI-review/review/uploads/qrcodes/' . $filename;
                        
                        $upd_qr = $mysqli->prepare("UPDATE businesses SET qr_image = ? WHERE id = ?");
                        $upd_qr->bind_param('si', $qr_image_path, $bid);
                        $upd_qr->execute();
                    } catch (Exception $e) {
                        $error = "Business added but QR code failed: " . $e->getMessage();
                    }
                    // Insert business_settings
                    $ins_settings = $mysqli->prepare("INSERT INTO business_settings (business_id, allow_duplicate, created_at) VALUES (?, ?, NOW())");
                    $ins_settings->bind_param('ii', $bid, $allow_duplicate);
                    $ins_settings->execute();
                    
                    if (!$error) $success = "Business added successfully.";
                } else {
                    $error = "Error adding business.";
                }
            } elseif ($action === 'edit' && $bid > 0) {
                $update_stmt = $mysqli->prepare("UPDATE businesses SET name=?, location=?, no_review=?, city=?, category=?, subcategory=?, google_review_link=?, keywords=?, languages=?, default_tone=? WHERE id=? AND user_id=?");
                $update_stmt->bind_param('ssisssssssii', $name, $location, $no_review, $city, $category, $subcategory, $google_review_link, $keywords, $languages, $default_tone, $bid, $user_id);
                if ($update_stmt->execute()) {
                    // Update or insert business_settings
                    $chk_s = $mysqli->prepare("SELECT id FROM business_settings WHERE business_id = ?");
                    $chk_s->bind_param('i', $bid);
                    $chk_s->execute();
                    $chk_s->store_result();
                    if ($chk_s->num_rows > 0) {
                        $upd_s = $mysqli->prepare("UPDATE business_settings SET allow_duplicate = ?, updated_at = NOW() WHERE business_id = ?");
                        $upd_s->bind_param('ii', $allow_duplicate, $bid);
                        $upd_s->execute();
                    } else {
                        $ins_s = $mysqli->prepare("INSERT INTO business_settings (business_id, allow_duplicate, created_at) VALUES (?, ?, NOW())");
                        $ins_s->bind_param('ii', $bid, $allow_duplicate);
                        $ins_s->execute();
                    }
                    $chk_s->close();
                    $success = "Business updated successfully.";
                } else {
                    $error = "Error updating business.";
                }
            }
        }
    }
}

// Fetch all businesses
$stmt = $mysqli->prepare("SELECT b.*, t.token, IFNULL(bs.allow_duplicate, 0) as allow_duplicate FROM businesses b LEFT JOIN review_tokens t ON b.id = t.business_id LEFT JOIN business_settings bs ON b.id = bs.business_id WHERE b.user_id = ? ORDER BY b.id DESC");
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
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; color: #333; font-weight: 600; }
        td { color: #555; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center; }
        .modal-content { background: #fff; width: 100%; max-width: 750px; padding: 30px; border-radius: 16px; position: relative; margin:10px; max-height: 90vh; overflow-y: auto; scrollbar-width: none; }
        .modal-content::-webkit-scrollbar { display: none; }
        .close-modal { position: absolute; top: 15px; right: 20px; font-size: 1.5rem; cursor: pointer; color: #888; }
        .add-btn { background: #1890ff; color: white; padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; font-size: 1rem; font-weight: 600; margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; }

        .action-wrapper { position: relative; display: inline-block; }
        .dots-btn { background: none; border: 1px solid #e0e0e0; border-radius: 8px; padding: 8px 12px; cursor: pointer; font-size: 1.1rem; color: #555; transition: all 0.2s; }
        .dots-btn:hover { background: #f5f5f5; color: #333; }
        .action-dropdown { display: none; position: fixed; background: #fff; border-radius: 10px; box-shadow: 0 8px 25px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px; overflow: hidden; }
        .action-dropdown.show { display: block; }
        .action-dropdown a, .action-dropdown button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 12px 16px; border: none; background: none; text-align: left; font-size: 0.9rem; cursor: pointer; color: #333; text-decoration: none; transition: background 0.15s; font-family: 'Inter', sans-serif; }
        .action-dropdown a:hover, .action-dropdown button:hover { background: #f5f7fa; }
        .action-dropdown .delete-action { color: #dc3545; }
        .action-dropdown .delete-action:hover { background: #fff5f5; }

        /* Custom Table Controls */
        .table-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px; }
        .table-controls .length-menu { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #555; border: 1px solid #ddd; padding: 6px 12px; border-radius: 8px; }
        .table-controls select, .table-controls input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; outline: none; transition: 0.2s; }
        .table-controls select:focus, .table-controls input:focus { border-color: #1890ff; box-shadow: 0 0 0 3px rgba(24,144,255,0.1); }
        .table-controls .search-box { display: flex; align-items: center; gap: 8px; }
        
        .pagination-controls { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; font-size: 0.9rem; color: #555; flex-wrap: wrap; gap: 15px; }
        .pagination-btns { display: flex; gap: 5px; }
        .pagination-btns button { padding: 6px 12px; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer; transition: 0.2s; }
        .pagination-btns button:hover:not(:disabled) { background: #f0f0f0; }
        .pagination-btns button.active { background: #1890ff; color: #fff; border-color: #1890ff; }
        .pagination-btns button:disabled { opacity: 0.5; cursor: not-allowed; }

        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #eee; }
        th.sortable i { margin-left: 5px; color: #aaa; font-size: 0.8rem; }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: center !important; text-align: center; gap: 15px; }
            .page-header .add-btn { justify-content: center; }
            
            .table-controls { flex-direction: column; align-items: stretch; }
            .table-controls .length-menu { justify-content: center; }
            .table-controls .search-box input { width: 100%; }
            .pagination-controls { flex-direction: column; align-items: center; }
        }

        /* Pills Styling */
        .pills-group { display: flex; flex-wrap: wrap; gap: 10px;justify-content: center;
  margin: 15px; }
        .pill { padding: 8px 16px; border: 1px solid #ddd; border-radius: 20px; background: #fff; cursor: pointer; font-size: 0.9rem; transition: all 0.2s; color: #555; }
        .pill.active { background: #1890ff; color: #fff; border-color: #1890ff; }
    </style>
</head>
<body class="admin">
    <?php include '../include/sidebar.php'; ?>
    <main class="content">
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
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
        
        <div class="table-responsive" style="padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-top: 20px;">
            <div class="table-controls">
                <div class="length-menu">
                    Show 
                    <select id="pageSize">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="-1">All</option>
                    </select>
                    entries
                </div>
                <div class="search-box">
                    <label>Search:</label>
                    <input type="text" id="searchInput" placeholder="Search businesses...">
                </div>
            </div>
            
            <table id="businessTable" style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th class="sortable" data-col="0">Name <i class="fas fa-sort"></i></th>
                        <th class="sortable" data-col="1">Category <i class="fas fa-sort"></i></th>
                        <th class="sortable" data-col="2">City <i class="fas fa-sort"></i></th>
                        <th class="sortable" data-col="3">No Review <i class="fas fa-sort"></i></th>
                        <th class="sortable" data-col="4">Location <i class="fas fa-sort"></i></th>
                        <th>QR Code</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (count($businesses) > 0): ?>
                        <?php foreach($businesses as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['name']) ?></strong><br><small style="color:#888;">Token: <?= htmlspecialchars($b['token'] ?? 'N/A') ?></small></td>
                            <td><?= htmlspecialchars($b['category'] ?? '') ?></td>
                            <td><?= htmlspecialchars($b['city'] ?? '') ?></td>
                            <td><?= htmlspecialchars($b['no_review'] ?? '') ?></td>
                        <td><?= htmlspecialchars($b['location'] ?? '') ?></td>
                            <td>
                                <?php if ($b['qr_image']): ?>
                                    <a href="<?= htmlspecialchars($b['qr_image']) ?>" target="_blank" title="Click to view full size">
                                     
                                    </a>
                                    <br>
                                    <a href="<?= htmlspecialchars($b['qr_image']) ?>" download="QRCode_<?= htmlspecialchars($b['name']) ?>.png" style="font-size: 0.85rem; color:red; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-download"></i> Download</a>
                                    <br>
                                    <?php 
                                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                        $domain = $_SERVER['HTTP_HOST'];
                                        $copy_url = $protocol . "://" . $domain . "/AI-review/review/" . urlencode($b['token'] ?? '');
                                    ?>
                                    <a href="javascript:void(0)" onclick="navigator.clipboard.writeText('<?= $copy_url ?>'); alert('URL Copied!');" style="font-size: 0.85rem; color: #1890ff; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-top: 5px;"><i class="fas fa-copy"></i> Copy URL</a>
                                <?php else: ?>
                                    <span style="color:#aaa;">No QR</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-wrapper">
                                    <button class="dots-btn" onclick="toggleDropdown(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                                    <div class="action-dropdown">
                                        <a href="#" onclick="event.preventDefault(); closeAllDropdowns(); openModal('edit', <?= htmlspecialchars(json_encode($b)) ?>)"><i class="fas fa-edit" style="color:#ffc107;"></i> Edit</a>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this business?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="business_id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="delete-action"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
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

            <div class="pagination-controls">
                <div class="page-info" id="pageInfo">Showing 0 to 0 of 0 entries</div>
                <div class="pagination-btns" id="paginationBtns"></div>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div class="modal" id="businessModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-bottom: 20px; color: #111;text-align:center;">Add Business</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="business_id" id="businessId" value="0">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Business Name *</label>
                        <input type="text" name="name" id="bName" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">City</label>
                        <input type="text" name="city" id="bCity" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Business Location</label>
                        <input type="text" name="location" id="blocation" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">No of Reviews</label>
                        <input type="number" name="no_review" id="bno_review" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
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

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Google Review Link *</label>
                        <input type="url" name="google_review_link" id="bGoogle" required placeholder="https://..." style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Keywords</label>
                        <input type="text" name="keywords" id="bKeywords" placeholder="e.g. cafe,food,jaipur" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Choose Language</label>
                    <div class="pills-group" id="language-pills">
                        <button type="button" class="pill" data-lang="English">English</button>
                        <button type="button" class="pill" data-lang="Hindi">Hindi</button>
                        <button type="button" class="pill" data-lang="Hinglish">Hinglish</button>
                    </div>
                    <input type="hidden" name="languages" id="bLanguages" value="">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: 600; color: #333;">Default Tone</label>
                    <div class="pills-group" id="tone-pills">
                        <button type="button" class="pill" data-tone="Professional">Professional</button>
                        <button type="button" class="pill" data-tone="Friendly">Friendly</button>
                        <button type="button" class="pill" data-tone="Enthusiastic">Enthusiastic</button>
                    </div>
                    <input type="hidden" name="default_tone" id="bDefaultTone" value="Friendly">
                </div>

                <div style="margin-bottom: 25px; display: flex; align-items: center; gap: 10px; padding: 12px; background: #f8f9fa; border-radius: 8px;">
                    <input type="checkbox" name="allow_duplicate" id="bAllowDuplicate" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                    <label for="bAllowDuplicate" style="font-weight: 600; color: #333; cursor: pointer; margin: 0;">Allow Duplicate Reviews</label>
                    <small style="color: #888;">If unchecked, users can only generate once per session</small>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                    <button type="button" onclick="closeModal()" style="padding: 10px 24px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; font-weight: 600; color: #555; transition: 0.2s;">Cancel</button>
                    <button type="submit" class="btn-save" style="border-radius: 8px; width: auto; padding: 10px 24px;">Save Business</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Custom Table Logic (Search, Sort, Pagination)
        document.addEventListener('DOMContentLoaded', function() {
            const tbody = document.getElementById('tableBody');
            const originalRows = Array.from(tbody.querySelectorAll('tr'));
            const searchInput = document.getElementById('searchInput');
            const pageSizeSelect = document.getElementById('pageSize');
            const pageInfo = document.getElementById('pageInfo');
            const paginationBtns = document.getElementById('paginationBtns');
            const headers = document.querySelectorAll('th.sortable');
            
            let filteredRows = [...originalRows];
            let currentPage = 1;
            let currentSortCol = -1;
            let currentSortAsc = true;

            function renderTable() {
                // If "No businesses added yet" is the only row, skip logic
                if (originalRows.length === 1 && originalRows[0].cells[0].colSpan > 1) {
                    return;
                }

                const pageSize = parseInt(pageSizeSelect.value);
                const totalEntries = filteredRows.length;
                const totalPages = pageSize === -1 ? 1 : Math.ceil(totalEntries / pageSize);
                
                if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const startIdx = pageSize === -1 ? 0 : (currentPage - 1) * pageSize;
                const endIdx = pageSize === -1 ? totalEntries : Math.min(startIdx + pageSize, totalEntries);

                // Hide all original rows
                originalRows.forEach(row => row.style.display = 'none');

                // Show only paginated rows
                for (let i = startIdx; i < endIdx; i++) {
                    filteredRows[i].style.display = '';
                    tbody.appendChild(filteredRows[i]); // Append to keep order
                }

                // Update Info
                const startDisplay = totalEntries === 0 ? 0 : startIdx + 1;
                pageInfo.innerText = `Showing ${startDisplay} to ${endIdx} of ${totalEntries} entries`;

                
                // Render Pagination Buttons
                paginationBtns.innerHTML = '';
                if (totalPages > 1) {
                    const prevBtn = document.createElement('button');
                    prevBtn.innerText = 'Previous';
                    prevBtn.disabled = currentPage === 1;
                    prevBtn.onclick = () => { currentPage--; renderTable(); };
                    paginationBtns.appendChild(prevBtn);

                    for (let i = 1; i <= totalPages; i++) {
                        // Simple sliding window for pages can be added, but keeping it simple for now
                        const btn = document.createElement('button');
                        btn.innerText = i;
                        if (i === currentPage) btn.classList.add('active');
                        btn.onclick = () => { currentPage = i; renderTable(); };
                        paginationBtns.appendChild(btn);
                    }

                    const nextBtn = document.createElement('button');
                    nextBtn.innerText = 'Next';
                    nextBtn.disabled = currentPage === totalPages;
                    nextBtn.onclick = () => { currentPage++; renderTable(); };
                    paginationBtns.appendChild(nextBtn);
                }
            }

            // Search Filter
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                filteredRows = originalRows.filter(row => {
                    return row.innerText.toLowerCase().includes(term);
                });
                currentPage = 1; // Reset to page 1 on search
                
                // Re-apply sorting if active
                if (currentSortCol !== -1) {
                    sortArray(filteredRows, currentSortCol, currentSortAsc);
                }
                
                renderTable();
            });

            // Length Menu Change
            pageSizeSelect.addEventListener('change', function() {
                currentPage = 1;
                renderTable();
            });

            // Sorting helper
            function sortArray(arr, colIdx, isAsc) {
                arr.sort((a, b) => {
                    let valA = a.cells[colIdx].innerText.trim();
                    let valB = b.cells[colIdx].innerText.trim();
                    
                    // Basic numeric check
                    let numA = parseFloat(valA.replace(/[^0-9.-]+/g,""));
                    let numB = parseFloat(valB.replace(/[^0-9.-]+/g,""));
                    
                    if (!isNaN(numA) && !isNaN(numB) && valA.match(/^[0-9.,]+$/)) {
                        return isAsc ? numA - numB : numB - numA;
                    }
                    
                    return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                });
            }

            // Sorting Clicks
            headers.forEach(header => {
                header.addEventListener('click', function() {
                    const colIdx = parseInt(this.getAttribute('data-col'));
                    
                    if (currentSortCol === colIdx) {
                        currentSortAsc = !currentSortAsc;
                    } else {
                        currentSortCol = colIdx;
                        currentSortAsc = true;
                    }

                    // Reset icons
                    headers.forEach(th => th.querySelector('i').className = 'fas fa-sort');
                    
                    // Set current icon
                    const icon = this.querySelector('i');
                    icon.className = currentSortAsc ? 'fas fa-sort-up' : 'fas fa-sort-down';

                    sortArray(filteredRows, colIdx, currentSortAsc);
                    renderTable();
                });
            });

            // Initial render
            renderTable();
        });

        function openModal(action, data = null) {
            document.getElementById('businessModal').style.display = 'flex';
            document.getElementById('formAction').value = action;
            if (action === 'edit' && data) {
                document.getElementById('modalTitle').innerText = 'Edit Business';
                document.getElementById('businessId').value = data.id;
                document.getElementById('bName').value = data.name;
                document.getElementById('bCity').value = data.city;
                document.getElementById('blocation').value = data.location;
                document.getElementById('bno_review').value = data.no_review;
                document.getElementById('bCategory').value = data.category;
                document.getElementById('bSubcategory').value = data.subcategory;
                document.getElementById('bGoogle').value = data.google_review_link;
                document.getElementById('bAllowDuplicate').checked = (data.allow_duplicate == 1);
                document.getElementById('bKeywords').value = data.keywords || '';
                
                // Set language pills
                let langs = data.languages ? data.languages.split(',') : [];
                document.getElementById('bLanguages').value = data.languages || '';
                document.querySelectorAll('#language-pills .pill').forEach(p => {
                    if (langs.includes(p.getAttribute('data-lang'))) {
                        p.classList.add('active');
                    } else {
                        p.classList.remove('active');
                    }
                });

                // Set tone pills
                let tone = data.default_tone || 'Friendly';
                document.getElementById('bDefaultTone').value = tone;
                document.querySelectorAll('#tone-pills .pill').forEach(p => {
                    p.innerHTML = p.getAttribute('data-tone'); // reset HTML
                    if (p.getAttribute('data-tone') === tone) {
                        p.classList.add('active');
                        p.innerHTML += ' <i class="fas fa-check"></i>';
                    } else {
                        p.classList.remove('active');
                    }
                });
            } else {
                document.getElementById('modalTitle').innerText = 'Add Business';
                document.getElementById('businessId').value = '0';
                document.getElementById('bName').value = '';
                document.getElementById('bCity').value = '';
                document.getElementById('blocation').value = '';
                document.getElementById('bno_review').value = 3;
                document.getElementById('bCategory').value = '';
                document.getElementById('bSubcategory').value = '';
                document.getElementById('bGoogle').value = '';
                document.getElementById('bAllowDuplicate').checked = false;
                document.getElementById('bKeywords').value = '';
                
                // Reset language pills
                document.getElementById('bLanguages').value = '';
                document.querySelectorAll('#language-pills .pill').forEach(p => p.classList.remove('active'));

                // Reset tone pills (Default Friendly)
                document.getElementById('bDefaultTone').value = 'Friendly';
                document.querySelectorAll('#tone-pills .pill').forEach(p => {
                    p.innerHTML = p.getAttribute('data-tone');
                    p.classList.remove('active');
                    if (p.getAttribute('data-tone') === 'Friendly') {
                        p.classList.add('active');
                        p.innerHTML += ' <i class="fas fa-check"></i>';
                    }
                });
            }
        }
        function closeModal() {
            document.getElementById('businessModal').style.display = 'none';
        }

        // Pill Click Logic
        document.addEventListener('DOMContentLoaded', function() {
            // Languages
            document.querySelectorAll('#language-pills .pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    this.classList.toggle('active');
                    let activeLangs = [];
                    document.querySelectorAll('#language-pills .pill.active').forEach(p => {
                        activeLangs.push(p.getAttribute('data-lang'));
                    });
                    document.getElementById('bLanguages').value = activeLangs.join(',');
                });
            });

            // Tone
            document.querySelectorAll('#tone-pills .pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    document.querySelectorAll('#tone-pills .pill').forEach(p => {
                        p.classList.remove('active');
                        p.innerHTML = p.getAttribute('data-tone');
                    });
                    this.classList.add('active');
                    this.innerHTML += ' <i class="fas fa-check"></i>';
                    document.getElementById('bDefaultTone').value = this.getAttribute('data-tone');
                });
            });
        });

        // 3-dot dropdown logic
        function closeAllDropdowns() {
            document.querySelectorAll('.action-dropdown').forEach(d => d.classList.remove('show'));
        }
        function toggleDropdown(btn) {
            const dropdown = btn.nextElementSibling;
            const isOpen = dropdown.classList.contains('show');
            closeAllDropdowns();
            if (!isOpen) {
                dropdown.classList.add('show');
                const rect = btn.getBoundingClientRect();
                const dropRect = dropdown.getBoundingClientRect();
                
                // Position fixed below the button, aligned to the right edge
                let topPos = rect.bottom + 5;
                if (topPos + dropRect.height > window.innerHeight) {
                    topPos = rect.top - dropRect.height - 5; // show above if not enough space
                }
                
                dropdown.style.top = topPos + 'px';
                dropdown.style.left = (rect.right - dropRect.width) + 'px';
            }
        }
        
        // Close dropdown when clicking outside or scrolling
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.action-wrapper')) {
                closeAllDropdowns();
            }
        });
        window.addEventListener('scroll', closeAllDropdowns, true);
    </script>
</body>
</html>
