<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'auth_middleware.php'; // Ensure user is authenticated

try {

    $stmt = $pdo->query("SELECT MAX(id) as latest_id FROM audit_log");
    $result = $stmt->fetch();
    
    echo json_encode([
        'status' => 'success', 
        'latest_id' => $result['latest_id'] ? (int)$result['latest_id'] : 0
    ]);
} catch (PDOException $e) {
    error_log("Sync API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to check sync status']);
}
?>
