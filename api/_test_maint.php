<?php
$cookieJar = tempnam(sys_get_temp_dir(), 'php');

$ch = curl_init('http://localhost/MpeliOutFitStore/api/login.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => 'mpeli', 'password' => 'admin1234']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
$loginResp = json_decode(curl_exec($ch), true);
$csrf = $loginResp['csrf_token'] ?? '';
echo "Login: " . ($loginResp['success'] ? 'OK' : 'FAIL') . PHP_EOL;

$ch2 = curl_init('http://localhost/MpeliOutFitStore/api/maintenance.php');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookieJar);
$maintResp = json_decode(curl_exec($ch2), true);
echo "GET maintenance: " . json_encode($maintResp['maintenance']) . PHP_EOL;

$ch3 = curl_init('http://localhost/MpeliOutFitStore/api/maintenance.php');
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode(['enable' => true, 'message' => 'Test maintenance']));
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-CSRF-Token: $csrf"]);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_COOKIEFILE, $cookieJar);
$enableResp = json_decode(curl_exec($ch3), true);
echo "POST enable: " . json_encode($enableResp['maintenance']) . PHP_EOL;

$ch4 = curl_init('http://localhost/MpeliOutFitStore/api/me.php');
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_COOKIEFILE, $cookieJar);
$meResp = json_decode(curl_exec($ch4), true);
echo "GET me (should show maintenance): " . json_encode(['healthy' => $meResp['healthy'], 'maintenance' => $meResp['maintenance']]) . PHP_EOL;

$ch5 = curl_init('http://localhost/MpeliOutFitStore/api/maintenance.php');
curl_setopt($ch5, CURLOPT_POST, true);
curl_setopt($ch5, CURLOPT_POSTFIELDS, json_encode(['enable' => false]));
curl_setopt($ch5, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-CSRF-Token: $csrf"]);
curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch5, CURLOPT_COOKIEFILE, $cookieJar);
$disableResp = json_decode(curl_exec($ch5), true);
echo "POST disable: " . json_encode($disableResp['maintenance']) . PHP_EOL;

unlink($cookieJar);
