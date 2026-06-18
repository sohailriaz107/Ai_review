

<?php
session_start();
require_once __DIR__.'/include/connect.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$business_id = 0;

if ($token !== '') {
    $token_stmt = $mysqli->prepare("SELECT user_id, business_id FROM review_tokens WHERE token=?");
    $token_stmt->bind_param('s', $token);
    $token_stmt->execute();
    $token_res = $token_stmt->get_result();
    if ($token_row = $token_res->fetch_assoc()) {
        $user_id = $token_row['user_id'];
        $business_id = $token_row['business_id'];
    }
    $token_stmt->close();
}

$fetch_query = $token !== '' ? "token=" . urlencode($token) : "id=" . $user_id;

$bname = 'Business Not Found';
$google_link = '';
$category = '';
$tone = 'Friendly';

if ($business_id > 0) {
    $stmt = $mysqli->prepare("SELECT name, google_review_link, category, default_tone, keywords, languages, location, no_review FROM businesses WHERE id=?");
    $stmt->bind_param('i', $business_id);
} elseif ($user_id > 0) {
    $stmt = $mysqli->prepare("SELECT name, google_review_link, category, default_tone, keywords, languages, location, no_review FROM businesses WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
}

if (isset($stmt)) {
    $stmt->execute();
    $stmt->bind_result($bname_db, $google_link_db, $category_db, $tone_db, $keywords_db, $languages_db, $location_db, $no_review_db);
    if ($stmt->fetch()) {
        $bname = $bname_db;
        $google_link = $google_link_db;
        $category = $category_db;
        $tone = $tone_db ?: 'Friendly';
        $keywords = $keywords_db ?? '';
        $languages = $languages_db ?? '';
        $location = $location_db ?? '';
        $review_count = $no_review_db ?: 3;
    }
    $stmt->close();
}

// Fetch allow_duplicate setting for this business
$allow_duplicate = 0;
if ($business_id > 0) {
    $bs_stmt = $mysqli->prepare("SELECT allow_duplicate FROM business_settings WHERE business_id = ?");
    $bs_stmt->bind_param('i', $business_id);
    $bs_stmt->execute();
    $bs_result = $bs_stmt->get_result();
    if ($bs_row = $bs_result->fetch_assoc()) {
        $allow_duplicate = intval($bs_row['allow_duplicate']);
    }
    $bs_stmt->close();
}

// API Key for Gemini (Now from .env)
$gemini_api_key = $_ENV['GEMINI_API_KEY'] ?? 'ENTER_YOUR_API_KEY_HERE';

// Handle AJAX Request for Gemini (auto-generate on star click)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'generate_review') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $rating = intval($data['rating'] ?? 5);

    // Use stored business data
    $keywords = $keywords ?? '';
    $languages = $languages ?? '';
    $tone_used = $tone ?: 'Friendly';

    // --- Rate Limiting: Max 5 requests per minute ---
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    $now = time();
    // Remove timestamps older than 60 seconds
    $_SESSION['rate_limit'] = array_filter($_SESSION['rate_limit'], function($ts) use ($now) {
        return ($now - $ts) < 60;
    });
    if (count($_SESSION['rate_limit']) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a minute before generating more reviews.']);
        exit;
    }
    $_SESSION['rate_limit'][] = $now;

    // --- Duplicate Prevention ---
    if ($allow_duplicate === 0 && $business_id > 0) {
        $session_key = "generated_{$business_id}";
        if (isset($_SESSION[$session_key])) {
            echo json_encode(['success' => false, 'message' => 'You have already generated reviews for this business in this session. Duplicate generation is disabled for this business.']);
            exit;
        }
    }

    if ($gemini_api_key === 'ENTER_YOUR_API_KEY_HERE') {
        echo json_encode(['success' => false, 'message' => 'API Key is missing. Please configure your API key in .env file.']);
        exit;
    }

    $styles = [
        'casual customer', 'friendly customer', 'first-time visitor', 'regular customer',
        'family visitor', 'local resident', 'satisfied customer', 'professional customer'
    ];
    $random_style = $styles[array_rand($styles)];

    $focuses = [
        'service quality', 'staff behaviour', 'overall experience', 'value for money',
        'cleanliness', 'atmosphere', 'product quality', 'customer support'
    ];
    $random_focus = $focuses[array_rand($focuses)];

    $prompt = "
Generate exactly {$review_count} unique customer review options.

Business: {$bname}
Category: {$category}
Location: {$location}
Rating: {$rating}/5
Tone: {$tone_used}
Keywords: {$keywords}
Language: {$languages}
Customer Type: {$random_style}
Review Focus: {$random_focus}

Requirements:
- Each review must sound like it was written by a different real customer.
- VERY IMPORTANT (LANGUAGE): You MUST write the reviews in the requested language(s) '{$languages}'. 
  * If multiple languages are listed (e.g. 'English,Hindi'), you MUST generate some reviews entirely in one language and some entirely in the other language (e.g. 1 in English, 2 in Hindi). 
  * If 'Hindi' is requested, use proper Hindi script (देवनागरी). 
  * If 'Hinglish' is requested, write conversational Hindi using the English alphabet.
- Use different openings, wording and sentence structures.
- Avoid repeating phrases between reviews.
- Keep reviews natural, authentic and human-like.
- Mention the business name or location naturally when relevant.
- Include relevant keywords naturally where appropriate.
- Length: 30-60 words per review.
- Do not use quotation marks, emojis, or markdown.
- Do not number the reviews.

Return ONLY a valid JSON array containing exactly {$review_count} review strings.
";

    // Call Gemini API (gemini-2.5-flash-lite)
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=" . $gemini_api_key;
    
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
            
            // Mark session as generated (for duplicate prevention)
            if ($business_id > 0) {
                $_SESSION["generated_{$business_id}"] = true;
            }
            
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
    <link rel="stylesheet" href="../assets/review_generation.css">
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
