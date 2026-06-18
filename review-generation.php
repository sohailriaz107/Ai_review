

<?php
require_once __DIR__.'/include/connect.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($token !== '') {
    $token_stmt = $mysqli->prepare("SELECT user_id FROM review_tokens WHERE token=?");
    $token_stmt->bind_param('s', $token);
    $token_stmt->execute();
    $token_res = $token_stmt->get_result();
    if ($token_row = $token_res->fetch_assoc()) {
        $user_id = $token_row['user_id'];
    }
    $token_stmt->close();
}

$fetch_query = $token !== '' ? "token=" . urlencode($token) : "id=" . $user_id;

$bname = 'Business Not Found';
$google_link = '';
$category = '';
$tone = 'Friendly';

if ($user_id > 0) {
    $stmt = $mysqli->prepare("SELECT name, google_review_link, category, default_tone, keywords, languages FROM businesses WHERE user_id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($bname_db, $google_link_db, $category_db, $tone_db, $keywords_db, $languages_db);
    if ($stmt->fetch()) {
        $bname = $bname_db;
        $google_link = $google_link_db;
        $category = $category_db;
        $tone = $tone_db ?: 'Friendly';
        $keywords = $keywords_db ?? '';
        $languages = $languages_db ?? '';
    }
    $stmt->close();
}

// API Key for Gemini (Now from .env)
$gemini_api_key = $_ENV['GEMINI_API_KEY'] ?? 'ENTER_YOUR_API_KEY_HERE';

// Handle AJAX Request for Gemini (auto-generate on star click)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'generate_review') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $rating = intval($data['rating'] ?? 5);

    // Use stored business data: keywords, languages, tone (already fetched earlier)
    $keywords = $keywords ?? '';
    $languages = $languages ?? '';
    $tone_used = $tone ?: 'Friendly';

    if ($gemini_api_key === 'ENTER_YOUR_API_KEY_HERE') {
        echo json_encode(['success' => false, 'message' => 'API Key is missing. Please configure $gemini_api_key in review-generation.php']);
        exit;
    }

    $prompt = "Write 3 distinct, detailed customer review options (at least 3-4 sentences each) for a business named '$bname' in the '$category' industry. The rating is $rating out of 5 stars. Use a $tone_used tone. Include the following keywords: $keywords. Use the selected language(s): $languages. The reviews should read naturally from a customer's perspective without quotation marks.\n\nIMPORTANT: Return ONLY a valid JSON array of strings containing the 3 review options. Do not include markdown formatting or any other text.";

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
            if (strpos(strtolower($errorMessage), 'high demand') !== false) {
                echo json_encode(['success' => false, 'message' => "AI is busy right now. Please try again after a few seconds."]);
            } else {
                echo json_encode(['success' => false, 'message' => "API Error: " . $errorMessage]);
            }
            exit;
        }

        if (isset($resp_data['candidates'][0]['content']['parts'][0]['text'])) {
            $generated_text = $resp_data['candidates'][0]['content']['parts'][0]['text'];
            
            // Attempt to parse JSON array of strings
            $reviews_array = json_decode($generated_text, true);
            if (is_array($reviews_array)) {
                echo json_encode(['success' => true, 'texts' => $reviews_array]);
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
    <title>Leave a Review – <?= htmlspecialchars($bname) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: #333;
        }
        .review-card {
            background: #fff;
            width: 100%;
            max-width: 500px;
            padding: 40px 30px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            text-align: center;
        }
        .review-card .logo-icon {
            font-size: 3.5rem;
            color: #1890ff;
            margin-bottom: 15px;
        }
        .review-card h1 {
            font-size: 1.8rem;
            color: #111;
            margin-bottom: 5px;
        }
        .review-card p.subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .stars {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
            direction: rtl; /* For CSS hover effect on preceding siblings */
        }
        .stars i {
            font-size: 2.5rem;
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
        .options-container {
            display: flex;
            flex-direction: row;
            gap: 15px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 10px;
            scroll-snap-type: x mandatory;
            scrollbar-width: none; /* Hide scrollbar for Firefox */
        }
        .options-container::-webkit-scrollbar {
            display: none; /* Hide scrollbar for WebKit */
        }
        .option-card {
            flex: 0 0 85%;
            scroll-snap-align: center;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 12px;
            background: #fdfdfd;
            cursor: pointer;
            text-align: left;
            font-size: 0.95rem;
            color: #444;
            transition: all 0.2s;
        }
        .option-card.selected {
            border-color: #1890ff;
            background: #e6f7ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        .option-card:hover {
            border-color: #1890ff;
        }
        
    </style>
</head>
<body>

    <div class="review-card">
        <div class="logo-icon"><i class="fas fa-store"></i></div>
        <h1><?= htmlspecialchars($bname) ?></h1>
        <p class="subtitle">We value your feedback!</p>

        <!-- Rating Stars (RTL applied so left-to-right logic works beautifully with pure CSS, JS will handle the state) -->
        <div class="stars" id="star-rating">
            <i class="fas fa-star" data-value="5"></i>
            <i class="fas fa-star" data-value="4"></i>
            <i class="fas fa-star" data-value="3"></i>
            <i class="fas fa-star" data-value="2"></i>
            <i class="fas fa-star" data-value="1"></i>
        </div>
        
        <div id="status-message" style="display:none; margin-bottom:15px; color:#1890ff; font-weight:600; font-size:1rem;"><i class="fas fa-spinner fa-spin"></i> AI is thinking...</div>

        <form id="review-form" action="" method="POST">
            <input type="hidden" name="rating" id="rating-input" value="0">
            
            <div class="options-container" id="options-container" style="display:none;"></div>
            
            <textarea class="review-textarea" id="review-text" name="message" placeholder="Write your review here..." required></textarea>
            
            <button type="button" class="btn-submit" id="btn-ready">Ready to Review</button>
        </form>
    </div>

    <script>
        const stars = document.querySelectorAll('#star-rating i');
        const ratingInput = document.getElementById('rating-input');
        const textarea = document.getElementById('review-text');
        const btnSubmit = document.getElementById('btn-ready');
        const googleLink = <?= json_encode($google_link) ?>;

        // Function to generate review based on selected rating
function generateReview(rating) {
    const statusMsg = document.getElementById('status-message');
    const optionsContainer = document.getElementById('options-container');
    
    // Show AI is thinking...
    statusMsg.style.display = 'block';
    optionsContainer.style.display = 'none';
    optionsContainer.innerHTML = '';
    textarea.value = ''; // clear textarea

    fetch('/AI-review/review-generation.php?<?= $fetch_query ?>&action=generate_review', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rating: rating })
    })
    .then(res => res.json())
    .then(data => {
        statusMsg.style.display = 'none';
        if (data.success) {
            if (data.texts && Array.isArray(data.texts) && data.texts.length > 0) {
                optionsContainer.style.display = 'flex';
                data.texts.forEach((text, index) => {
                    const card = document.createElement('div');
                    card.className = 'option-card';
                    if (index === 0) {
                        card.classList.add('selected');
                        textarea.value = text;
                    }
                    card.textContent = text;
                    card.addEventListener('click', function() {
                        document.querySelectorAll('.option-card').forEach(c => c.classList.remove('selected'));
                        this.classList.add('selected');
                        textarea.value = text;
                    });
                    optionsContainer.appendChild(card);
                });
            } else if (data.texts && data.texts.length > 0) {
                textarea.value = data.texts[0];
            } else if (data.text) {
                textarea.value = data.text;
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        statusMsg.style.display = 'none';
        alert('An error occurred communicating with the AI.');
    });
}

// Attach click listeners to stars
stars.forEach(star => {
    star.addEventListener('click', function() {
        stars.forEach(s => s.classList.remove('active'));
        this.classList.add('active');
        const rating = this.getAttribute('data-value');
        ratingInput.value = rating;
        // Auto-generate review on star selection
        generateReview(rating);
    });
});

        btnSubmit.addEventListener('click', function() {
            const textToCopy = textarea.value;
            if (!textToCopy.trim()) {
                alert('Please write or generate a review first!');
                return;
            }
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                alert('Review text copied to clipboard! Redirecting you to Google to paste it...');
                if (googleLink) {
                    window.location.href = googleLink;
                }
            }).catch(err => {
                alert('Failed to copy text automatically. Please select the text, copy it, and then click OK to proceed to Google.');
                if (googleLink) {
                    window.location.href = googleLink;
                }
            });
        });
    </script>
</body>
</html>
