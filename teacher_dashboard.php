<?php
include_once 'auth_check.php';
require_login(['teacher']); // Ensure only teacher can access (admin might have a different view or use admin_dashboard)

$page_title = "Teacher Dashboard";

include 'config.php'; // For DB connection
include 'functions.php'; // For get_teacher_attendance_accessible_sections if needed, or direct query

$current_user_id = current_user_id();
$assigned_class_sections = [];
$delegated_attendance_sections = []; // Sections where attendance is delegated TO this teacher

// Fetch class sections where this teacher is the CLASS TEACHER
$assigned_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name, cs.academic_year
                 FROM class_sections cs
                 JOIN grades g ON cs.grade_id = g.id
                 JOIN divisions d ON cs.division_id = d.id
                 WHERE cs.class_teacher_user_id = $current_user_id
                 ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$assigned_res = mysqli_query($conn, $assigned_sql);
if ($assigned_res) {
    while ($row = mysqli_fetch_assoc($assigned_res)) {
        $assigned_class_sections[] = $row;
    }
}

// Fetch class sections where this teacher has DELEGATED ATTENDANCE permission
$delegated_sql = "SELECT cs.id, IFNULL(cs.section_name, CONCAT(g.grade_name, ' - ', d.division_name, ' (', cs.academic_year, ')')) as display_name, cs.academic_year
                  FROM teacher_delegations td
                  JOIN class_sections cs ON td.class_section_id = cs.id
                  JOIN grades g ON cs.grade_id = g.id
                  JOIN divisions d ON cs.division_id = d.id
                  WHERE td.delegated_to_user_id = $current_user_id AND td.can_take_attendance = 1
                  AND cs.class_teacher_user_id != $current_user_id /* Exclude their own classes already listed above */
                  ORDER BY cs.academic_year DESC, g.grade_name, d.division_name";
$delegated_res = mysqli_query($conn, $delegated_sql);
if($delegated_res){
    while($row = mysqli_fetch_assoc($delegated_res)){
        // Avoid duplicates if a section was somehow listed in both (though logic should prevent)
        $is_already_assigned = false;
        foreach($assigned_class_sections as $acs){
            if($acs['id'] == $row['id']) {
                $is_already_assigned = true;
                break;
            }
        }
        if(!$is_already_assigned){
            $delegated_attendance_sections[] = $row;
        }
    }
}


// Start output buffering for page content
ob_start();
?>

<div class="container-fluid mt-3">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4"><?php echo htmlspecialchars($page_title); ?></h1>
            <p>Welcome, <?php echo htmlspecialchars(current_username()); ?>! Here are your classes and quick actions.</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-8">
            <?php if (!empty($assigned_class_sections)): ?>
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-journal-richtext me-2"></i> Your Assigned Class Sections (as Class Teacher)
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($assigned_class_sections as $section): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-easel2 me-1"></i> <?php echo htmlspecialchars($section['display_name']); ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($section['academic_year']); ?>)</small>
                            </span>
                            <a href="attendance.php?report_date=<?php echo date('Y-m-d'); ?>&class_section_id=<?php echo $section['id']; ?>&fetch_students=1" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-calendar-check me-1"></i> Take Today's Attendance
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (!empty($delegated_attendance_sections)): ?>
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                   <i class="bi bi-person-check-fill me-2"></i> Delegated Attendance Responsibilities
                </div>
                 <ul class="list-group list-group-flush">
                    <?php foreach ($delegated_attendance_sections as $section): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-easel2 me-1"></i> <?php echo htmlspecialchars($section['display_name']); ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($section['academic_year']); ?>)</small>
                            </span>
                            <a href="attendance.php?report_date=<?php echo date('Y-m-d'); ?>&class_section_id=<?php echo $section['id']; ?>&fetch_students=1" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-calendar-check me-1"></i> Take Today's Attendance
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (empty($assigned_class_sections) && empty($delegated_attendance_sections)): ?>
                <div class="alert alert-info">You are not currently assigned as a class teacher or delegated for attendance for any class sections.</div>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-card-checklist me-2"></i> Quick Links
                </div>
                <div class="list-group list-group-flush">
                    <a href="students.php" class="list-group-item list-group-item-action"><i class="bi bi-people-fill me-2"></i> Manage My Students</a>
                    <a href="attendance.php" class="list-group-item list-group-item-action"><i class="bi bi-calendar-range me-2"></i> View Full Attendance Page</a>
                     <?php if (current_user_role() == 'teacher'): // Assuming only class teachers or admins can delegate ?>
                        <a href="delegate_tasks.php" class="list-group-item list-group-item-action"><i class="bi bi-person-gear me-2"></i> Delegate Tasks</a>
                    <?php endif; ?>
                    <a href="reports_daily_attendance.php" class="list-group-item list-group-item-action"><i class="bi bi-file-earmark-text me-2"></i> View Reports</a>

                </div>
            </div>
            <div class="card">
                <div class="card-header bg-warning">
                    <i class="bi bi-bell-fill me-2"></i> Pending Items / Alerts
                </div>
                <div class="card-body">
                    <p class="card-text"><em>(Placeholder for pending student leave requests or other alerts relevant to the teacher.)</em></p>
                    <p class="card-text"><small>E.g., 2 Unread Parent Messages, 1 Pending Leave Approval.</small></p>
                    <a href="#" class="btn btn-sm btn-outline-primary disabled">View All Alerts (Future)</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$page_content_html = ob_get_clean();
if(isset($conn)) mysqli_close($conn);
include 'layout_authenticated.php';
?>
