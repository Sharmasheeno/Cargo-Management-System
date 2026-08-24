<?php
// includes/theme_handler.php
// Dynamic Theme Handler - Loads appearance settings from database

session_start();

// Include database connection FIRST
require_once __DIR__ . '/../config/db_connect.php';

// Function to get appearance settings from database
function loadThemeSettings() {
    global $pdo;
    
    $theme_settings = [];
    
    // Check if $pdo exists
    if (!$pdo) {
        error_log("Theme handler: Database connection not available");
        return $theme_settings;
    }
    
    try {
        // Get tenant_id from session or default
        $tenant_id = $_SESSION['tenant_id'] ?? null;
        
        // Check if appearance_settings table exists
        $table_check = $pdo->query("SHOW TABLES LIKE 'appearance_settings'");
        if ($table_check->rowCount() == 0) {
            error_log("Theme handler: appearance_settings table does not exist");
            return $theme_settings;
        }
        
        // Query appearance settings
        $stmt = $pdo->prepare("SELECT setting_key, setting_value, setting_type FROM appearance_settings WHERE tenant_id = ? OR tenant_id IS NULL");
        $stmt->execute([$tenant_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $theme_settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log("Theme handler error: " . $e->getMessage());
    }
    
    return $theme_settings;
}

// Load settings
$theme = loadThemeSettings();

// Set default values if not exist
$theme_defaults = [
    'primary_color' => '#520066',
    'primary_light' => '#7a1a99',
    'primary_dark' => '#3a004a',
    'secondary_color' => '#f4dd08',
    'secondary_dark' => '#d4c005',
    'success_color' => '#00a65a',
    'danger_color' => '#c62828',
    'warning_color' => '#ff9800',
    'info_color' => '#17a2b8',
    'dark_color' => '#2d2d2d',
    'gray_color' => '#6c757d',
    'body_bg' => '#f4f6f9',
    'sidebar_bg' => '#1a1a2e',
    'sidebar_text' => '#ffffff',
    'navbar_bg' => '#ffffff',
    'card_bg' => '#ffffff',
    'footer_bg' => '#2d2d2d',
    'footer_text' => '#ffffff',
    'border_radius' => '8px',
    'box_shadow' => '0 2px 4px rgba(0,0,0,0.05)',
    'font_family' => "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif",
    'theme_mode' => 'light',
    'enable_animations' => '1',
    'rtl_enabled' => '0',
    'custom_css' => '',
    'custom_js' => ''
];

foreach ($theme_defaults as $key => $default) {
    if (!isset($theme[$key]) || empty($theme[$key])) {
        $theme[$key] = $default;
    }
}

// Generate dynamic CSS
function generateThemeCSS($theme) {
    $css = "
        :root {
            --curdun-primary: {$theme['primary_color']};
            --curdun-primary-light: {$theme['primary_light']};
            --curdun-primary-dark: {$theme['primary_dark']};
            --curdun-secondary: {$theme['secondary_color']};
            --curdun-secondary-dark: {$theme['secondary_dark']};
            --curdun-success: {$theme['success_color']};
            --curdun-danger: {$theme['danger_color']};
            --curdun-warning: {$theme['warning_color']};
            --curdun-info: {$theme['info_color']};
            --curdun-dark: {$theme['dark_color']};
            --curdun-gray: {$theme['gray_color']};
            --curdun-body-bg: {$theme['body_bg']};
            --curdun-sidebar-bg: {$theme['sidebar_bg']};
            --curdun-sidebar-text: {$theme['sidebar_text']};
            --curdun-navbar-bg: {$theme['navbar_bg']};
            --curdun-card-bg: {$theme['card_bg']};
            --curdun-footer-bg: {$theme['footer_bg']};
            --curdun-footer-text: {$theme['footer_text']};
            --curdun-border-radius: {$theme['border_radius']};
            --curdun-box-shadow: {$theme['box_shadow']};
            --curdun-font-family: {$theme['font_family']};
        }
        
        /* Apply theme variables globally */
        body {
            background-color: var(--curdun-body-bg);
            font-family: var(--curdun-font-family);
            margin: 0;
            padding: 0;
        }
        
        /* Card styles */
        .card, .settings-card, .dashboard-card {
            background-color: var(--curdun-card-bg);
            border-radius: var(--curdun-border-radius);
            box-shadow: var(--curdun-box-shadow);
        }
        
        /* Sidebar styles */
        .sidebar, .side-menu {
            background-color: var(--curdun-sidebar-bg);
        }
        
        .sidebar .nav-link, .side-menu a {
            color: var(--curdun-sidebar-text);
        }
        
        .sidebar .nav-link:hover, .side-menu a:hover {
            background-color: var(--curdun-primary);
        }
        
        /* Navbar styles */
        .navbar, .top-navbar {
            background-color: var(--curdun-navbar-bg);
        }
        
        /* Footer styles */
        footer, .footer {
            background-color: var(--curdun-footer-bg);
            color: var(--curdun-footer-text);
        }
        
        /* Button styles */
        .btn-primary {
            background-color: var(--curdun-primary);
            border-color: var(--curdun-primary);
            border-radius: var(--curdun-border-radius);
        }
        
        .btn-primary:hover {
            background-color: var(--curdun-primary-dark);
            border-color: var(--curdun-primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--curdun-secondary);
            border-color: var(--curdun-secondary);
            color: #333;
        }
        
        .btn-success {
            background-color: var(--curdun-success);
            border-color: var(--curdun-success);
        }
        
        .btn-danger {
            background-color: var(--curdun-danger);
            border-color: var(--curdun-danger);
        }
        
        .btn-warning {
            background-color: var(--curdun-warning);
            border-color: var(--curdun-warning);
        }
        
        .btn-info {
            background-color: var(--curdun-info);
            border-color: var(--curdun-info);
        }
        
        /* Text colors */
        .text-primary { color: var(--curdun-primary) !important; }
        .text-success { color: var(--curdun-success) !important; }
        .text-danger { color: var(--curdun-danger) !important; }
        .text-warning { color: var(--curdun-warning) !important; }
        .text-info { color: var(--curdun-info) !important; }
        
        /* Badge colors */
        .badge-primary { background-color: var(--curdun-primary); }
        .badge-success { background-color: var(--curdun-success); }
        .badge-danger { background-color: var(--curdun-danger); }
        .badge-warning { background-color: var(--curdun-warning); }
        .badge-info { background-color: var(--curdun-info); }
        
        /* Alert colors */
        .alert-primary { background-color: var(--curdun-primary-light); border-color: var(--curdun-primary); color: var(--curdun-primary-dark); }
        .alert-success { background-color: #d4edda; border-color: var(--curdun-success); color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: var(--curdun-danger); color: #721c24; }
        .alert-warning { background-color: #fff3cd; border-color: var(--curdun-warning); color: #856404; }
        .alert-info { background-color: #d1ecf1; border-color: var(--curdun-info); color: #0c5460; }
        
        /* Table styles */
        .table thead th {
            background-color: var(--curdun-primary);
            color: white;
        }
        
        /* Pagination styles */
        .page-item.active .page-link {
            background-color: var(--curdun-primary);
            border-color: var(--curdun-primary);
        }
        
        /* Links */
        a {
            color: var(--curdun-primary);
        }
        
        a:hover {
            color: var(--curdun-primary-dark);
        }
        
        /* Form controls */
        .form-control:focus {
            border-color: var(--curdun-primary);
            box-shadow: 0 0 0 0.2rem rgba(var(--curdun-primary-rgb), 0.25);
        }
    ";
    
    // Add dark mode if enabled
    if ($theme['theme_mode'] === 'dark') {
        $css .= "
            body {
                background-color: #121212;
                color: #e0e0e0;
            }
            .card, .settings-card, .modal-content {
                background-color: #1e1e1e;
                color: #e0e0e0;
                border-color: #333;
            }
            .form-control, .input-group-text {
                background-color: #2d2d2d;
                border-color: #444;
                color: #e0e0e0;
            }
            .form-control:focus {
                background-color: #2d2d2d;
                color: #e0e0e0;
            }
            .table {
                color: #e0e0e0;
            }
            .table-striped tbody tr:nth-of-type(odd) {
                background-color: #2d2d2d;
            }
            .table-bordered, .table-bordered th, .table-bordered td {
                border-color: #444;
            }
            .dropdown-menu {
                background-color: #2d2d2d;
                color: #e0e0e0;
            }
            .dropdown-item {
                color: #e0e0e0;
            }
            .dropdown-item:hover {
                background-color: var(--curdun-primary);
                color: white;
            }
            .border {
                border-color: #444 !important;
            }
            .bg-white {
                background-color: #1e1e1e !important;
            }
            .text-muted {
                color: #999 !important;
            }
        ";
    }
    
    // Add RTL support if enabled
    if ($theme['rtl_enabled'] === '1') {
        $css .= "
            body {
                direction: rtl;
                text-align: right;
            }
            .sidebar {
                right: 0;
                left: auto;
            }
            .ml-auto {
                margin-left: 0 !important;
                margin-right: auto !important;
            }
            .mr-auto {
                margin-right: 0 !important;
                margin-left: auto !important;
            }
            .text-left {
                text-align: right !important;
            }
            .text-right {
                text-align: left !important;
            }
        ";
    }
    
    // Add animations if enabled
    if ($theme['enable_animations'] === '1') {
        $css .= "
            .fade-in {
                animation: fadeIn 0.3s ease-in;
            }
            .slide-in {
                animation: slideIn 0.3s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from { transform: translateX(-20px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        ";
    }
    
    // Add custom CSS from settings
    if (!empty($theme['custom_css'])) {
        $css .= "\n/* Custom CSS */\n" . $theme['custom_css'];
    }
    
    return $css;
}

// Generate CSS
$dynamic_css = generateThemeCSS($theme);
?>

<style>
<?= $dynamic_css ?>
</style>

<?php
// Add custom JavaScript if exists
if (!empty($theme['custom_js'])): ?>
<script>
<?= $theme['custom_js'] ?>
</script>
<?php endif; ?>