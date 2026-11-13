<?php
$host = 'localhost';
$db_user = 'root';
$db_pass = ''; // Default XAMPP password is blank
$db_name = 'tech_repair';

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>