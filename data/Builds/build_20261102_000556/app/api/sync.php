<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'auth_middleware.php'; // Ensure user is authenticated

try {

    $x_0001 = $x_0002->query("SELECT MAX(id) as latest_id FROM audit_log");
    $x_0003 = $x_0001->fetch();
    
    echo json_encode([
        'status' => 'success', 
        'latest_id' => $x_0003['latest_id'] ? (int)$x_0003['latest_id'] : 0
    ]);
} catch (PDOException $e) {
    error_log("Sync API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to check sync status']);
}
?>
