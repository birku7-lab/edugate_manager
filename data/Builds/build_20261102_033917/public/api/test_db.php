<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require "config.php"; $stmt = $pdo->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 5;"); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); ?>
