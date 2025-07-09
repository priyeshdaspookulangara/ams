<?php
// This file assumes session is already started by auth_check.php or individual pages
// and auth_check.php has been included to provide user functions.
// if (session_status() == PHP_SESSION_NONE) { session_start(); } // Should be handled before this
// include_once 'auth_check.php'; // Should be handled by the page including this layout

// Define a variable for page title, can be overridden by including page
$page_title = isset($page_title) ? $page_title : "School Attendance System";
// Define a variable for page specific content
$page_content = isset($page_content) ? $page_content : "<p>Welcome!</p>"; // Default content

// Placeholder for active menu item logic - actual logic will be more complex
$current_page_url = basename($_SERVER['PHP_SELF']);

// --- Dynamic Menu Structure (Example - this would be more detailed and role-based) ---
// This is a simplified representation. Actual menu generation will be more robust.
$menu_items = [];

// Common Home Link
$menu_items[] = ['type' => 'link', 'href' => 'index.php', 'icon_placeholder' => 'bi-house-door', 'text' => 'Home', 'roles' => ['admin', 'teacher', 'parent']];

// Academics & Attendance Group
$academics_submenu = [];
if (in_array(current_user_role(), ['admin', 'teacher'])) {
    $academics_submenu[] = ['href' => 'students.php', 'text' => 'Manage Students'];
    $academics_submenu[] = ['href' => 'attendance.php', 'text' => 'Take/View Attendance'];
}
if (!empty($academics_submenu)) {
    $menu_items[] = ['type' => 'dropdown', 'id' => 'academicsSubmenu', 'icon_placeholder' => 'bi-journal-bookmark-fill', 'text' => 'Academics & Attendance', 'submenu' => $academics_submenu, 'roles' => ['admin', 'teacher']];
}

// Reports Group
$reports_submenu = [];
if (in_array(current_user_role(), ['admin', 'teacher'])) {
    $reports_submenu[] = ['href' => 'reports_student_master.php', 'text' => 'Student Master List'];
    $reports_submenu[] = ['href' => 'reports_enrollment_summary.php', 'text' => 'Enrollment Summary'];
    $reports_submenu[] = ['href' => 'reports_daily_attendance.php', 'text' => 'Daily Attendance'];
    $reports_submenu[] = ['href' => 'reports_student_individual_attendance.php', 'text' => 'Student Individual Record'];
}
if (!empty($reports_submenu)) {
    $menu_items[] = ['type' => 'dropdown', 'id' => 'reportsSubmenu', 'icon_placeholder' => 'bi-file-earmark-text', 'text' => 'Reports', 'submenu' => $reports_submenu, 'roles' => ['admin', 'teacher']];
}

// Administration Group (Admin only)
$admin_submenu = [];
if (current_user_role() == 'admin') {
    $admin_submenu[] = ['href' => 'settings.php', 'text' => 'System Settings'];
    $admin_submenu[] = ['href' => 'manage_users.php', 'text' => 'Manage Users'];
    $admin_submenu[] = ['href' => 'manage_grades.php', 'text' => 'Manage Grades'];
    $admin_submenu[] = ['href' => 'manage_divisions.php', 'text' => 'Manage Divisions'];
    $admin_submenu[] = ['href' => 'manage_class_sections.php', 'text' => 'Manage Class Sections'];
    // Delegate Tasks is accessible by Admin directly here, and by Teacher below if they are not admin
}
if (!empty($admin_submenu)) {
     $menu_items[] = ['type' => 'dropdown', 'id' => 'adminSubmenu', 'icon_placeholder' => 'bi-person-badge', 'text' => 'Administration', 'submenu' => $admin_submenu, 'roles' => ['admin']];
}

