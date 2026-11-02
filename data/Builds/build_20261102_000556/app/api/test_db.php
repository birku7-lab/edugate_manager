<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require "config.php"; $x_0001 = $x_0002->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 5;"); print_r($x_0001->fetchAll(PDO::FETCH_ASSOC)); ?>
