<style>
/* Sidebar - Blue/Navy Theme */
.sidebar {
    width: 300px;
    min-height: 100vh;
    position: fixed;
    top: 0; left: 0;
    background: linear-gradient(180deg, #0b2c4d 0%, #1a4a7a 100%);
    box-shadow: 4px 0 15px rgba(0,0,0,0.1);
    padding: 20px 0;
    color: #fff;
    z-index: 1000;
    transition: transform 0.3s ease-in-out;
}
.sidebar-header {
    font-size: 1.5rem;
    font-weight: 700;
    text-align: center;
    padding: 15px 0 30px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    margin-bottom: 20px;
    position: relative;
}
.sidebar-header i {
    margin-right: 8px;
    color: #64b5f6;
}
.menu {
    list-style: none;
    padding: 0;
    margin: 0;
}
.menu li {
    margin: 8px 15px;
}
.menu a {
    display: flex;
    align-items: center;
    color: #e0e0e0;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    font-size: 1.05rem;
    padding: 12px 20px;
    border-radius: 8px;
    transition: background 0.3s, color 0.3s, border-left 0.3s;
}
.menu a i {
    width: 25px;
    font-size: 1.1rem;
    margin-right: 10px;
    color: #90caf9;
}
.menu a:hover {
    background: rgba(255,255,255,0.15);
    color: #fff;
}
.menu a.active {
    background: rgba(255,255,255,0.2);
    color: #fff;
    border-left: 4px solid #64b5f6;
    border-radius: 0 8px 8px 0;
}

/* Mobile Top Bar (Hidden on Desktop) */
.mobile-top-bar {
    display: none;
}

.close-sidebar {
    display: none;
    position: absolute;
    top: 15px;
    right: 15px;
    background: transparent;
    color: white;
    border: none;
    font-size: 1.8rem;
    cursor: pointer;
    z-index: 9002;
}

/* Mobile Sidebar Styles */
@media (max-width: 768px) {
    .mobile-top-bar {
        display: flex;
        align-items: center;
        background: linear-gradient(135deg, #0b2c4d 0%, #1a4a7a 100%);
        padding: 15px 20px;
        color: white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        position: relative; /* sits naturally above content */
        z-index: 900;
        width: 100%;
    }
    .mobile-menu-toggle {
        background: transparent;
        color: white;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        margin-right: 15px;
    }
    .mobile-top-bar h2 {
        margin: 0;
        font-size: 1.2rem;
        display: flex;
        align-items: center;
    }
    .mobile-top-bar h2 i {
        margin-right: 8px;
        color: #64b5f6;
    }

    .close-sidebar {
        display: block;
    }
    .sidebar {
        width: 260px;
        transform: translateX(-100%);
        z-index: 9001;
    }
    .sidebar.open {
        transform: translateX(0);
    }
}
</style>

<?php
/*  sidebar.php – Premium admin sidebar (glass‑morphism, gradient) */
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Mobile Top Bar (Sits on top of content, pushes it down) -->
<div class="mobile-top-bar">
    <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" onclick="document.getElementById('sidebarNav').classList.toggle('open')">
        <i class="fas fa-bars"></i>
    </button>
    <h2><i class="fas fa-robot"></i> AI Review</h2>
</div>

<nav class="sidebar" id="sidebarNav">
    <!-- Close Button (Visible only on mobile) -->
    <button type="button" class="close-sidebar" onclick="document.getElementById('sidebarNav').classList.remove('open')">
        <i class="fas fa-times"></i>
    </button>
    
    <div class="sidebar-header">
        <i class="fas fa-robot"></i> AI Review
    </div>
    <ul class="menu">
        <li><a href="../back-office-login-wipro/dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
      
        <!-- <li><a href="/AI-review/review/smart-reply.php" class="<?= $current_page == 'smart-reply.php' ? 'active' : '' ?>"><i class="fas fa-message"></i> smart reply</a></li> -->
        <li><a href="../back-office-login-wipro/business.php" class="<?= $current_page == 'business.php' ? 'active' : '' ?>"><i class="fas fa-business-time"></i>Business</a></li>
           <li><a href="../back-office-login-wipro/setting.php" class="<?= $current_page == 'setting.php' ? 'active' : '' ?>"><i class="fas fa-gear"></i>Setting</a></li>
        <li><a href="../back-office-login-wipro/logout.php" ><i style="color:red" class="fas fa-sign-out-alt"></i> Logout</a></li>
        
    </ul>
</nav>