// Delegate Tasks Link (for Admin it's in Admin submenu, for Teacher it's standalone if they are not admin)
if (current_user_role() == 'admin' || current_user_role() == 'teacher') {
    // For admin, it's already in the admin submenu. This adds it for teachers who are not admins.
    // Or, if it's a top-level item for both:
    if(current_user_role() == 'teacher'){ // Only add if teacher and not admin (admin has it in submenu)
         $menu_items[] = ['type' => 'link', 'href' => 'delegate_tasks.php', 'icon_placeholder' => 'bi-person-check', 'text' => 'Delegate Tasks', 'roles' => ['teacher']];
    } else if (current_user_role() == 'admin'){ // If admin, ensure it's in their admin submenu or as a direct link if preferred
        // Check if already added to admin_submenu. If not, add it here as a direct link for admin for visibility.
        // For now, assuming it's fine in the admin submenu only for admins.
        // To add it for admin directly as well (outside submenu):
        // $menu_items[] = ['type' => 'link', 'href' => 'delegate_tasks.php', 'icon_placeholder' => 'bi-person-check', 'text' => 'Delegate Tasks (Admin)', 'roles' => ['admin']];
    }
}


// Parent Dashboard Link
if (current_user_role() == 'parent') {
    $menu_items[] = ['type' => 'link', 'href' => 'parent_dashboard.php', 'icon_placeholder' => 'bi-person-lines-fill', 'text' => 'Parent Dashboard', 'roles' => ['parent']];
}

// Function to check if a menu item should be active
function is_menu_active($href, $current_page_url) {
    if ($href === 'index.php' && $current_page_url === 'index.php') return true;
    if ($href !== 'index.php' && strpos($current_page_url, $href) !== false) return true;
    // Add more sophisticated matching if needed (e.g. for query params)
    return false;
}

