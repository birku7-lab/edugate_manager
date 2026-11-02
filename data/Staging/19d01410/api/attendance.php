<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'scan') {
        $qr_code = $_POST['qr_code'] ?? '';
        $date = date('Y-m-d');
        $time = date('H:i:s');

        // Offline Sync Override
        if (isset($_POST['timestamp']) && !empty($_POST['timestamp'])) {
            $offline_time = strtotime($_POST['timestamp']);
            if ($offline_time) {
                $date = date('Y-m-d', $offline_time);
                $time = date('H:i:s', $offline_time);
            }
        }
        
        if(empty($qr_code)) {
            echo json_encode(["status" => "error", "message" => "No QR code provided."]);
            exit;
        }

        try {
            // Find student
            $stmt = $pdo->prepare("SELECT id, full_name, admission_number, class, gender, photo, assigned_bus_id, parent_phone FROM students WHERE qr_code = ?");
            $stmt->execute([$qr_code]);
            $student = $stmt->fetch();
            
            if (!$student) {
                echo json_encode(["status" => "error", "message" => "Invalid QR Code. Student not found."]);
                exit;
            }

            $mode = $_GET['mode'] ?? 'attendance';
            $warningMsg = null;

            // Fetch Settings
            $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settings = [];
            while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // ── Billing Verification ──
            if (($settings['billing_verify_enabled'] ?? '0') === '1') {
                $curYear = $settings['academic_year'] ?? '';
                $curTerm = $settings['current_term'] ?? '';
                
                $billingCategory = 'School Fees';
                $categoryIdFilter = null;
                if ($mode === 'meals') $billingCategory = 'Meals';
                if ($mode === 'transport') $billingCategory = 'Transport';
                if (strpos($mode, 'cat_') === 0) {
                    $categoryIdFilter = intval(str_replace('cat_', '', $mode));
                }

                // Fetch student billing for enforced categories OR the default category for this mode
                if ($categoryIdFilter !== null) {
                    $billStmt = $pdo->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.id = ?)
                    ");
                    $billStmt->execute([$student['id'], $curYear, $curTerm, $categoryIdFilter]);
                } else {
                    $billStmt = $pdo->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.name = ?)
                    ");
                    $billStmt->execute([$student['id'], $curYear, $curTerm, $billingCategory]);
                }
                $bills = $billStmt->fetchAll(PDO::FETCH_ASSOC);

                $totalBalance = 0;
                $denyCategory = null;
                $warnCategories = [];
                $strictDenial = false;

                foreach ($bills as $bill) {
                    $pStatus = $bill['payment_status'];
                    $dueDate = $bill['due_date'];
                    $grace   = intval($bill['grace_period_days']);
                    
                    if ($pStatus !== 'Paid' && $pStatus !== 'Exempt' && !empty($dueDate)) {
                        $today = new DateTime('today');
                        try {
                            $due = new DateTime($dueDate);
                            $due->setTime(0,0,0);
                            $graceEnd = (clone $due)->modify("+$grace days");
                            if ($today > $graceEnd) {
                                $pStatus = 'Overdue';
                            } elseif ($today > $due && $today <= $graceEnd) {
                                $pStatus = 'Grace Period';
                            }
                        } catch (Exception $e) {}
                    }

                    $balance = floatval($bill['amount_due']) - floatval($bill['amount_paid']);
                    if ($balance > 0) {
                        $totalBalance += $balance;
                    }
                    
                    $targetPercent = floatval($settings['fee_policy_target_percentage'] ?? 0);
                    $paidPercent = floatval($bill['amount_due']) > 0 ? (floatval($bill['amount_paid']) / floatval($bill['amount_due'])) * 100 : 100;
                    
                    $rule = 'Allow';
                    
                    if ($balance > 0 && ($grace === 0 || $paidPercent < $targetPercent)) {
                        $rule = 'Deny';
                        $strictDenial = true;
                    } else {
                        if ($pStatus === 'Unpaid') $rule = $settings['billing_unpaid_rule'] ?? 'Allow';
                        elseif ($pStatus === 'Partial') $rule = $settings['billing_partial_rule'] ?? 'Allow';
                        elseif ($pStatus === 'Overdue') $rule = $settings['billing_overdue_rule'] ?? 'Deny';
                        elseif ($pStatus === 'Grace Period') $rule = $settings['billing_grace_rule'] ?? 'Warn';
                    }
                    
                    if ($rule === 'Deny') {
                        $denyCategory = $bill['name'];
                        break; // Stop checking, one denial is enough
                    } elseif ($rule === 'Warn') {
                        $warnCategories[] = $bill['name'];
                    }
                }

                if ($denyCategory) {
                    if ($strictDenial) {
                        $msg = "Not allowed, clear your fee first. Balance: Ksh " . number_format($totalBalance, 2);
                        
                        if (($settings['sms_enable_denial'] ?? '0') === '1' && !empty($student['parent_phone'])) {
                            require_once 'sms.php';
                            $apiKey = $settings['sms_api_token'] ?? '';
                            $senderId = $settings['sms_sender_id'] ?? '';
                            
                            if (!empty($apiKey)) {
                                $smsTemplate = $settings['sms_template_denial'] ?? 'Dear Parent, {Student Name} was not allowed into school today because their fee balance is Ksh {Balance}. Please clear the fee immediately. The student has been sent home. For inquiries, contact us at {School Phone}.';
                                
                                $smsMsg = str_replace(
                                    ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Balance}', '{School Phone}'],
                                    [$student['full_name'], $student['parent_name'], $student['admission_number'], $student['class'], $settings['school_name'] ?? 'School Admin', number_format($totalBalance, 2), $settings['school_phone'] ?? ''],
                                    $smsTemplate
                                );
                                
                                $smsRes = sendBulkSMS([$student['parent_phone']], $smsMsg, $senderId, $apiKey);
                                $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
                                
                                $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                                $logStmt->execute([
                                    $student['id'], $student['parent_phone'], $smsMsg, $smsStatus, json_encode($smsRes), $smsRes['error'] ?? ''
                                ]);
                            }
                        }
                    } else {
                        $msg = $settings['billing_denial_message'] ?? 'Access Denied: Outstanding Fees.';
                        $msg = str_replace('{balance}', number_format($totalBalance, 2), $msg);
                    }
                    echo json_encode(["status" => "error", "message" => $msg, "data" => $student]);
                    exit;
                } elseif (count($warnCategories) > 0) {
                    $msg = $settings['billing_warning_message'] ?? 'Warning: Outstanding Fees.';
                    $warningMsg = str_replace('{balance}', number_format($totalBalance, 2), $msg);
                }
            }
            // ── End Billing Verification ──

            if ($mode === 'meals') {
                // Check if already scanned today
                $check_stmt = $pdo->prepare("SELECT id FROM meal_logs WHERE student_id = ? AND meal_date = ?");
                $check_stmt->execute([$student['id'], $date]);
                if ($check_stmt->fetch()) {
                    echo json_encode(["status" => "duplicate", "message" => "Already had a meal today", "data" => $student]);
                    exit;
                }

                $insert_stmt = $pdo->prepare("INSERT INTO meal_logs (student_id, meal_date, meal_type, scanned_by) VALUES (?, ?, 'Normal', ?)");
                $insert_stmt->execute([$student['id'], $date, $current_user['id'] ?? null]);
                log_audit($pdo, $current_user['id'] ?? null, 'MEAL_SCAN', "Recorded meal for student ID: {$student['id']}");
                $student['scan_time'] = $time;
                echo json_encode([
                    "status" => $warningMsg ? "warning" : "success", 
                    "message" => $warningMsg ? $warningMsg : "Meal Recorded Successfully", 
                    "data" => $student
                ]);
                exit;
            }

            if ($mode === 'transport') {
                if (empty($student['assigned_bus_id'])) {
                    echo json_encode(["status" => "error", "message" => "Student is not assigned to any bus.", "data" => $student]);
                    exit;
                }
                
                // Get scanner user's bus
                $staff_stmt = $pdo->prepare("SELECT assigned_bus_id FROM users WHERE id = ?");
                $staff_stmt->execute([$current_user['id'] ?? 0]);
                $staff = $staff_stmt->fetch();
                if ($staff && !empty($staff['assigned_bus_id']) && $student['assigned_bus_id'] != $staff['assigned_bus_id']) {
                    echo json_encode(["status" => "error", "message" => "Wrong Bus! Student is assigned to a different bus.", "data" => $student]);
                    exit;
                }
                
                $check_stmt = $pdo->prepare("SELECT board_time FROM transport_logs WHERE student_id = ? AND board_date = ?");
                $check_stmt->execute([$student['id'], $date]);
                $existing = $check_stmt->fetch();
                if ($existing) {
                    echo json_encode(["status" => "duplicate", "message" => "Already boarded today", "data" => $student, "first_scan_time" => $existing['board_time']]);
                    exit;
                }

                $insert_stmt = $pdo->prepare("INSERT INTO transport_logs (student_id, bus_id, board_date, board_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->execute([$student['id'], $student['assigned_bus_id'], $date, $time, $current_user['id'] ?? null]);
                log_audit($pdo, $current_user['id'] ?? null, 'TRANSPORT_SCAN', "Recorded transport boarding for student ID: {$student['id']}");

                // --- SMS Trigger for Transport ---
                if (!empty($student['parent_phone']) && ($settings['sms_enable_transport'] ?? 'true') === 'true') {
                    $apiKey = $settings['sms_api_token'] ?? '';
                    $senderId = $settings['sms_sender_id'] ?? '';
                    if (!empty($apiKey)) {
                        $schoolName = $settings['school_name'] ?? 'School System';
                        $msg = "Hello, {$student['full_name']} has boarded the bus and left school. - {$schoolName}";
                        
                        if (!empty($settings['sms_template_transport'])) {
                            $msg = str_replace(
                                ['{Student Name}', '{School Name}', '{Class}', '{TIME}'],
                                [$student['full_name'], $schoolName, $student['class'], date('h:i A')],
                                $settings['sms_template_transport']
                            );
                        }

                        $smsRes = sendBulkSMS([$student['parent_phone']], $msg, $senderId, $apiKey);
                        $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
                        $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $logStmt->execute([
                            $student['id'], $student['parent_phone'], $msg, $smsStatus,
                            json_encode($smsRes), $smsRes['error'] ?? ''
                        ]);
                    }
                }
                // ---------------------------------
                
                $student['scan_time'] = $time;
                echo json_encode([
                    "status" => $warningMsg ? "warning" : "success", 
                    "message" => $warningMsg ? $warningMsg : "Boarding Recorded Successfully", 
                    "data" => $student
                ]);
                exit;
            }

            // --- CUSTOM CATEGORY SCANNER ---
            if ($categoryIdFilter !== null) {
                // Check if already scanned today for this category
                $check_stmt = $pdo->prepare("SELECT scan_time FROM custom_scanner_logs WHERE student_id = ? AND category_id = ? AND scan_date = ?");
                $check_stmt->execute([$student['id'], $categoryIdFilter, $date]);
                $existing = $check_stmt->fetch();
                if ($existing) {
                    echo json_encode(["status" => "duplicate", "message" => "Already scanned today", "data" => $student, "first_scan_time" => $existing['scan_time']]);
                    exit;
                }

                $insert_stmt = $pdo->prepare("INSERT INTO custom_scanner_logs (student_id, category_id, scan_date, scan_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->execute([$student['id'], $categoryIdFilter, $date, $time, $current_user['id'] ?? null]);
                log_audit($pdo, $current_user['id'] ?? null, 'CUSTOM_SCAN', "Recorded custom scan (cat_id: $categoryIdFilter) for student ID: {$student['id']}");
                $student['scan_time'] = $time;
                echo json_encode([
                    "status" => $warningMsg ? "warning" : "success", 
                    "message" => $warningMsg ? $warningMsg : "Scan Recorded Successfully", 
                    "data" => $student
                ]);
                exit;
            }

            // --- ATTENDANCE SCANNER ---
            
            // Check for duplicate scan
            $check_stmt = $pdo->prepare("SELECT arrival_time FROM attendance WHERE student_id = ? AND attendance_date = ?");
            $check_stmt->execute([$student['id'], $date]);
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                echo json_encode([
                    "status" => "duplicate", 
                    "message" => "Already Checked In Today", 
                    "data" => $student,
                    "first_scan_time" => $existing['arrival_time']
                ]);
                exit;
            }

            // Check Holidays
            $holidays_stmt = $pdo->prepare("SELECT name FROM holidays WHERE ? BETWEEN start_date AND end_date");
            $holidays_stmt->execute([$date]);
            $holiday = $holidays_stmt->fetch();
            if ($holiday) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Holiday: " . $holiday['name']]);
                exit;
            }

            // Check Weekends
            $day_of_week = date('D'); // Mon, Tue, etc.
            $school_days = json_decode($settings['school_days'] ?? '{}', true);
            if (isset($school_days[$day_of_week]) && $school_days[$day_of_week] == false) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Non-School Day."]);
                exit;
            }

            // Determine Status
            $status = 'Present';
            $late_time = $settings['late_arrival_time'] ?? '07:30';
            $cutoff_time = $settings['attendance_cutoff_time'] ?? '09:00';
            
            // Format check to ensure time strings are comparable
            if (strlen($late_time) == 5) $late_time .= ':00';
            if (strlen($cutoff_time) == 5) $cutoff_time .= ':00';

            if ($time > $cutoff_time) {
                $status = 'Absent';
            } elseif ($time > $late_time) {
                $status = 'Late';
            }

            // Insert attendance
            $insert_stmt = $pdo->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$student['id'], $date, $time, $status]);
            
            // Trigger sync update
            log_audit($pdo, $current_user['id'] ?? null, 'ATTENDANCE_SCAN', "Recorded attendance for student ID: {$student['id']}");
            
            // --- SMS Trigger for Attendance ---
            try {
                if (!empty($student['parent_phone']) && ($settings['sms_enable_attendance'] ?? 'true') === 'true') {
                    $apiKey = $settings['sms_api_token'] ?? '';
                    $senderId = $settings['sms_sender_id'] ?? '';
                    $schoolName = $settings['school_name'] ?? 'School Admin';
                    
                    if (!empty($apiKey)) {
                        $msgTime = date('h:i A', strtotime($time));
                        $msg = "Hello, your child " . $student['full_name'] . " has safely arrived at school today at " . $msgTime . ". Thank you. - " . $schoolName;
                        
                        $smsRes = sendBulkSMS([$student['parent_phone']], $msg, $senderId, $apiKey);
                        $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
                        
                        $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $logStmt->execute([
                            $student['id'],
                            $student['parent_phone'],
                            $msg,
                            $smsStatus,
                            json_encode($smsRes),
                            $smsRes['error'] ?? ''
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("Attendance SMS sending failed: " . $ex->getMessage());
            }
            // ----------------------------------
            
            $smsFeedback = "";
            if (isset($smsStatus)) {
                if ($smsStatus === 'Sent') {
                    $smsFeedback = " (SMS Sent)";
                } else {
                    $smsFeedback = " (SMS Failed)";
                }
            } else {
                if (empty($student['parent_phone'])) {
                    $smsFeedback = " (No SMS: No Parent Phone)";
                } elseif (empty($apiKey)) {
                    $smsFeedback = " (No SMS: API Key missing)";
                }
            }

            $student['arrival_time'] = $time;
            $student['status'] = $status;
            
            $baseMessage = $warningMsg ? $warningMsg : "Attendance Recorded ($status)";
            
            $resp = [
                "status" => $warningMsg ? "warning" : "success", 
                "message" => $baseMessage . $smsFeedback, 
                "data" => $student
            ];
            echo json_encode($resp);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'records') {
        // Advanced filters
        $date = $_GET['date'] ?? '';
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $student_name = $_GET['student_name'] ?? '';
        $admission_number = $_GET['admission_number'] ?? '';
        $class = $_GET['class'] ?? '';
        $gender = $_GET['gender'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $query = "SELECT a.id, a.attendance_date, a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.gender 
                  FROM attendance a 
                  JOIN students s ON a.student_id = s.id 
                  WHERE 1=1";
        $params = [];
        
        if (!empty($date)) {
            $query .= " AND a.attendance_date = ?";
            $params[] = $date;
        }
        if (!empty($start_date) && !empty($end_date)) {
            $query .= " AND a.attendance_date BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }
        if (!empty($student_name)) {
            $query .= " AND s.full_name LIKE ?";
            $params[] = "%$student_name%";
        }
        if (!empty($admission_number)) {
            $query .= " AND s.admission_number = ?";
            $params[] = $admission_number;
        }
        if (!empty($class)) {
            $query .= " AND s.class = ?";
            $params[] = $class;
        }
        if (!empty($gender)) {
            $query .= " AND s.gender = ?";
            $params[] = $gender;
        }
        if (!empty($status)) {
            $query .= " AND a.attendance_status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY a.attendance_date DESC, a.arrival_time DESC";
        
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $records = $stmt->fetchAll();
            echo json_encode(["status" => "success", "data" => $records]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($action === 'history') {
        $student_id = $_GET['student_id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT attendance_date, arrival_time, attendance_status FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC");
            $stmt->execute([$student_id]);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
