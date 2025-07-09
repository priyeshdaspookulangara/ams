<?php
// This file assumes auth_check.php (providing current_user_role(), current_username(), current_user_id())
// and config.php (for $conn if DB queries are needed in menu, e.g. for teacher's delegate link)
// have been included by the page that includes layout_authenticated.php.

// Placeholder for active menu item logic - actual logic will be more complex
$current_page_url = basename($_SERVER['PHP_SELF']); // Used by is_menu_active

// --- Dynamic Menu Structure ---
$menu_items = [];
$user_role = current_user_role();
$current_user_id_for_menu = current_user_id();
global $conn; // Make $conn available if not already global or passed

// 1. Dashboard Link
if ($user_role == 'admin') {
    $menu_items[] = ['type' => 'link', 'href' => 'admin_dashboard.php', 'icon_class' => 'bi-speedometer2', 'text' => 'Dashboard', 'roles' => ['admin']];
} elseif ($user_role == 'teacher') {
    $menu_items[] = ['type' => 'link', 'href' => 'teacher_dashboard.php', 'icon_class' => 'bi-speedometer2', 'text' => 'Dashboard', 'roles' => ['teacher']];
} elseif ($user_role == 'parent') {
    $menu_items[] = ['type' => 'link', 'href' => 'parent_dashboard.php', 'icon_class' => 'bi-person-video2', 'text' => 'Dashboard', 'roles' => ['parent']];
}
$menu_items[] = ['type' => 'link', 'href' => 'index.php', 'icon_class' => 'bi-house-door-fill', 'text' => 'Site Home', 'roles' => ['admin', 'teacher', 'parent']];

// 2. Academics Group
$academics_submenu = [];
if (in_array($user_role, ['admin', 'teacher'])) {
    $academics_submenu[] = ['href' => 'students.php', 'text' => 'Manage Students', 'icon_class' => 'bi-people-fill'];
    $academics_submenu[] = ['href' => 'attendance.php', 'text' => 'Take/View Attendance', 'icon_class' => 'bi-calendar-check-fill'];
}
if (!empty($academics_submenu)) {
    $menu_items[] = ['type' => 'dropdown', 'id' => 'academicsSubmenu', 'icon_class' => 'bi-journal-bookmark-fill', 'text' => 'Academics', 'submenu' => $academics_submenu, 'roles' => ['admin', 'teacher']];
}

// 3. Reports Group
$reports_submenu = [];
if (in_array($user_role, ['admin', 'teacher'])) {
    $reports_submenu[] = ['href' => 'reports_student_master.php', 'text' => 'Student Master List'];
    $reports_submenu[] = ['href' => 'reports_enrollment_summary.php', 'text' => 'Enrollment Summary'];
    $reports_submenu[] = ['href' => 'reports_daily_attendance.php', 'text' => 'Daily Attendance'];
    $reports_submenu[] = ['href' => 'reports_student_individual_attendance.php', 'text' => 'Student Individual Record'];
    $reports_submenu[] = ['href' => 'reports_absentee_list.php', 'text' => 'Absentee List'];
    $reports_submenu[] = ['href' => 'reports_excessive_absences.php', 'text' => 'Excessive Absences'];
    $reports_submenu[] = ['href' => 'reports_student_contacts.php', 'text' => 'Student Contact Info'];
    if ($user_role == 'admin') {
        $reports_submenu[] = ['href' => 'reports_overall_attendance_summary.php', 'text' => 'Overall Inst. Summary'];
    }
}
if (!empty($reports_submenu)) {
    $menu_items[] = ['type' => 'dropdown', 'id' => 'reportsSubmenu', 'icon_class' => 'bi-file-earmark-text-fill', 'text' => 'Reports', 'submenu' => $reports_submenu, 'roles' => ['admin', 'teacher']];
}

// 4. Administration Group (Admin only)
if ($user_role == 'admin') {
    $academic_setup_submenu = [
        ['href' => 'manage_grades.php', 'text' => 'Grades'],
        ['href' => 'manage_divisions.php', 'text' => 'Divisions'],
        ['href' => 'manage_class_sections.php', 'text' => 'Class Sections']
    ];
    $user_task_admin_submenu = [
        ['href' => 'manage_users.php', 'text' => 'Manage Users'],
        ['href' => 'delegate_tasks.php', 'text' => 'Delegate Tasks']
    ];
    $admin_main_submenu = [
        ['type' => 'dropdown', 'id' => 'academicSetupAdminSubmenu', 'text' => 'Academic Setup', 'submenu' => $academic_setup_submenu, 'icon_class' => 'bi-building-gear'],
        ['type' => 'dropdown', 'id' => 'userTaskAdminSubmenu', 'text' => 'User & Task Mgmt', 'submenu' => $user_task_admin_submenu, 'icon_class' => 'bi-people-gear'],
        ['href' => 'settings.php', 'text' => 'System Settings', 'icon_class' => 'bi-gear-fill']
    ];
    $menu_items[] = ['type' => 'dropdown', 'id' => 'administrationSubmenu', 'icon_class' => 'bi-person-badge-fill', 'text' => 'Administration', 'submenu' => $admin_main_submenu, 'roles' => ['admin']];
}

