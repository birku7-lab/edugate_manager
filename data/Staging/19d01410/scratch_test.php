<?php
$text = 'OBG123456 Confirmed.You have received Ksh5,000.00 from JOHN DOE 0712345678 on 14/8/20 at 2:00 PM.';

if (preg_match('/^([A-Z0-9]+)\s+Confirmed/i', $text, $matches)) echo 'Receipt: ' . $matches[1] . "\n";
if (preg_match('/Ksh\s*([\d,]+\.?\d*)/i', $text, $matches)) echo 'Amount: ' . floatval(str_replace(',', '', $matches[1])) . "\n";
if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $text, $matches)) echo 'Phone: ' . $matches[1] . "\n";
if (preg_match('/from\s+([A-Z\s]+)\s+(?:0[17]|254)/i', $text, $matches)) echo 'Name: ' . trim($matches[1]) . "\n";
