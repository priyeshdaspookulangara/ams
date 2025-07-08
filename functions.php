<?php

// Function to fetch current settings
function get_settings($conn) {
    $sql_get_settings = "SELECT * FROM settings WHERE id = 1";
    $result = mysqli_query($conn, $sql_get_settings);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    // Fallback to sensible defaults if settings are somehow missing
    return [
        'notification_type' => 'none',
        'sms_template_single_absence' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) was absent on {current_date}. Contact office: {office_number}.',
        'sms_template_multiple_absences' => 'Dear {parent_name}, {student_name} (Roll No: {student_rollnumber}) has been absent for {consecutive_days} days, including today ({current_date}). Please contact office: {office_number} urgently.',
        'consecutive_absence_threshold' => 3,
        'office_number' => 'N/A'
    ];
}

// Function to format notification messages
function format_notification_message($template, $data) {
    foreach ($data as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }
    return $template;
}

// Placeholder function to "send" notifications
function send_notification($phoneNumber, $message, $type, $studentNameForLog = "N/A") {
    $log_message = "[" . date("Y-m-d H:i:s") . "] Notification Sent (Placeholder)\n";
    $log_message .= "Type: " . strtoupper($type) . "\n";
    $log_message .= "To: " . $phoneNumber . "\n";
    $log_message .= "For Student: " . $studentNameForLog . "\n";
    $log_message .= "Message: " . $message . "\n";
    $log_message .= "-------------------------------------------------\n";

    if (!isset($_SESSION['notification_log'])) {
        $_SESSION['notification_log'] = [];
    }
    $_SESSION['notification_log'][] = nl2br(htmlspecialchars($log_message));
    return true;
}

// Function to check for consecutive absences
function check_consecutive_absences($student_id, $current_attendance_date_str, $conn) {
    $consecutive_days = 0;
    // Ensure date is in Y-m-d for comparison
    $current_date = date('Y-m-d', strtotime($current_attendance_date_str));

    // Start checking from the current date and go backwards
    for ($i = 0; ; $i++) {
        $check_date_obj = new DateTime($current_date);
        $check_date_obj->modify("-$i days");
        $check_date_str = $check_date_obj->format('Y-m-d');

        // Skip weekends (Saturday, Sunday) - Optional, depending on school policy
        $day_of_week = $check_date_obj->format('N'); // 1 (for Monday) through 7 (for Sunday)
        if ($day_of_week == 6 || $day_of_week == 7) {
            // If today is a weekend and we are checking it, it means no school, so not an absence.
            // If we are iterating backwards and hit a weekend, we should continue checking the previous school day.
            // This logic assumes attendance is only taken on weekdays.
            // If $i is 0 (current day is weekend), this student can't be marked absent for this day.
            if ($i == 0 && ($day_of_week == 6 || $day_of_week == 7) ) return 0;
            // If we're iterating backwards and hit a weekend, this day doesn't break consecutiveness,
            // but it also doesn't count towards it. So, we just continue the loop to the previous day.
            // However, the $i still increments, so we need to effectively extend our search window.
            // A simpler way is to just check if the record exists and is_present = 0.
            // If school policy is to count weekends in consecutive absences if student is absent Friday and Monday,
            // then this weekend skipping logic should be removed or adjusted.
            // For now, let's assume school days only.
        }

        $sql = "SELECT is_present FROM attendance_records
                WHERE student_id = $student_id AND attendance_date = '$check_date_str'";
        $result = mysqli_query($conn, $sql);

        if ($result && mysqli_num_rows($result) > 0) {
            $record = mysqli_fetch_assoc($result);
            if ($record['is_present'] == 0) { // Student was absent
                // If the day is a weekend, but an absence was marked (unlikely but possible), count it.
                // Or, more typically, if it's a weekday and absent.
                $consecutive_days++;
            } else { // Student was present or record exists and is_present is true
                break; // Streak broken
            }
        } else {
            // No record for this day.
            // If $i = 0 (current day), it means attendance not yet taken or student is considered present by default if no record.
            // For consecutive check, if no record on a *previous* working day, it implies the student was present or it was a holiday.
            // This means the streak of *absences* is broken.
            // However, if today is the first day of absence, and no prior records, consecutive_days will be 1.
            if ($i == 0) { // If it's the current day and no record (implies absent if this function is called after marking them absent)
                 // This case is tricky: this function is called AFTER a student is marked absent for current_attendance_date_str.
                 // So, if $i=0, we should assume they are absent for this day.
                 // The actual record might not be committed yet if called mid-transaction, but logic implies it.
                 // Let's assume the function is called after the day's absence is recorded.
                 // So if $i=0 and no record, it's an issue or means they were not marked absent yet.
                 // Given the context, we assume the current day's absence is a fact.
                 // The loop structure handles the current day's absence implicitly if it's marked.
                 // If there's no record for a *previous* day, the streak is broken.
            } else {
                 // No record for a previous day breaks the streak of recorded absences.
                break;
            }
            // If $i=0 and no record, and we are here, it means the current day's absence is what we are checking.
            // The logic below handles this. If this function is called for an absent student on current_attendance_date_str,
            // their absence for the current day should be counted.
            // The current implementation relies on the current day's absence being in the DB.
            // Let's adjust: the function is called for a student *known* to be absent today.
            // So, if $i=0, we count 1, then check previous days.

            // If checking historical dates and no record is found for a weekday, assume present or holiday.
            // This breaks the consecutive absence streak.
            // Exception: if $i=0 (current day), this day's absence is the trigger, so it counts as 1.
            // The way the loop is structured, if current day is absent, $consecutive_days will be at least 1.
            // If no record for a *previous* day, streak broken.
            if ($i > 0) break;
            // If $i == 0 and no record, it means current day's attendance not in DB.
            // This function expects the current day's absence to be recorded before it's called for accurate count.
            // For robustness, if $i == 0 and no record, we can assume this is the first day of absence being recorded.
            // However, the most reliable way is to ensure current day's record is in DB.
            // Let's assume the calling code ensures current day's absence is recorded.
            // So, if no record for $check_date_str where $i > 0, streak is broken.
        }
    }
    return $consecutive_days;
}
?>
