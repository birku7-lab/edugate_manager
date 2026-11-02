<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'scan') {
        $x_0001 = $_POST['qr_code'] ?? '';
        $x_0002 = date('Y-m-d');
        $x_0003 = date('H:i:s');

        if (isset($_POST['timestamp']) && !empty($_POST['timestamp'])) {
            $x_0004 = strtotime($_POST['timestamp']);
            if ($x_0004) {
                $x_0002 = date('Y-m-d', $x_0004);
                $x_0003 = date('H:i:s', $x_0004);
            }
        }
        
        if(empty($x_0001)) {
            echo json_encode(["status" => "error", "message" => "No QR code provided."]);
            exit;
        }

        try {

            $stmt = $pdo->prepare("SELECT id, full_name, admission_number, class, gender, photo, assigned_bus_id, parent_phone FROM students WHERE qr_code = ?");
            $stmt->execute([$x_0001]);
            $x_0005 = $stmt->fetch();
            
            if (!$x_0005) {
                echo json_encode(["status" => "error", "message" => "Invalid QR Code. Student not found."]);
                exit;
            }

            $x_0006 = $_GET['mode'] ?? 'attendance';
            $x_0007 = null;

            $x_0008 = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $x_0009 = [];
            while ($row = $x_0008->fetch(PDO::FETCH_ASSOC)) {
                $x_0009[$row['setting_key']] = $row['setting_value'];
            }

            if (($x_0009['billing_verify_enabled'] ?? '0') === '1') {
                $x_000a = $x_0009['academic_year'] ?? '';
                $x_000b = $x_0009['current_term'] ?? '';
                
                $x_000c = 'School Fees';
                $x_000d = null;
                if ($x_0006 === 'meals') $x_000c = 'Meals';
                if ($x_0006 === 'transport') $x_000c = 'Transport';
                if (strpos($x_0006, 'cat_') === 0) {
                    $x_000d = intval(str_replace('cat_', '', $x_0006));
                }

                if ($x_000d !== null) {
                    $x_000e = $pdo->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.id = ?)
                    ");
                    $x_000e->execute([$x_0005['id'], $x_000a, $x_000b, $x_000d]);
                } else {
                    $x_000e = $pdo->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.name = ?)
                    ");
                    $x_000e->execute([$x_0005['id'], $x_000a, $x_000b, $x_000c]);
                }
                $x_000f = $x_000e->fetchAll(PDO::FETCH_ASSOC);

                $x_0010 = 0;
                $x_0011 = null;
                $x_0012 = [];
                $x_0013 = false;

                foreach ($x_000f as $x_0014) {
                    $x_0015 = $x_0014['payment_status'];
                    $x_0016 = $x_0014['due_date'];
                    $x_0017   = intval($x_0014['grace_period_days']);
                    
                    if ($x_0015 !== 'Paid' && $x_0015 !== 'Exempt' && !empty($x_0016)) {
                        $x_0018 = new DateTime('today');
                        try {
                            $x_0019 = new DateTime($x_0016);
                            $x_0019->setTime(0,0,0);
                            $x_001a = (clone $x_0019)->modify("+$x_0017 days");
                            if ($x_0018 > $x_001a) {
                                $x_0015 = 'Overdue';
                            } elseif ($x_0018 > $x_0019 && $x_0018 <= $x_001a) {
                                $x_0015 = 'Grace Period';
                            }
                        } catch (Exception $e) {}
                    }

                    $x_001b = floatval($x_0014['amount_due']) - floatval($x_0014['amount_paid']);
                    if ($x_001b > 0) {
                        $x_0010 += $x_001b;
                    }
                    
                    $x_001c = floatval($x_0009['fee_policy_target_percentage'] ?? 0);
                    $x_001d = floatval($x_0014['amount_due']) > 0 ? (floatval($x_0014['amount_paid']) / floatval($x_0014['amount_due'])) * 100 : 100;
                    
                    $x_001e = 'Allow';
                    
                    if ($x_001b > 0 && ($x_0017 === 0 || $x_001d < $x_001c)) {
                        $x_001e = 'Deny';
                        $x_0013 = true;
                    } else {
                        if ($x_0015 === 'Unpaid') $x_001e = $x_0009['billing_unpaid_rule'] ?? 'Allow';
                        elseif ($x_0015 === 'Partial') $x_001e = $x_0009['billing_partial_rule'] ?? 'Allow';
                        elseif ($x_0015 === 'Overdue') $x_001e = $x_0009['billing_overdue_rule'] ?? 'Deny';
                        elseif ($x_0015 === 'Grace Period') $x_001e = $x_0009['billing_grace_rule'] ?? 'Warn';
                    }
                    
                    if ($x_001e === 'Deny') {
                        $x_0011 = $x_0014['name'];
                        break; // Stop checking, one denial is enough
                    } elseif ($x_001e === 'Warn') {
                        $x_0012[] = $x_0014['name'];
                    }
                }

                if ($x_0011) {
                    if ($x_0013) {
                        $msg = "Not allowed, clear your fee first. Balance: Ksh " . number_format($x_0010, 2);
                        
                        if (($x_0009['sms_enable_denial'] ?? '0') === '1' && !empty($x_0005['parent_phone'])) {
                            require_once 'sms.php';
                            $x_001f = $x_0009['sms_api_token'] ?? '';
                            $x_0020 = $x_0009['sms_sender_id'] ?? '';
                            
                            if (!empty($x_001f)) {
                                $x_0021 = $x_0009['sms_template_denial'] ?? 'Dear Parent, {Student Name} was not allowed into school today because their fee balance is Ksh {Balance}. Please clear the fee immediately. The student has been sent home. For inquiries, contact us at {School Phone}.';
                                
                                $x_0022 = str_replace(
                                    ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Balance}', '{School Phone}'],
                                    [$x_0005['full_name'], $x_0005['parent_name'], $x_0005['admission_number'], $x_0005['class'], $x_0009['school_name'] ?? 'School Admin', number_format($x_0010, 2), $x_0009['school_phone'] ?? ''],
                                    $x_0021
                                );
                                
                                $x_0023 = sendBulkSMS([$x_0005['parent_phone']], $x_0022, $x_0020, $x_001f);
                                $x_0024 = (isset($x_0023['success']) && $x_0023['success'] === true) ? 'Sent' : 'Failed';
                                
                                $x_0025 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                                $x_0025->execute([
                                    $x_0005['id'], $x_0005['parent_phone'], $x_0022, $x_0024, json_encode($x_0023), $x_0023['error'] ?? ''
                                ]);
                            }
                        }
                    } else {
                        $msg = $x_0009['billing_denial_message'] ?? 'Access Denied: Outstanding Fees.';
                        $msg = str_replace('{balance}', number_format($x_0010, 2), $msg);
                    }
                    echo json_encode(["status" => "error", "message" => $msg, "data" => $x_0005]);
                    exit;
                } elseif (count($x_0012) > 0) {
                    $msg = $x_0009['billing_warning_message'] ?? 'Warning: Outstanding Fees.';
                    $x_0007 = str_replace('{balance}', number_format($x_0010, 2), $msg);
                }
            }

            if ($x_0006 === 'meals') {

                $x_0026 = $pdo->prepare("SELECT id FROM meal_logs WHERE student_id = ? AND meal_date = ?");
                $x_0026->execute([$x_0005['id'], $x_0002]);
                if ($x_0026->fetch()) {
                    echo json_encode(["status" => "duplicate", "message" => "Already had a meal today", "data" => $x_0005]);
                    exit;
                }

                $x_0027 = $pdo->prepare("INSERT INTO meal_logs (student_id, meal_date, meal_type, scanned_by) VALUES (?, ?, 'Normal', ?)");
                $x_0027->execute([$x_0005['id'], $x_0002, $x_0028['id'] ?? null]);
                log_audit($pdo, $x_0028['id'] ?? null, 'MEAL_SCAN', "Recorded meal for student ID: {$x_0005['id']}");
                $x_0005['scan_time'] = $x_0003;
                echo json_encode([
                    "status" => $x_0007 ? "warning" : "success", 
                    "message" => $x_0007 ? $x_0007 : "Meal Recorded Successfully", 
                    "data" => $x_0005
                ]);
                exit;
            }

            if ($x_0006 === 'transport') {
                if (empty($x_0005['assigned_bus_id'])) {
                    echo json_encode(["status" => "error", "message" => "Student is not assigned to any bus.", "data" => $x_0005]);
                    exit;
                }

                $x_0029 = $pdo->prepare("SELECT assigned_bus_id FROM users WHERE id = ?");
                $x_0029->execute([$x_0028['id'] ?? 0]);
                $x_002a = $x_0029->fetch();
                if ($x_002a && !empty($x_002a['assigned_bus_id']) && $x_0005['assigned_bus_id'] != $x_002a['assigned_bus_id']) {
                    echo json_encode(["status" => "error", "message" => "Wrong Bus! Student is assigned to a different bus.", "data" => $x_0005]);
                    exit;
                }
                
                $x_0026 = $pdo->prepare("SELECT board_time FROM transport_logs WHERE student_id = ? AND board_date = ?");
                $x_0026->execute([$x_0005['id'], $x_0002]);
                $x_002b = $x_0026->fetch();
                if ($x_002b) {
                    echo json_encode(["status" => "duplicate", "message" => "Already boarded today", "data" => $x_0005, "first_scan_time" => $x_002b['board_time']]);
                    exit;
                }

                $x_0027 = $pdo->prepare("INSERT INTO transport_logs (student_id, bus_id, board_date, board_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $x_0027->execute([$x_0005['id'], $x_0005['assigned_bus_id'], $x_0002, $x_0003, $x_0028['id'] ?? null]);
                log_audit($pdo, $x_0028['id'] ?? null, 'TRANSPORT_SCAN', "Recorded transport boarding for student ID: {$x_0005['id']}");

                if (!empty($x_0005['parent_phone']) && ($x_0009['sms_enable_transport'] ?? 'true') === 'true') {
                    $x_001f = $x_0009['sms_api_token'] ?? '';
                    $x_0020 = $x_0009['sms_sender_id'] ?? '';
                    if (!empty($x_001f)) {
                        $x_002c = $x_0009['school_name'] ?? 'School System';
                        $msg = "Hello, {$x_0005['full_name']} has boarded the bus and left school. - {$x_002c}";
                        
                        if (!empty($x_0009['sms_template_transport'])) {
                            $msg = str_replace(
                                ['{Student Name}', '{School Name}', '{Class}', '{TIME}'],
                                [$x_0005['full_name'], $x_002c, $x_0005['class'], date('h:i A')],
                                $x_0009['sms_template_transport']
                            );
                        }

                        $x_0023 = sendBulkSMS([$x_0005['parent_phone']], $msg, $x_0020, $x_001f);
                        $x_0024 = (isset($x_0023['success']) && $x_0023['success'] === true) ? 'Sent' : 'Failed';
                        $x_0025 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_0025->execute([
                            $x_0005['id'], $x_0005['parent_phone'], $msg, $x_0024,
                            json_encode($x_0023), $x_0023['error'] ?? ''
                        ]);
                    }
                }

                
                $x_0005['scan_time'] = $x_0003;
                echo json_encode([
                    "status" => $x_0007 ? "warning" : "success", 
                    "message" => $x_0007 ? $x_0007 : "Boarding Recorded Successfully", 
                    "data" => $x_0005
                ]);
                exit;
            }

            if ($x_000d !== null) {

                $x_0026 = $pdo->prepare("SELECT scan_time FROM custom_scanner_logs WHERE student_id = ? AND category_id = ? AND scan_date = ?");
                $x_0026->execute([$x_0005['id'], $x_000d, $x_0002]);
                $x_002b = $x_0026->fetch();
                if ($x_002b) {
                    echo json_encode(["status" => "duplicate", "message" => "Already scanned today", "data" => $x_0005, "first_scan_time" => $x_002b['scan_time']]);
                    exit;
                }

                $x_0027 = $pdo->prepare("INSERT INTO custom_scanner_logs (student_id, category_id, scan_date, scan_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $x_0027->execute([$x_0005['id'], $x_000d, $x_0002, $x_0003, $x_0028['id'] ?? null]);
                log_audit($pdo, $x_0028['id'] ?? null, 'CUSTOM_SCAN', "Recorded custom scan (cat_id: $x_000d) for student ID: {$x_0005['id']}");
                $x_0005['scan_time'] = $x_0003;
                echo json_encode([
                    "status" => $x_0007 ? "warning" : "success", 
                    "message" => $x_0007 ? $x_0007 : "Scan Recorded Successfully", 
                    "data" => $x_0005
                ]);
                exit;
            }

            $x_0026 = $pdo->prepare("SELECT arrival_time FROM attendance WHERE student_id = ? AND attendance_date = ?");
            $x_0026->execute([$x_0005['id'], $x_0002]);
            $x_002b = $x_0026->fetch();
            
            if ($x_002b) {
                echo json_encode([
                    "status" => "duplicate", 
                    "message" => "Already Checked In Today", 
                    "data" => $x_0005,
                    "first_scan_time" => $x_002b['arrival_time']
                ]);
                exit;
            }

            $x_002d = $pdo->prepare("SELECT name FROM holidays WHERE ? BETWEEN start_date AND end_date");
            $x_002d->execute([$x_0002]);
            $x_002e = $x_002d->fetch();
            if ($x_002e) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Holiday: " . $x_002e['name']]);
                exit;
            }

            $x_002f = date('D'); // Mon, Tue, etc.
            $x_0030 = json_decode($x_0009['school_days'] ?? '{}', true);
            if (isset($x_0030[$x_002f]) && $x_0030[$x_002f] == false) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Non-School Day."]);
                exit;
            }

            $status = 'Present';
            $x_0031 = $x_0009['late_arrival_time'] ?? '07:30';
            $x_0032 = $x_0009['attendance_cutoff_time'] ?? '09:00';

            if (strlen($x_0031) == 5) $x_0031 .= ':00';
            if (strlen($x_0032) == 5) $x_0032 .= ':00';

            if ($x_0003 > $x_0032) {
                $status = 'Absent';
            } elseif ($x_0003 > $x_0031) {
                $status = 'Late';
            }

            $x_0027 = $pdo->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, ?)");
            $x_0027->execute([$x_0005['id'], $x_0002, $x_0003, $status]);

            log_audit($pdo, $x_0028['id'] ?? null, 'ATTENDANCE_SCAN', "Recorded attendance for student ID: {$x_0005['id']}");

            try {
                if (!empty($x_0005['parent_phone']) && ($x_0009['sms_enable_attendance'] ?? 'true') === 'true') {
                    $x_001f = $x_0009['sms_api_token'] ?? '';
                    $x_0020 = $x_0009['sms_sender_id'] ?? '';
                    $x_002c = $x_0009['school_name'] ?? 'School Admin';
                    
                    if (!empty($x_001f)) {
                        $x_0033 = date('h:i A', strtotime($x_0003));
                        $msg = "Hello, your child " . $x_0005['full_name'] . " has safely arrived at school today at " . $x_0033 . ". Thank you. - " . $x_002c;
                        
                        $x_0023 = sendBulkSMS([$x_0005['parent_phone']], $msg, $x_0020, $x_001f);
                        $x_0024 = (isset($x_0023['success']) && $x_0023['success'] === true) ? 'Sent' : 'Failed';
                        
                        $x_0025 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_0025->execute([
                            $x_0005['id'],
                            $x_0005['parent_phone'],
                            $msg,
                            $x_0024,
                            json_encode($x_0023),
                            $x_0023['error'] ?? ''
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("Attendance SMS sending failed: " . $ex->getMessage());
            }

            
            $x_0034 = "";
            if (isset($x_0024)) {
                if ($x_0024 === 'Sent') {
                    $x_0034 = " (SMS Sent)";
                } else {
                    $x_0034 = " (SMS Failed)";
                }
            } else {
                if (empty($x_0005['parent_phone'])) {
                    $x_0034 = " (No SMS: No Parent Phone)";
                } elseif (empty($x_001f)) {
                    $x_0034 = " (No SMS: API Key missing)";
                }
            }

            $x_0005['arrival_time'] = $x_0003;
            $x_0005['status'] = $status;
            
            $x_0035 = $x_0007 ? $x_0007 : "Attendance Recorded ($status)";
            
            $x_0036 = [
                "status" => $x_0007 ? "warning" : "success", 
                "message" => $x_0035 . $x_0034, 
                "data" => $x_0005
            ];
            echo json_encode($x_0036);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'records') {

        $x_0002 = $_GET['date'] ?? '';
        $x_0037 = $_GET['start_date'] ?? '';
        $x_0038 = $_GET['end_date'] ?? '';
        $x_0039 = $_GET['student_name'] ?? '';
        $x_003a = $_GET['admission_number'] ?? '';
        $x_003b = $_GET['class'] ?? '';
        $x_003c = $_GET['gender'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $query = "SELECT a.id, a.attendance_date, a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.gender 
                  FROM attendance a 
                  JOIN students s ON a.student_id = s.id 
                  WHERE 1=1";
        $params = [];
        
        if (!empty($x_0002)) {
            $query .= " AND a.attendance_date = ?";
            $params[] = $x_0002;
        }
        if (!empty($x_0037) && !empty($x_0038)) {
            $query .= " AND a.attendance_date BETWEEN ? AND ?";
            $params[] = $x_0037;
            $params[] = $x_0038;
        }
        if (!empty($x_0039)) {
            $query .= " AND s.full_name LIKE ?";
            $params[] = "%$x_0039%";
        }
        if (!empty($x_003a)) {
            $query .= " AND s.admission_number = ?";
            $params[] = $x_003a;
        }
        if (!empty($x_003b)) {
            $query .= " AND s.class = ?";
            $params[] = $x_003b;
        }
        if (!empty($x_003c)) {
            $query .= " AND s.gender = ?";
            $params[] = $x_003c;
        }
        if (!empty($status)) {
            $query .= " AND a.attendance_status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY a.attendance_date DESC, a.arrival_time DESC";
        
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $x_003d = $stmt->fetchAll();
            echo json_encode(["status" => "success", "data" => $x_003d]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($action === 'history') {
        $x_003e = $_GET['student_id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT attendance_date, arrival_time, attendance_status FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC");
            $stmt->execute([$x_003e]);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
