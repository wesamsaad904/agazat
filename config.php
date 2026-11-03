<?php
// إعدادات قاعدة البيانات — المستخدم الافتراضي في XAMPP هو root بدون كلمة مرور.
// غيّر هذه القيم إذا كنت قد حددت بيانات دخول خاصة بك.
$host = "localhost";
$db   = "agazat2";
$user = "root";
$pass = "";

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_errno) {
    http_response_code(500);
    header("Content-Type: application/json");
    die(json_encode(["ok" => false, "message" => "فشل الاتصال بقاعدة البيانات: " . $mysqli->connect_error], JSON_UNESCAPED_UNICODE));
}
$mysqli->set_charset("utf8mb4");