// 5. Delegate My Tasks Link (For Teachers who are Class Teachers)
if ($user_role == 'teacher') {
    if ($conn) { // Check if $conn is available
        $can_delegate_query = "SELECT id FROM class_sections WHERE class_teacher_user_id = $current_user_id_for_menu LIMIT 1";
        $can_delegate_res = mysqli_query($conn, $can_delegate_query);
        if ($can_delegate_res && mysqli_num_rows($can_delegate_res) > 0) {
            $menu_items[] = ['type' => 'link', 'href' => 'delegate_tasks.php', 'icon_class' => 'bi-person-check-fill', 'text' => 'Delegate My Tasks', 'roles' => ['teacher']];
        }
    }
}

// Helper functions for menu active state (could also be in a global functions file)
if (!function_exists('is_menu_active')) {
    function is_menu_active($href, $current_page_url) {
        if ($href === 'index.php' && $current_page_url === 'index.php') return true;
        if ($href !== 'index.php' && strpos($current_page_url, $href) !== false && !empty($href)) return true;
        return false;
    }
}
if (!function_exists('is_submenu_active')) {
    function is_submenu_active($submenu_items, $current_page_url) {
        foreach ($submenu_items as $item) {
            if (isset($item['submenu']) && is_submenu_active($item['submenu'], $current_page_url)) { // Check nested submenus
                return true;
            } elseif (isset($item['href']) && is_menu_active($item['href'], $current_page_url)) {
                return true;
            }
        }
        return false;
    }
}
?>

