<?php
// This file assumes auth_check.php (providing current_user_role(), current_username())
// has been included by the page that includes layout_authenticated.php, which then includes this file.
// No direct session_start() or auth_check.php include here to avoid re-declaration issues.
?>
<!-- Persistent Top Header -->
<nav class="navbar navbar-expand-md navbar-dark bg-dark fixed-top top-header no-print shadow-sm">
    <div class="container-fluid">
        <!-- Mobile Sidebar Toggle Button (visible on small screens) -->
        <button class="navbar-toggler d-md-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar" aria-label="Toggle sidebar navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Project Name/Brand -->
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-calendar-check-fill me-1"></i> <!-- Optional: Project Icon -->
            Attendance System
        </a>

        <!-- Navbar items on the right (use a div to group them for ms-auto) -->
        <div class="d-flex align-items-center ms-auto">
            <!-- Notification Dropdown -->
            <div class="nav-item dropdown me-2 me-md-3">
                <a href="#" class="nav-link text-white position-relative" id="notificationDropdownToggle" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                    <i class="bi bi-bell-fill fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light" id="notificationBadge" style="font-size: 0.6em; display: none; /* Initially hidden, show with JS */">
                        3 <span class="visually-hidden">unread notifications</span>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark" aria-labelledby="notificationDropdownToggle">
                    <li><h6 class="dropdown-header">Notifications</h6></li>
                    <li><a class="dropdown-item" href="#"><small><i class="bi bi-info-circle me-2"></i>Notification 1 (placeholder)...</small></a></li>
                    <li><a class="dropdown-item" href="#"><small><i class="bi bi-info-circle me-2"></i>Notification 2 (placeholder)...</small></a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-center" href="#"><small>View all notifications (placeholder)</small></a></li>
                </ul>
            </div>

            <!-- Logout Link -->
            <div class="nav-item">
                <a href="logout.php" class="nav-link text-white" title="Logout">
                    <i class="bi bi-box-arrow-right fs-5 me-md-1"></i>
                    <span class="d-none d-md-inline">Logout</span>
                </a>
            </div>
        </div>
    </div>
</nav>
<!-- End Persistent Top Header -->
