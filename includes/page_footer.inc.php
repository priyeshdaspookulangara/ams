<?php
// This file is intended to be included at the very end of the HTML body.
// It contains common JavaScript includes and custom inline scripts for the layout.
?>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
    $(document).ready(function() {
        const $desktopSidebar = $('#sidebar');
        const $desktopSidebarToggleBtn = $('#sidebarToggleBtn');
        const $desktopSidebarToggleBtnIcon = $desktopSidebarToggleBtn.find('i');
        const $desktopSidebarToggleBtnText = $desktopSidebarToggleBtn.find('span.sidebar-text');
        const $body = $('body');

        function setSidebarState(isCollapsed) {
            if (!$desktopSidebar.length) return;

            if (isCollapsed) {
                $desktopSidebar.addClass('collapsed');
                $body.removeClass('sidebar-expanded').addClass('sidebar-contracted');
                if ($desktopSidebarToggleBtnText.length) {
                    $desktopSidebarToggleBtnText.hide();
                }
                if ($desktopSidebarToggleBtnIcon.length) {
                    $desktopSidebarToggleBtnIcon.removeClass('bi-arrow-bar-left').addClass('bi-arrow-bar-right');
                }
                if ($desktopSidebarToggleBtn.length) {
                    $desktopSidebarToggleBtn.attr('aria-expanded', 'false');
                }
            } else {
                $desktopSidebar.removeClass('collapsed');
                $body.removeClass('sidebar-contracted').addClass('sidebar-expanded');
                if ($desktopSidebarToggleBtnText.length) {
                    $desktopSidebarToggleBtnText.show().text('Collapse');
                }
                if ($desktopSidebarToggleBtnIcon.length) {
                    $desktopSidebarToggleBtnIcon.removeClass('bi-arrow-bar-right').addClass('bi-arrow-bar-left');
                }
                if ($desktopSidebarToggleBtn.length) {
                    $desktopSidebarToggleBtn.attr('aria-expanded', 'true');
                }
            }
        }

        let initialCollapsedState = localStorage.getItem('sidebarCollapsed') === 'true';
        setSidebarState(initialCollapsedState);
        // Ensure body class is set on initial load for main content margin
        // This logic was slightly off, body class should be applied regardless of sidebar existing for layout structure.
        if(initialCollapsedState) {
            $body.addClass('sidebar-contracted').removeClass('sidebar-expanded');
        } else {
            $body.addClass('sidebar-expanded').removeClass('sidebar-contracted');
        }

        if ($desktopSidebarToggleBtn.length) {
            $desktopSidebarToggleBtn.on('click', function() {
                let currentIsCollapsed = $desktopSidebar.hasClass('collapsed');
                let newStateIsCollapsed = !currentIsCollapsed;

                setSidebarState(newStateIsCollapsed);
                localStorage.setItem('sidebarCollapsed', newStateIsCollapsed);
            });
        }

        // Initialize Bootstrap Tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl, {
            container: 'body'
          });
        });

        // Off-canvas menu item click - close offcanvas (if not handled by Bootstrap default on all links)
        // Bootstrap usually handles this for simple <a> links within offcanvas.
        // $('#offcanvasSidebar .nav-link').on('click', function(){
        //    var myOffcanvas = document.getElementById('offcanvasSidebar');
        //    var bsOffcanvas = bootstrap.Offcanvas.getInstance(myOffcanvas);
        //    if(bsOffcanvas) {
        //        bsOffcanvas.hide();
        //    }
        // });
    });
</script>