<!-- Offcanvas Sidebar (for mobile/tablet) -->
<nav class="offcanvas offcanvas-start text-white bg-dark" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasSidebarLabel" aria-label="Main navigation mobile">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasSidebarLabel"><i class="bi bi-list-nested me-2"></i>Menu</h5>
        <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body p-0">
        <ul class="nav nav-pills flex-column mb-auto p-3" role="navigation" aria-label="Main navigation mobile content">
            <?php foreach ($menu_items as $item_idx => $item): ?>
                <?php if (empty($item['roles']) || in_array($user_role, $item['roles'])): ?>
                    <?php if ($item['type'] == 'link'): ?>
                        <li class="nav-item">
                            <a href="<?php echo $item['href']; ?>"
                               class="nav-link text-white <?php echo is_menu_active($item['href'], $current_page_url) ? 'active' : ''; ?>"
                               title="<?php echo htmlspecialchars($item['text']); ?>"
                               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                                <i class="bi <?php echo $item['icon_class'] ?? 'bi-arrow-right-short'; ?> me-2"></i> <span><?php echo htmlspecialchars($item['text']); ?></span>
                            </a>
                        </li>
                    <?php elseif ($item['type'] == 'dropdown' && !empty($item['submenu'])):
                        $is_parent_active_mobile = is_submenu_active($item['submenu'], $current_page_url);
                        $mobile_submenu_id = $item['id'] . 'Mobile' . $item_idx; // Ensure unique ID for mobile
                    ?>
                        <li class="nav-item">
                            <a href="#<?php echo $mobile_submenu_id; ?>" data-bs-toggle="collapse"
                               aria-expanded="<?php echo $is_parent_active_mobile ? 'true' : 'false'; ?>"
                               aria-controls="<?php echo $mobile_submenu_id; ?>"
                               class="nav-link text-white <?php echo $is_parent_active_mobile ? 'active' : ''; ?>"
                               title="<?php echo htmlspecialchars($item['text']); ?>"
                               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                                <i class="bi <?php echo $item['icon_class'] ?? 'bi-collection-fill'; ?> me-2"></i> <span><?php echo htmlspecialchars($item['text']); ?></span>
                            </a>
                            <ul class="collapse nav flex-column ms-3 <?php echo $is_parent_active_mobile ? 'show' : ''; ?>" id="<?php echo $mobile_submenu_id; ?>" data-bs-parent="#offcanvasSidebar .nav">
                                <?php foreach ($item['submenu'] as $sub_item): ?>
                                     <?php if (empty($sub_item['roles']) || in_array($user_role, $sub_item['roles'])): ?>
                                        <?php if ($sub_item['type'] == 'dropdown' && !empty($sub_item['submenu'])):
                                            $is_sub_parent_active_mobile = is_submenu_active($sub_item['submenu'], $current_page_url);
                                            $mobile_sub_submenu_id = $sub_item['id'] . 'Mobile' . $item_idx;
                                        ?>
                                            <li class="nav-item">
                                                <a href="#<?php echo $mobile_sub_submenu_id; ?>" data-bs-toggle="collapse"
                                                   aria-expanded="<?php echo $is_sub_parent_active_mobile ? 'true' : 'false'; ?>"
                                                   aria-controls="<?php echo $mobile_sub_submenu_id; ?>"
                                                   class="nav-link text-white sub-item <?php echo $is_sub_parent_active_mobile ? 'active' : ''; ?>"
                                                   title="<?php echo htmlspecialchars($sub_item['text']); ?>">
                                                    <i class="bi <?php echo $sub_item['icon_class'] ?? 'bi-chevron-right'; ?> me-2"></i> <span><?php echo htmlspecialchars($sub_item['text']); ?></span>
                                                </a>
                                                <ul class="collapse nav flex-column ms-3 <?php echo $is_sub_parent_active_mobile ? 'show' : ''; ?>" id="<?php echo $mobile_sub_submenu_id; ?>" data-bs-parent="#<?php echo $mobile_submenu_id; ?>">
                                                    <?php foreach ($sub_item['submenu'] as $sub_sub_item): ?>
                                                        <li class="nav-item">
                                                            <a href="<?php echo $sub_sub_item['href']; ?>"
                                                               class="nav-link text-white sub-item <?php echo is_menu_active($sub_sub_item['href'], $current_page_url) ? 'active' : ''; ?>"
                                                               title="<?php echo htmlspecialchars($sub_sub_item['text']); ?>">
                                                                <span><?php echo htmlspecialchars($sub_sub_item['text']); ?></span>
                                                            </a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </li>
                                        <?php else: // Regular sub-item link ?>
                                            <li class="nav-item">
                                                <a href="<?php echo $sub_item['href']; ?>"
                                                   class="nav-link text-white sub-item <?php echo is_menu_active($sub_item['href'], $current_page_url) ? 'active' : ''; ?>"
                                                   title="<?php echo htmlspecialchars($sub_item['text']); ?>">
                                                    <i class="bi <?php echo $sub_item['icon_class'] ?? 'bi-arrow-right-short'; ?> me-2"></i> <span><?php echo htmlspecialchars($sub_item['text']); ?></span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
        <hr>
        <div class="p-3 user-profile-section dropdown"> <!-- User info at bottom of offcanvas -->
             <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUserOffcanvas" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo htmlspecialchars(current_username()); ?>">
                <i class="bi bi-person-circle me-2 fs-4"></i>
                <strong><?php echo htmlspecialchars(current_username()); ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUserOffcanvas">
                <li><span class="dropdown-item-text"><small>Role: <?php echo htmlspecialchars($user_role); ?></small></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Desktop Sidebar (Original one) - hidden on small screens -->
