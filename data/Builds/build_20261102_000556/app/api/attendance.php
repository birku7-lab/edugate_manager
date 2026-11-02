<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms.php';

$x_0001 = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($x_0001 === 'scan') {
        $x_0002 = $_POST['qr_code'] ?? '';
        $x_0003 = date('Y-m-d');
        $x_0004 = date('H:i:s');

        if (isset($_POST['timestamp']) && !empty($_POST['timestamp'])) {
            $x_0005 = strtotime($_POST['timestamp']);
            if ($x_0005) {
                $x_0003 = date('Y-m-d', $x_0005);
                $x_0004 = date('H:i:s', $x_0005);
            }
        }
        
        if(empty($x_0002)) {
            echo json_encode(["status" => "error", "message" => "No QR code provided."]);
            exit;
        }

        try {

            $x_0006 = $x_0007->prepare("SELECT id, full_name, admission_number, class, gender, photo, assigned_bus_id, parent_phone FROM students WHERE qr_code = ?");
            $x_0006->execute([$x_0002]);
            $x_0008 = $x_0006->fetch();
            
            if (!$x_0008) {
                echo json_encode(["status" => "error", "message" => "Invalid QR Code. Student not found."]);
                exit;
            }

            $x_0009 = $_GET['mode'] ?? 'attendance';
            $x_000a = null;

            $x_000b = $x_0007->query("SELECT setting_key, setting_value FROM settings");
            $x_000c = [];
            while ($x_000d = $x_000b->fetch(PDO::FETCH_ASSOC)) {
                $x_000c[$x_000d['setting_key']] = $x_000d['setting_value'];
            }

            if (($x_000c['billing_verify_enabled'] ?? '0') === '1') {
                $x_000e = $x_000c['academic_year'] ?? '';
                $x_000f = $x_000c['current_term'] ?? '';
                
                $x_0010 = 'School Fees';
                $x_0011 = null;
                if ($x_0009 === 'meals') $x_0010 = 'Meals';
                if ($x_0009 === 'transport') $x_0010 = 'Transport';
                if (strpos($x_0009, 'cat_') === 0) {
                    $x_0011 = intval(str_replace('cat_', '', $x_0009));
                }

                if ($x_0011 !== null) {
                    $x_0012 = $x_0007->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.id = ?)
                    ");
                    $x_0012->execute([$x_0008['id'], $x_000e, $x_000f, $x_0011]);
                } else {
                    $x_0012 = $x_0007->prepare("
                        SELECT sb.payment_status, sb.amount_due, sb.amount_paid, bc.due_date, bc.grace_period_days, bc.name 
                        FROM student_billing sb
                        JOIN billing_categories bc ON sb.category_id = bc.id
                        WHERE sb.student_id = ? AND sb.academic_year = ? AND sb.school_term = ?
                          AND (bc.enforce_on_scanner = 1 OR bc.name = ?)
                    ");
                    $x_0012->execute([$x_0008['id'], $x_000e, $x_000f, $x_0010]);
                }
                $x_0013 = $x_0012->fetchAll(PDO::FETCH_ASSOC);

                $x_0014 = 0;
                $x_0015 = null;
                $x_0016 = [];
                $x_0017 = false;

                foreach ($x_0013 as $x_0018) {
                    $x_0019 = $x_0018['payment_status'];
                    $x_001a = $x_0018['due_date'];
                    $x_001b   = intval($x_0018['grace_period_days']);
                    
                    if ($x_0019 !== 'Paid' && $x_0019 !== 'Exempt' && !empty($x_001a)) {
                        $x_001c = new DateTime('today');
                        try {
                            $x_001d = new DateTime($x_001a);
                            $x_001d->setTime(0,0,0);
                            $x_001e = (clone $x_001d)->modify("+$x_001b days");
                            if ($x_001c > $x_001e) {
                                $x_0019 = 'Overdue';
                            } elseif ($x_001c > $x_001d && $x_001c <= $x_001e) {
                                $x_0019 = 'Grace Period';
                            }
                        } catch (Exception $e) {}
                    }

                    $x_001f = floatval($x_0018['amount_due']) - floatval($x_0018['amount_paid']);
                    if ($x_001f > 0) {
                        $x_0014 += $x_001f;
                    }
                    
                    $x_0020 = floatval($x_000c['fee_policy_target_percentage'] ?? 0);
                    $x_0021 = floatval($x_0018['amount_due']) > 0 ? (floatval($x_0018['amount_paid']) / floatval($x_0018['amount_due'])) * 100 : 100;
                    
                    $x_0022 = 'Allow';
                    
                    if ($x_001f > 0 && ($x_001b === 0 || $x_0021 < $x_0020)) {
                        $x_0022 = 'Deny';
                        $x_0017 = true;
                    } else {
                        if ($x_0019 === 'Unpaid') $x_0022 = $x_000c['billing_unpaid_rule'] ?? 'Allow';
                        elseif ($x_0019 === 'Partial') $x_0022 = $x_000c['billing_partial_rule'] ?? 'Allow';
                        elseif ($x_0019 === 'Overdue') $x_0022 = $x_000c['billing_overdue_rule'] ?? 'Deny';
                        elseif ($x_0019 === 'Grace Period') $x_0022 = $x_000c['billing_grace_rule'] ?? 'Warn';
                    }
                    
                    if ($x_0022 === 'Deny') {
                        $x_0015 = $x_0018['name'];
                        break; // Stop checking, one denial is enough
                    } elseif ($x_0022 === 'Warn') {
                        $x_0016[] = $x_0018['name'];
                    }
                }

                if ($x_0015) {
                    if ($x_0017) {
                        $x_0023 = "Not allowed, clear your fee first. Balance: Ksh " . number_format($x_0014, 2);
                        
                        if (($x_000c['sms_enable_denial'] ?? '0') === '1' && !empty($x_0008['parent_phone'])) {
                            require_once 'sms.php';
                            $x_0024 = $x_000c['sms_api_token'] ?? '';
                            $x_0025 = $x_000c['sms_sender_id'] ?? '';
                            
                            if (!empty($x_0024)) {
                                $x_0026 = $x_000c['sms_template_denial'] ?? 'Dear Parent, {Student Name} was not allowed into school today because their fee balance is Ksh {Balance}. Please clear the fee immediately. The student has been sent home. For inquiries, contact us at {School Phone}.';
                                
                                $x_0027 = str_replace(
                                    ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Balance}', '{School Phone}'],
                                    [$x_0008['full_name'], $x_0008['parent_name'], $x_0008['admission_number'], $x_0008['class'], $x_000c['school_name'] ?? 'School Admin', number_format($x_0014, 2), $x_000c['school_phone'] ?? ''],
                                    $x_0026
                                );
                                
                                $x_0028 = sendBulkSMS([$x_0008['parent_phone']], $x_0027, $x_0025, $x_0024);
                                $x_0029 = (isset($x_0028['success']) && $x_0028['success'] === true) ? 'Sent' : 'Failed';
                                
                                $x_002a = $x_0007->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                                $x_002a->execute([
                                    $x_0008['id'], $x_0008['parent_phone'], $x_0027, $x_0029, json_encode($x_0028), $x_0028['error'] ?? ''
                                ]);
                            }
                        }
                    } else {
                        $x_0023 = $x_000c['billing_denial_message'] ?? 'Access Denied: Outstanding Fees.';
                        $x_0023 = str_replace('{balance}', number_format($x_0014, 2), $x_0023);
                    }
                    echo json_encode(["status" => "error", "message" => $x_0023, "data" => $x_0008]);
                    exit;
                } elseif (count($x_0016) > 0) {
                    $x_0023 = $x_000c['billing_warning_message'] ?? 'Warning: Outstanding Fees.';
                    $x_000a = str_replace('{balance}', number_format($x_0014, 2), $x_0023);
                }
            }

            if ($x_0009 === 'meals') {

                $x_002b = $x_0007->prepare("SELECT id FROM meal_logs WHERE student_id = ? AND meal_date = ?");
                $x_002b->execute([$x_0008['id'], $x_0003]);
                if ($x_002b->fetch()) {
                    echo json_encode(["status" => "duplicate", "message" => "Already had a meal today", "data" => $x_0008]);
                    exit;
                }

                $x_002c = $x_0007->prepare("INSERT INTO meal_logs (student_id, meal_date, meal_type, scanned_by) VALUES (?, ?, 'Normal', ?)");
                $x_002c->execute([$x_0008['id'], $x_0003, $x_002d['id'] ?? null]);
                log_audit($x_0007, $x_002d['id'] ?? null, 'MEAL_SCAN', "Recorded meal for student ID: {$x_0008['id']}");
                $x_0008['scan_time'] = $x_0004;
                echo json_encode([
                    "status" => $x_000a ? "warning" : "success", 
                    "message" => $x_000a ? $x_000a : "Meal Recorded Successfully", 
                    "data" => $x_0008
                ]);
                exit;
            }

            if ($x_0009 === 'transport') {
                if (empty($x_0008['assigned_bus_id'])) {
                    echo json_encode(["status" => "error", "message" => "Student is not assigned to any bus.", "data" => $x_0008]);
                    exit;
                }

                $x_002e = $x_0007->prepare("SELECT assigned_bus_id FROM users WHERE id = ?");
                $x_002e->execute([$x_002d['id'] ?? 0]);
                $x_002f = $x_002e->fetch();
                if ($x_002f && !empty($x_002f['assigned_bus_id']) && $x_0008['assigned_bus_id'] != $x_002f['assigned_bus_id']) {
                    echo json_encode(["status" => "error", "message" => "Wrong Bus! Student is assigned to a different bus.", "data" => $x_0008]);
                    exit;
                }
                
                $x_002b = $x_0007->prepare("SELECT board_time FROM transport_logs WHERE student_id = ? AND board_date = ?");
                $x_002b->execute([$x_0008['id'], $x_0003]);
                $x_0030 = $x_002b->fetch();
                if ($x_0030) {
                    echo json_encode(["status" => "duplicate", "message" => "Already boarded today", "data" => $x_0008, "first_scan_time" => $x_0030['board_time']]);
                    exit;
                }

                $x_002c = $x_0007->prepare("INSERT INTO transport_logs (student_id, bus_id, board_date, board_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $x_002c->execute([$x_0008['id'], $x_0008['assigned_bus_id'], $x_0003, $x_0004, $x_002d['id'] ?? null]);
                log_audit($x_0007, $x_002d['id'] ?? null, 'TRANSPORT_SCAN', "Recorded transport boarding for student ID: {$x_0008['id']}");

                if (!empty($x_0008['parent_phone']) && ($x_000c['sms_enable_transport'] ?? 'true') === 'true') {
                    $x_0024 = $x_000c['sms_api_token'] ?? '';
                    $x_0025 = $x_000c['sms_sender_id'] ?? '';
                    if (!empty($x_0024)) {
                        $x_0031 = $x_000c['school_name'] ?? 'School System';
                        $x_0023 = "Hello, {$x_0008['full_name']} has boarded the bus and left school. - {$x_0031}";
                        
                        if (!empty($x_000c['sms_template_transport'])) {
                            $x_0023 = str_replace(
                                ['{Student Name}', '{School Name}', '{Class}', '{TIME}'],
                                [$x_0008['full_name'], $x_0031, $x_0008['class'], date('h:i A')],
                                $x_000c['sms_template_transport']
                            );
                        }

                        $x_0028 = sendBulkSMS([$x_0008['parent_phone']], $x_0023, $x_0025, $x_0024);
                        $x_0029 = (isset($x_0028['success']) && $x_0028['success'] === true) ? 'Sent' : 'Failed';
                        $x_002a = $x_0007->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_002a->execute([
                            $x_0008['id'], $x_0008['parent_phone'], $x_0023, $x_0029,
                            json_encode($x_0028), $x_0028['error'] ?? ''
                        ]);
                    }
                }

                
                $x_0008['scan_time'] = $x_0004;
                echo json_encode([
                    "status" => $x_000a ? "warning" : "success", 
                    "message" => $x_000a ? $x_000a : "Boarding Recorded Successfully", 
                    "data" => $x_0008
                ]);
                exit;
            }

            if ($x_0011 !== null) {

                $x_002b = $x_0007->prepare("SELECT scan_time FROM custom_scanner_logs WHERE student_id = ? AND category_id = ? AND scan_date = ?");
                $x_002b->execute([$x_0008['id'], $x_0011, $x_0003]);
                $x_0030 = $x_002b->fetch();
                if ($x_0030) {
                    echo json_encode(["status" => "duplicate", "message" => "Already scanned today", "data" => $x_0008, "first_scan_time" => $x_0030['scan_time']]);
                    exit;
                }

                $x_002c = $x_0007->prepare("INSERT INTO custom_scanner_logs (student_id, category_id, scan_date, scan_time, scanned_by) VALUES (?, ?, ?, ?, ?)");
                $x_002c->execute([$x_0008['id'], $x_0011, $x_0003, $x_0004, $x_002d['id'] ?? null]);
                log_audit($x_0007, $x_002d['id'] ?? null, 'CUSTOM_SCAN', "Recorded custom scan (cat_id: $x_0011) for student ID: {$x_0008['id']}");
                $x_0008['scan_time'] = $x_0004;
                echo json_encode([
                    "status" => $x_000a ? "warning" : "success", 
                    "message" => $x_000a ? $x_000a : "Scan Recorded Successfully", 
                    "data" => $x_0008
                ]);
                exit;
            }

            $x_002b = $x_0007->prepare("SELECT arrival_time FROM attendance WHERE student_id = ? AND attendance_date = ?");
            $x_002b->execute([$x_0008['id'], $x_0003]);
            $x_0030 = $x_002b->fetch();
            
            if ($x_0030) {
                echo json_encode([
                    "status" => "duplicate", 
                    "message" => "Already Checked In Today", 
                    "data" => $x_0008,
                    "first_scan_time" => $x_0030['arrival_time']
                ]);
                exit;
            }

            $x_0032 = $x_0007->prepare("SELECT name FROM holidays WHERE ? BETWEEN start_date AND end_date");
            $x_0032->execute([$x_0003]);
            $x_0033 = $x_0032->fetch();
            if ($x_0033) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Holiday: " . $x_0033['name']]);
                exit;
            }

            $x_0034 = date('D'); // Mon, Tue, etc.
            $x_0035 = json_decode($x_000c['school_days'] ?? '{}', true);
            if (isset($x_0035[$x_0034]) && $x_0035[$x_0034] == false) {
                echo json_encode(["status" => "error", "message" => "Attendance Disabled. Today is a Non-School Day."]);
                exit;
            }

            $x_0036 = 'Present';
            $x_0037 = $x_000c['late_arrival_time'] ?? '07:30';
            $x_0038 = $x_000c['attendance_cutoff_time'] ?? '09:00';

            if (strlen($x_0037) == 5) $x_0037 .= ':00';
            if (strlen($x_0038) == 5) $x_0038 .= ':00';

            if ($x_0004 > $x_0038) {
                $x_0036 = 'Absent';
            } elseif ($x_0004 > $x_0037) {
                $x_0036 = 'Late';
            }

            $x_002c = $x_0007->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, ?)");
            $x_002c->execute([$x_0008['id'], $x_0003, $x_0004, $x_0036]);

            log_audit($x_0007, $x_002d['id'] ?? null, 'ATTENDANCE_SCAN', "Recorded attendance for student ID: {$x_0008['id']}");

            try {
                if (!empty($x_0008['parent_phone']) && ($x_000c['sms_enable_attendance'] ?? 'true') === 'true') {
                    $x_0024 = $x_000c['sms_api_token'] ?? '';
                    $x_0025 = $x_000c['sms_sender_id'] ?? '';
                    $x_0031 = $x_000c['school_name'] ?? 'School Admin';
                    
                    if (!empty($x_0024)) {
                        $x_0039 = date('h:i A', strtotime($x_0004));
                        $x_0023 = "Hello, your child " . $x_0008['full_name'] . " has safely arrived at school today at " . $x_0039 . ". Thank you. - " . $x_0031;
                        
                        $x_0028 = sendBulkSMS([$x_0008['parent_phone']], $x_0023, $x_0025, $x_0024);
                        $x_0029 = (isset($x_0028['success']) && $x_0028['success'] === true) ? 'Sent' : 'Failed';
                        
                        $x_002a = $x_0007->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_002a->execute([
                            $x_0008['id'],
                            $x_0008['parent_phone'],
                            $x_0023,
                            $x_0029,
                            json_encode($x_0028),
                            $x_0028['error'] ?? ''
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("Attendance SMS sending failed: " . $ex->getMessage());
            }

            
            $x_003a = "";
            if (isset($x_0029)) {
                if ($x_0029 === 'Sent') {
                    $x_003a = " (SMS Sent)";
                } else {
                    $x_003a = " (SMS Failed)";
                }
            } else {
                if (empty($x_0008['parent_phone'])) {
                    $x_003a = " (No SMS: No Parent Phone)";
                } elseif (empty($x_0024)) {
                    $x_003a = " (No SMS: API Key missing)";
                }
            }

            $x_0008['arrival_time'] = $x_0004;
            $x_0008['status'] = $x_0036;
            
            $x_003b = $x_000a ? $x_000a : "Attendance Recorded ($x_0036)";
            
            $x_003c = [
                "status" => $x_000a ? "warning" : "success", 
                "message" => $x_003b . $x_003a, 
                "data" => $x_0008
            ];
            echo json_encode($x_003c);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($x_0001 === 'records') {

        $x_0003 = $_GET['date'] ?? '';
        $x_003d = $_GET['start_date'] ?? '';
        $x_003e = $_GET['end_date'] ?? '';
        $x_003f = $_GET['student_name'] ?? '';
        $x_0040 = $_GET['admission_number'] ?? '';
        $x_0041 = $_GET['class'] ?? '';
        $x_0042 = $_GET['gender'] ?? '';
        $x_0036 = $_GET['status'] ?? '';
        
        $x_0043 = "SELECT a.id, a.attendance_date, a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.gender 
                  FROM attendance a 
                  JOIN students s ON a.student_id = s.id 
                  WHERE 1=1";
        $x_0044 = [];
        
        if (!empty($x_0003)) {
            $x_0043 .= " AND a.attendance_date = ?";
            $x_0044[] = $x_0003;
        }
        if (!empty($x_003d) && !empty($x_003e)) {
            $x_0043 .= " AND a.attendance_date BETWEEN ? AND ?";
            $x_0044[] = $x_003d;
            $x_0044[] = $x_003e;
        }
        if (!empty($x_003f)) {
            $x_0043 .= " AND s.full_name LIKE ?";
            $x_0044[] = "%$x_003f%";
        }
        if (!empty($x_0040)) {
            $x_0043 .= " AND s.admission_number = ?";
            $x_0044[] = $x_0040;
        }
        if (!empty($x_0041)) {
            $x_0043 .= " AND s.class = ?";
            $x_0044[] = $x_0041;
        }
        if (!empty($x_0042)) {
            $x_0043 .= " AND s.gender = ?";
            $x_0044[] = $x_0042;
        }
        if (!empty($x_0036)) {
            $x_0043 .= " AND a.attendance_status = ?";
            $x_0044[] = $x_0036;
        }
        
        $x_0043 .= " ORDER BY a.attendance_date DESC, a.arrival_time DESC";
        
        try {
            $x_0006 = $x_0007->prepare($x_0043);
            $x_0006->execute($x_0044);
            $x_0045 = $x_0006->fetchAll();
            echo json_encode(["status" => "success", "data" => $x_0045]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($x_0001 === 'history') {
        $x_0046 = $_GET['student_id'] ?? 0;
        try {
            $x_0006 = $x_0007->prepare("SELECT attendance_date, arrival_time, attendance_status FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC");
            $x_0006->execute([$x_0046]);
            echo json_encode(["status" => "success", "data" => $x_0006->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