// Function to check if a submenu contains an active item
function is_submenu_active($submenu_items, $current_page_url) {
    foreach ($submenu_items as $item) {
        if (is_menu_active($item['href'], $current_page_url)) {
            return true;
        }
    }
    return false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <!-- Placeholder for Bootstrap Icons or Font Awesome CDN -->
    <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"> -->
    <style>
        body {
            display: flex;
        }
        .sidebar {
            width: 280px; /* Expanded width */
            transition: width 0.3s ease;
            /* Ensure vh-100 and position-fixed are applied if not done by BS class in some contexts */
        }
        .sidebar.collapsed {
            width: 80px; /* Collapsed width */
        }
        .sidebar.collapsed .sidebar-text,
        .sidebar.collapsed .dropdown-toggle::after, /* Hides default bootstrap dropdown arrow */
        .sidebar.collapsed hr, /* Hides hr in collapsed */
        .sidebar.collapsed .user-info-detailed-role /* Hide detailed role text */
         {
            display: none;
        }
        .sidebar.collapsed .nav-link i, /* Ensure icons are visible */
        .sidebar.collapsed .dropdown-toggle i /* Ensure user icon visible */
         {
            margin-right: 0 !important; /* Remove margin when text is hidden */
            font-size: 1.5rem; /* Make icons bigger in collapsed */
            display: block;
            text-align: center;
        }
         .sidebar.collapsed .nav-link, .sidebar.collapsed .dropdown-toggle {
            text-align: center; /* Center icons */
        }
        .sidebar.collapsed .user-profile-section strong { /* only icon/avatar part of user section */
            display:none; /* hide username when collapsed, show only avatar or generic icon */
        }
        .sidebar.collapsed .user-profile-section img, .sidebar.collapsed .user-profile-section .default-user-icon {
             margin: 0 auto !important; /* Center avatar/icon */
        }


        .main-content-wrapper {
            flex-grow: 1;
            padding: 20px;
            transition: margin-left 0.3s ease;
            margin-left: 280px; /* Should match expanded sidebar width */
            overflow-x: auto; /* Prevent content from being hidden by sidebar */
        }
        .sidebar.collapsed + .main-content-wrapper {
            margin-left: 80px; /* Should match collapsed sidebar width */
        }

        /* Sub-item styling */
        .sidebar .nav .nav-item .sub-item {
            padding-left: 2.5em; /* Indent sub-items */
            font-size: 0.9em;
        }
        .sidebar .nav .nav-link { /* Ensure consistent padding for icon alignment */
            display: flex;
            align-items: center;
        }
         .sidebar .nav .nav-link i { /* Basic icon styling */
            width: 24px; /* Fixed width for icon container */
            text-align: center; /* Center icon in its container */
        }

        /* Caret for dropdowns */
        .sidebar .nav-link[data-bs-toggle="collapse"]::after {
            content: ' \25BC'; /* Down arrow */
            float: right;
            transition: transform 0.2s ease-in-out;
        }
        .sidebar .nav-link[data-bs-toggle="collapse"][aria-expanded="true"]::after {
            transform: rotate(-180deg);
        }
        .sidebar.collapsed .nav-link[data-bs-toggle="collapse"]::after {
            display:none; /* Hide arrow when sidebar is collapsed */
        }
        #sidebarToggleBtnContainer {
            padding: 0.75rem 1.25rem; /* Match .p-3 roughly for button */
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }


    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="d-flex flex-column flex-shrink-0 text-white bg-dark vh-100 position-fixed" id="sidebar">
        <a href="index.php" class="d-flex align-items-center p-3 mb-0 me-md-auto text-white text-decoration-none">
            <i class="bi bi-calendar-check-fill me-2 fs-4"></i> <!-- Example Bootstrap Icon -->
            <span class="fs-4 sidebar-text">Attendance Sys.</span>
        </a>
        <hr class="sidebar-text">
        <ul class="nav nav-pills flex-column mb-auto">
            <?php foreach ($menu_items as $item): ?>
                <?php if (empty($item['roles']) || in_array(current_user_role(), $item['roles'])): ?>
                    <?php if ($item['type'] == 'link'): ?>
                        <li class="nav-item">
                            <a href="<?php echo $item['href']; ?>"
                               class="nav-link text-white <?php echo is_menu_active($item['href'], $current_page_url) ? 'active' : ''; ?>"
                               title="<?php echo htmlspecialchars($item['text']); ?>">
                                <i class="bi <?php echo $item['icon_placeholder']; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($item['text']); ?></span>
                            </a>
                        </li>
                    <?php elseif ($item['type'] == 'dropdown' && !empty($item['submenu'])):
                        $is_parent_active = is_submenu_active($item['submenu'], $current_page_url);
                    ?>
                        <li class="nav-item">
                            <a href="#<?php echo $item['id']; ?>" data-bs-toggle="collapse"
                               aria-expanded="<?php echo $is_parent_active ? 'true' : 'false'; ?>"
                               class="nav-link text-white <?php echo $is_parent_active ? 'active' : ''; ?>"
                               title="<?php echo htmlspecialchars($item['text']); ?>">
                                <i class="bi <?php echo $item['icon_placeholder']; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($item['text']); ?></span>
                            </a>
                            <ul class="collapse nav flex-column ms-1 <?php echo $is_parent_active ? 'show' : ''; ?>" id="<?php echo $item['id']; ?>" data-bs-parent="#sidebar .nav">
                                <?php foreach ($item['submenu'] as $sub_item): ?>
                                     <?php if (empty($sub_item['roles']) || in_array(current_user_role(), $sub_item['roles'])): ?>
                                    <li class="nav-item">
                                        <a href="<?php echo $sub_item['href']; ?>"
                                           class="nav-link text-white sub-item <?php echo is_menu_active($sub_item['href'], $current_page_url) ? 'active' : ''; ?>"
                                           title="<?php echo htmlspecialchars($sub_item['text']); ?>">
                                            <span class="sidebar-text"><?php echo htmlspecialchars($sub_item['text']); ?></span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <div id="sidebarToggleBtnContainer" class="mt-auto sidebar-text"> <!-- Only show text when expanded -->
             <button class="btn btn-sm btn-outline-light w-100" id="sidebarToggleBtn" title="Toggle Sidebar">
                <i class="bi bi-arrow-bar-left"></i> <span class="sidebar-text">Collapse</span>
            </button>
        </div>
        <hr class="sidebar-text">
        <div class="dropdown p-3 user-profile-section">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo htmlspecialchars(current_username()); ?>">
                <i class="bi bi-person-circle me-2 fs-4"></i> <!-- Default user icon -->
                <strong class="sidebar-text"><?php echo htmlspecialchars(current_username()); ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                <li><span class="dropdown-item-text user-info-detailed-role"><small>Role: <?php echo htmlspecialchars(current_user_role()); ?></small></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
            </ul>
        </div>
    </div>
    <!-- End Sidebar -->

    <!-- Main Content Wrapper -->
    <div class="main-content-wrapper">
        <!-- The actual page content will be outputted here by the including PHP file -->
        <?php
            // This is where the specific page's content will be rendered.
            // The including page should define $page_specific_content or use output buffering.
            if (function_exists('render_page_content')) {
                render_page_content();
            } elseif (isset($page_content_html)) {
                echo $page_content_html;
            } else {
                // Fallback if no specific content rendering function/variable is set by the page
                echo "<h1>" . htmlspecialchars($page_title) . "</h1>";
                echo "<div>Page content goes here.</div>";
            }
        ?>
    </div>
    <!-- End Main Content Wrapper -->

    <script src="assets/js/jquery.min.js"></script> <!-- Added jQuery -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle Functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const sidebarToggleBtnContainer = document.getElementById('sidebarToggleBtnContainer'); // Container for button text
        const mainContent = document.querySelector('.main-content-wrapper'); // To adjust margin

        // Load sidebar state from localStorage
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            if(sidebarToggleBtnContainer) sidebarToggleBtnContainer.querySelector('.sidebar-text').textContent = ''; // Hide text
            if(sidebarToggleBtn) sidebarToggleBtn.querySelector('i').classList.replace('bi-arrow-bar-left', 'bi-arrow-bar-right');
        } else {
            if(sidebarToggleBtnContainer) sidebarToggleBtnContainer.querySelector('.sidebar-text').textContent = 'Collapse';
            if(sidebarToggleBtn) sidebarToggleBtn.querySelector('i').classList.replace('bi-arrow-bar-right', 'bi-arrow-bar-left');
        }


        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                // Update localStorage
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                // Update button text/icon
                if (sidebar.classList.contains('collapsed')) {
                    if(sidebarToggleBtnContainer) sidebarToggleBtnContainer.querySelector('.sidebar-text').textContent = '';
                    if(sidebarToggleBtn) sidebarToggleBtn.querySelector('i').classList.replace('bi-arrow-bar-left', 'bi-arrow-bar-right');

                } else {
                    if(sidebarToggleBtnContainer) sidebarToggleBtnContainer.querySelector('.sidebar-text').textContent = 'Collapse';
                    if(sidebarToggleBtn) sidebarToggleBtn.querySelector('i').classList.replace('bi-arrow-bar-right', 'bi-arrow-bar-left');
                }
            });
        }

        // Simple dropdown for nav (if not using Bootstrap's JS for this specific pattern)
        // This is for the Reports menu, which is already part of the sidebar structure.
        // Bootstrap's collapse handles the vertical accordion.
        // The user dropdown is also handled by Bootstrap.
        // The script for the top-nav style dropdown is not needed if top-nav is removed.

        // Active menu item based on URL (basic version)
        // More robust active state handling is done with PHP `is_menu_active` for initial load.
        // This JS part is mostly for SPAs, but good to have if we ever add client-side routing.
        // For now, PHP handles active class on page load.
    </script>
</body>
</html>