<nav class="d-none d-md-flex flex-column flex-shrink-0 text-white bg-dark vh-100 position-fixed" id="sidebar" aria-label="Main navigation desktop">
     <a href="index.php" class="d-flex align-items-center p-3 mb-0 me-md-auto text-white text-decoration-none">
        <i class="bi bi-calendar-check-fill me-2 fs-4"></i>
        <span class="fs-4 sidebar-text">Attendance Sys.</span>
    </a>
    <hr class="sidebar-text">
    <ul class="nav nav-pills flex-column mb-auto">
        <?php foreach ($menu_items as $item_idx => $item): ?>
            <?php if (empty($item['roles']) || in_array($user_role, $item['roles'])): ?>
                <?php if ($item['type'] == 'link'): ?>
                    <li class="nav-item">
                        <a href="<?php echo $item['href']; ?>"
                           class="nav-link text-white <?php echo is_menu_active($item['href'], $current_page_url) ? 'active' : ''; ?>"
                           title="<?php echo htmlspecialchars($item['text']); ?>"
                           data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                            <i class="bi <?php echo $item['icon_class'] ?? 'bi-arrow-right-short'; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($item['text']); ?></span>
                        </a>
                    </li>
                <?php elseif ($item['type'] == 'dropdown' && !empty($item['submenu'])):
                    $is_parent_active_desktop = is_submenu_active($item['submenu'], $current_page_url);
                    $desktop_submenu_id = $item['id'] . 'Desktop' . $item_idx; // Ensure unique ID for desktop
                ?>
                    <li class="nav-item">
                        <a href="#<?php echo $desktop_submenu_id; ?>" data-bs-toggle="collapse"
                           aria-expanded="<?php echo $is_parent_active_desktop ? 'true' : 'false'; ?>"
                           aria-controls="<?php echo $desktop_submenu_id; ?>"
                           class="nav-link text-white <?php echo $is_parent_active_desktop ? 'active' : ''; ?>"
                           title="<?php echo htmlspecialchars($item['text']); ?>"
                           data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                            <i class="bi <?php echo $item['icon_class'] ?? 'bi-collection-fill'; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($item['text']); ?></span>
                        </a>
                        <ul class="collapse nav flex-column ms-1 <?php echo $is_parent_active_desktop ? 'show' : ''; ?>" id="<?php echo $desktop_submenu_id; ?>" data-bs-parent="#sidebar .nav">
                            <?php foreach ($item['submenu'] as $sub_item_idx => $sub_item): ?>
                                 <?php if (empty($sub_item['roles']) || in_array($user_role, $sub_item['roles'])): ?>
                                    <?php if ($sub_item['type'] == 'dropdown' && !empty($sub_item['submenu'])):
                                        $is_sub_parent_active_desktop = is_submenu_active($sub_item['submenu'], $current_page_url);
                                        $desktop_sub_submenu_id = $sub_item['id'] . 'Desktop' . $item_idx . '_' . $sub_item_idx;
                                    ?>
                                        <li class="nav-item">
                                             <a href="#<?php echo $desktop_sub_submenu_id; ?>" data-bs-toggle="collapse"
                                               aria-expanded="<?php echo $is_sub_parent_active_desktop ? 'true' : 'false'; ?>"
                                               aria-controls="<?php echo $desktop_sub_submenu_id; ?>"
                                               class="nav-link text-white sub-item <?php echo $is_sub_parent_active_desktop ? 'active' : ''; ?>"
                                               title="<?php echo htmlspecialchars($sub_item['text']); ?>">
                                                <i class="bi <?php echo $sub_item['icon_class'] ?? 'bi-chevron-right'; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($sub_item['text']); ?></span>
                                            </a>
                                            <ul class="collapse nav flex-column ms-3 <?php echo $is_sub_parent_active_desktop ? 'show' : ''; ?>" id="<?php echo $desktop_sub_submenu_id; ?>" data-bs-parent="#<?php echo $desktop_submenu_id; ?>">
                                                <?php foreach ($sub_item['submenu'] as $sub_sub_item): ?>
                                                    <li class="nav-item">
                                                        <a href="<?php echo $sub_sub_item['href']; ?>"
                                                           class="nav-link text-white sub-item <?php echo is_menu_active($sub_sub_item['href'], $current_page_url) ? 'active' : ''; ?>"
                                                           title="<?php echo htmlspecialchars($sub_sub_item['text']); ?>"
                                                           data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                                                            <i class="bi <?php echo $sub_sub_item['icon_class'] ?? 'bi-dot'; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($sub_sub_item['text']); ?></span>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php else: // Regular sub-item link ?>
                                        <li class="nav-item">
                                            <a href="<?php echo $sub_item['href']; ?>"
                                               class="nav-link text-white sub-item <?php echo is_menu_active($sub_item['href'], $current_page_url) ? 'active' : ''; ?>"
                                               title="<?php echo htmlspecialchars($sub_item['text']); ?>"
                                               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-trigger="hover" data-bs-custom-class="sidebar-tooltip">
                                                <i class="bi <?php echo $sub_item['icon_class'] ?? 'bi-arrow-right-short'; ?> me-2"></i> <span class="sidebar-text"><?php echo htmlspecialchars($sub_item['text']); ?></span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <div id="sidebarToggleBtnContainer" class="mt-auto sidebar-text p-3">
         <button class="btn btn-sm btn-outline-light w-100" id="sidebarToggleBtn" title="Toggle Sidebar"
                 aria-controls="sidebar"
                 aria-expanded="<?php echo (isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true') ? 'false' : 'true'; /* JS will update this */ ?>">
            <i class="bi bi-arrow-bar-left"></i> <span class="sidebar-text">Collapse</span>
        </button>
    </div>
    <hr class="sidebar-text">
    <div class="dropdown p-3 user-profile-section">
         <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUserDesktop" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo htmlspecialchars(current_username()); ?>">
            <i class="bi bi-person-circle me-2 fs-4"></i>
            <strong class="sidebar-text"><?php echo htmlspecialchars(current_username()); ?></strong>
        </a>
        <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUserDesktop">
            <li><span class="dropdown-item-text user-info-detailed-role"><small>Role: <?php echo htmlspecialchars($user_role); ?></small></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
        </ul>
    </div>
</nav>
<!-- End Desktop Sidebar -->

<?php // Note: The main-content-wrapper and page_footer.inc.php include will be in layout_authenticated.php ?>
