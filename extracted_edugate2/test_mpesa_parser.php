<?php
$text = "OBG123456 Confirmed.You have received Ksh1.00 from ZIPPORAH MURUNGA 254712345678 on 14/8/20 at 2:00 PM.";

$receipt = '';
$amount = 0;
$senderPhone = '';
$senderName = '';
$maskedPhone = '';

if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $text, $matches)) {
    $rawPhone = $matches[1];
    if (str_starts_with($rawPhone, '0') && strlen($rawPhone) === 10) {
        $senderPhone = '254' . substr($rawPhone, 1);
    } else {
        $senderPhone = $rawPhone;
    }
} elseif (preg_match('/(?:from|by)\s+.*?((?:0|254)[17]\d{2}\*{2,4}\d{3})/i', $text, $matches)) {
    $maskedPhone = $matches[1];
}

if (preg_match('/from\s+([A-Z\s\-\']+)\s+(?:0[17]|254)/i', $text, $matches)) {
    $senderName = trim($matches[1]);
}

echo "SenderPhone: '$senderPhone'\n";
echo "MaskedPhone: '$maskedPhone'\n";
echo "SenderName: '$senderName'\n";
?>
