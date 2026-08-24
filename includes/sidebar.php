<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Redirect if not logged in
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// User Info
$user  = $_SESSION['user'];
$role  = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$name  = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
  ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
  : "../upload/profiles/default.png";

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!-- 🚀 HEADER & SIDEBAR START -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= ucfirst(str_replace('.php','',$currentPage)) ?> | Hormuud University</title>
<link rel="icon" type="image/png" href="../images.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ... KU RAACTA CSS-kii AAD HORE U QORTAY ... */
</style>
</head>
<body>
<header class="hu-header"> ... </header>
<nav class="sidebar" id="sidebar"> ... </nav>
<div class="profile-modal" id="profileModal"> ... </div>
<div class="overlay" id="overlayView"> ... </div>
<div class="overlay" id="overlayPassword"> ... </div>
<script>
/* ✅ JS Sidebar Toggle, Profile Modal, etc. */
</script>
<!-- 🚀 HEADER & SIDEBAR END -->
