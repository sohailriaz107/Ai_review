<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/../include/connect.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../back-office-login-wipro/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$gemini_api_key = $_ENV['GEMINI_API_KEY'] ?? 'ENTER_YOUR_API_KEY_HERE';

// Handle AJAX Request for Gemini
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'generate_smart_reply') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    
    $customerName = trim($data['customerName'] ?? 'Valued Customer');
    if (empty($customerName)) $customerName = 'Valued Customer';
    
    $rating = intval($data['rating'] ?? 5);
    $reviewText = trim($data['reviewText'] ?? '');
    
    if (empty($reviewText)) {
        echo json_encode(['success' => false, 'message' => 'Review text is required.']);
        exit;
    }

    // Get the business details if needed, but not strictly necessary for reply, though helpful for context
    $stmt = $mysqli->prepare("SELECT name, category FROM businesses WHERE user_id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($bname, $category);
    $business_context = "";
    if ($stmt->fetch()) {
        $business_context = " for a business named '$bname' in the '$category' industry";
    }
    $stmt->close();

    $prompt = "Write 5 distinct, professional responses to a customer review$business_context. The customer's name is '$customerName'. They gave a rating of $rating out of 5 stars and wrote the following review: '$reviewText'. The replies should be highly professional, address the customer's specific points, and match the language and tone of the customer's review.\n\nIMPORTANT: Return ONLY a valid JSON array of strings containing the 5 reply options. Do not include markdown formatting, numbering, or any other text.";

    // Call Gemini API (gemini-2.5-flash)
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key;
    
    $post_data = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.8,
            "responseMimeType" => "application/json"
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        echo json_encode(['success' => false, 'message' => "cURL Error: $err"]);
    } else {
        $resp_data = json_decode($response, true);
        
        // Handle Gemini API specific errors (e.g., quota exceeded)
        if (isset($resp_data['error'])) {
            $errorMessage = $resp_data['error']['message'] ?? 'Unknown Gemini API Error';
            echo json_encode(['success' => false, 'message' => "API Error: " . $errorMessage]);
            exit;
        }

        if (isset($resp_data['candidates'][0]['content']['parts'][0]['text'])) {
            $generated_text = $resp_data['candidates'][0]['content']['parts'][0]['text'];
            
            // Attempt to parse JSON array of strings
            $replies_array = json_decode($generated_text, true);
            if (is_array($replies_array)) {
                echo json_encode(['success' => true, 'texts' => $replies_array]);
            } else {
                echo json_encode(['success' => true, 'texts' => [trim($generated_text)]]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to parse Gemini response.', 'raw' => $response]);
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Reply – AI Review</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../back-office-login-wipro/assets/css/style.css">
    <style>
        .stars {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            direction: rtl; /* For CSS hover effect on preceding siblings */
            width: max-content;
        }
        .stars i {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
        }
        .stars i:hover,
        .stars i:hover ~ i,
        .stars i.active,
        .stars i.active ~ i {
            color: #FFD700; /* Golden color */
        }
        .options-container {
            display: flex;
            flex-direction: row;
            gap: 15px;
            margin-top: 30px;
            overflow-x: auto;
            padding-bottom: 10px;
            scroll-snap-type: x mandatory;
            scrollbar-width: none;
        }
        .options-container::-webkit-scrollbar {
            display: none;
        }
        .option-card {
            flex: 0 0 85%;
            scroll-snap-align: center;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 12px;
            background: #fdfdfd;
            text-align: left;
            font-size: 0.95rem;
            color: #444;
            transition: all 0.2s;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .option-text {
            margin-bottom: 15px;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        .copy-btn {
            align-self: flex-end;
            background: none;
            border: none;
            color: #1890ff;
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .copy-btn:hover {
            color: #096dd9;
        }
        .review-textarea {
            width: 100%;
            height: 120px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            resize: none;
            margin-bottom: 20px;
            background: #fdfdfd;
        }
        .review-textarea:focus {
            outline: none;
            border-color: #1890ff;
            background: #fff;
        }
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(24, 144, 255, 0.3);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
    </style>
</head>
<body class="admin">
    <?php include '../include/sidebar.php'; ?>
    <main class="content">
        <h1>Smart Reply Generation</h1>
        <p>Generate professional replies to your customer reviews instantly.</p>
        
        <div class="dashboard-grid" style="grid-template-columns: 1fr; max-width: 800px; margin: 0 auto;">
            <div class="dash-card">
                <form id="smart-reply-form">
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom: 8px; font-weight: 600; color: #333;">Customer Name</label>
                        <div class="input-group" style="margin: 0;">
                            <i class="fas fa-user"></i>
                            <input type="text" id="customer-name" placeholder="e.g. John Doe">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom: 8px; font-weight: 600; color: #333;">Rating (1-5)</label>
                        <input type="hidden" id="rating-input" value="5">
                        <div class="stars" id="star-rating">
                            <i class="fas fa-star active" data-value="5"></i>
                            <i class="fas fa-star active" data-value="4"></i>
                            <i class="fas fa-star active" data-value="3"></i>
                            <i class="fas fa-star active" data-value="2"></i>
                            <i class="fas fa-star active" data-value="1"></i>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom: 8px; font-weight: 600; color: #333;">Customer Review *</label>
                        <textarea class="review-textarea" id="review-text" placeholder="Paste the customer's review here..." required></textarea>
                    </div>

                    <div class="options-container" id="options-container" style="display:none;"></div>
                      <div id="status-message" style="display:none; margin-top:15px; margin-bottom:10px; color:#1890ff; font-weight:600; font-size:1rem; text-align: center;"><i class="fas fa-spinner fa-spin"></i> AI is thinking...</div>

                    <button type="submit" class="btn-submit" id="btn-generate">Generate Smart Replies</button>
                    
                  
                </form>

                
            </div>
        </div>
    </main>

    <script>
        const stars = document.querySelectorAll('#star-rating i');
        const ratingInput = document.getElementById('rating-input');
        const form = document.getElementById('smart-reply-form');
        const customerNameInput = document.getElementById('customer-name');
        const reviewInput = document.getElementById('review-text');
        const btnGenerate = document.getElementById('btn-generate');
        const statusMsg = document.getElementById('status-message');
        const optionsContainer = document.getElementById('options-container');

        // Initial setup for 5 stars
        function setRating(val) {
            stars.forEach(s => s.classList.remove('active'));
            stars.forEach(s => {
                if (parseInt(s.getAttribute('data-value')) <= val) {
                    s.classList.add('active');
                }
            });
            ratingInput.value = val;
        }

        // Set default visual to 5 stars
        setRating(5);

        // Update star click to set rating
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const val = parseInt(this.getAttribute('data-value'));
                setRating(val);
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const rating = ratingInput.value;
            const customerName = customerNameInput.value;
            const reviewText = reviewInput.value;

            if (!reviewText.trim()) {
                alert("Please provide the customer's review.");
                return;
            }

            // Show loading state
            btnGenerate.disabled = true;
            statusMsg.style.display = 'block';
            optionsContainer.style.display = 'none';
            optionsContainer.innerHTML = '';

            fetch('smart-reply.php?action=generate_smart_reply', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    rating: rating,
                    customerName: customerName,
                    reviewText: reviewText
                })
            })
            .then(res => res.json())
            .then(data => {
                btnGenerate.disabled = false;
                statusMsg.style.display = 'none';

                if (data.success) {
                    if (data.texts && Array.isArray(data.texts) && data.texts.length > 0) {
                        optionsContainer.style.display = 'flex';
                        data.texts.forEach((text, index) => {
                            const card = document.createElement('div');
                            card.className = 'option-card';
                            
                            const textDiv = document.createElement('div');
                            textDiv.className = 'option-text';
                            textDiv.textContent = text;
                            
                            const copyBtn = document.createElement('button');
                            copyBtn.type = 'button';
                            copyBtn.className = 'copy-btn';
                            copyBtn.innerHTML = '<i class="far fa-copy"></i> Copy';
                            copyBtn.title = "Copy to clipboard";
                            
                            copyBtn.addEventListener('click', function() {
                                navigator.clipboard.writeText(text).then(() => {
                                    const originalHtml = this.innerHTML;
                                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                                    setTimeout(() => {
                                        this.innerHTML = originalHtml;
                                    }, 2000);
                                }).catch(err => {
                                    alert('Failed to copy text. Please select and copy manually.');
                                });
                            });
                            
                            card.appendChild(textDiv);
                            card.appendChild(copyBtn);
                            optionsContainer.appendChild(card);
                        });
                    } else if (data.text) {
                        // Fallback
                        alert("Generated single reply (Check console)");
                        console.log(data.text);
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                btnGenerate.disabled = false;
                statusMsg.style.display = 'none';
                alert('An error occurred communicating with the AI.');
                console.error(err);
            });
        });
    </script>
</body>
</html>
