<?php require "config.php"; $stmt = $pdo->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 5;"); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); ?>
