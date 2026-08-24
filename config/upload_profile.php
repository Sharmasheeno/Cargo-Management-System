<?php
// upload_profile.php
// Profile photo upload handler forfaras cargo

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

// Check if user is logged in
requireAuth();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get current user data
$user = getCurrentUser();

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    
    $file = $_FILES['profile_photo'];
    $upload_dir = __DIR__ . '/../uploads/profiles/';
    
    // Create directory if not exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Validate file
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "❌ Upload failed. Please try again.";
    } elseif (!in_array($file['type'], $allowed_types)) {
        $error = "❌ Invalid file type. Please upload JPG, PNG, or GIF images only.";
    } elseif ($file['size'] > $max_size) {
        $error = "❌ File size too large. Maximum size is 5MB.";
    } else {
        // Generate unique filename
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $new_filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
        $upload_path = $upload_dir . $new_filename;
        $db_path = 'uploads/profiles/' . $new_filename;
        
        // Upload file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Delete old photo if exists and not default
            $old_photo = $user['sawir_profile'] ?? '';
            if ($old_photo && $old_photo !== 'uploads/profiles/default.png' && file_exists(__DIR__ . '/../' . $old_photo)) {
                unlink(__DIR__ . '/../' . $old_photo);
            }
            
            // Update database
            try {
                $stmt = $pdo->prepare("UPDATE isticmaalayaasha SET sawir_profile = ? WHERE id = ?");
                if ($stmt->execute([$db_path, $user_id])) {
                    $_SESSION['user_photo'] = $db_path;
                    $message = "✅ Profile photo updated successfully!";
                    
                    // Log activity
                    logActivity($user_id, 'profile_photo_upload', 'isticmaalayaasha', $user_id);
                } else {
                    $error = "❌ Database error. Please try again.";
                    // Delete uploaded file if database update fails
                    unlink($upload_path);
                }
            } catch(PDOException $e) {
                $error = "❌ Database error: " . $e->getMessage();
                unlink($upload_path);
            }
        } else {
            $error = "❌ Failed to upload file. Please check folder permissions.";
        }
    }
}

// Handle AJAX upload (for modern browsers)
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if ($message) {
        echo json_encode(['success' => true, 'message' => $message, 'photo_path' => $db_path ?? null]);
    } elseif ($error) {
        echo json_encode(['success' => false, 'message' => $error]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    }
    exit;
}

// Get current photo path
$current_photo = !empty($user['sawir_profile']) 
    ? '../' . htmlspecialchars($user['sawir_profile']) 
    : '../uploads/profiles/default.png';

// Handle photo removal
if (isset($_POST['remove_photo'])) {
    $old_photo = $user['sawir_profile'] ?? '';
    if ($old_photo && $old_photo !== 'uploads/profiles/default.png' && file_exists(__DIR__ . '/../' . $old_photo)) {
        unlink(__DIR__ . '/../' . $old_photo);
    }
    
    $stmt = $pdo->prepare("UPDATE isticmaalayaasha SET sawir_profile = NULL WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        $_SESSION['user_photo'] = null;
        $message = "✅ Profile photo removed successfully!";
        logActivity($user_id, 'profile_photo_removed', 'isticmaalayaasha', $user_id);
    } else {
        $error = "❌ Failed to remove photo.";
    }
    
    // Refresh user data
    $user = getCurrentUser();
    $current_photo = '../uploads/profiles/default.png';
}
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Profile Photo - Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-white: #FFFFFF;
            --curdun-bg: #F8F6F9;
            --curdun-dark-gray: #2D2D2D;
            --curdun-gray-light: #E8E4EC;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--curdun-bg) 0%, #fff 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .upload-container {
            max-width: 500px;
            width: 100%;
            background: var(--curdun-white);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(82, 0, 102, 0.15);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            background: linear-gradient(135deg, var(--curdun-violet) 0%, var(--curdun-violet-light) 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .profile-section {
            padding: 40px;
            text-align: center;
        }
        
        .profile-photo-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 30px;
        }
        
        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--curdun-violet);
            box-shadow: 0 8px 20px rgba(82, 0, 102, 0.2);
            transition: all 0.3s ease;
            background: var(--curdun-bg);
        }
        
        .profile-photo:hover {
            transform: scale(1.02);
            border-color: var(--curdun-yellow);
        }
        
        .camera-icon {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .camera-icon:hover {
            transform: scale(1.1);
            background: var(--curdun-yellow-dark);
        }
        
        .file-input {
            display: none;
        }
        
        .user-info {
            background: var(--curdun-bg);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: left;
        }
        
        .user-info h3 {
            color: var(--curdun-violet);
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--curdun-gray-light);
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--curdun-dark-gray);
        }
        
        .info-value {
            color: #666;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--curdun-violet);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--curdun-violet-light);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(82, 0, 102, 0.3);
        }
        
        .btn-secondary {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
        }
        
        .btn-secondary:hover {
            background: var(--curdun-yellow-dark);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--curdun-violet);
            color: var(--curdun-violet);
        }
        
        .btn-outline:hover {
            background: var(--curdun-violet);
            color: white;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: #EEFBF3;
            color: #0F7A3A;
            border-left: 4px solid #0F7A3A;
        }
        
        .alert-error {
            background: #FEF0EE;
            color: #B42318;
            border-left: 4px solid #B42318;
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--curdun-gray-light);
            border-radius: 2px;
            margin-top: 15px;
            overflow: hidden;
            display: none;
        }
        
        .progress-fill {
            width: 0%;
            height: 100%;
            background: var(--curdun-violet);
            transition: width 0.3s ease;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--curdun-white);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .preview-container {
            position: relative;
            display: inline-block;
        }
        
        .preview-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }
        
        .preview-container:hover .preview-overlay {
            opacity: 1;
        }
        
        @media (max-width: 480px) {
            .profile-section {
                padding: 30px 20px;
            }
            
            .profile-photo {
                width: 120px;
                height: 120px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="upload-container">
        <div class="header">
            <h1><i class="fas fa-camera"></i> Profile Photo</h1>
            <p>Update your profile picture</p>
        </div>
        
        <div class="profile-section">
            <?php if($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <div class="user-info">
                <h3><i class="fas fa-user-circle"></i> User Information</h3>
                <div class="info-row">
                    <span class="info-label">Full Name:</span>
                    <span class="info-value"><?= htmlspecialchars($user['magaca_dhameestiran'] ?? '') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?= htmlspecialchars($user['email'] ?? '') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Role:</span>
                    <span class="info-value"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                </div>
            </div>
            
            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                <div class="profile-photo-wrapper">
                    <div class="preview-container">
                        <img src="<?= $current_photo ?>" class="profile-photo" id="profilePreview" alt="Profile Photo">
                        <div class="preview-overlay" onclick="document.getElementById('photoInput').click()">
                            <i class="fas fa-camera" style="color: white; font-size: 30px;"></i>
                        </div>
                    </div>
                    <div class="camera-icon" onclick="document.getElementById('photoInput').click()">
                        <i class="fas fa-camera"></i>
                    </div>
                    <input type="file" name="profile_photo" id="photoInput" class="file-input" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                </div>
                
                <div class="progress-bar" id="progressBar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary" id="uploadBtn">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                    <button type="button" class="btn btn-outline" onclick="window.location.href='change_password.php'">
                        <i class="fas fa-arrow-left"></i> Back to Profile
                    </button>
                </div>
            </form>
            
            <?php if($user['sawir_profile'] && $user['sawir_profile'] !== 'uploads/profiles/default.png'): ?>
            <form method="POST" style="margin-top: 15px;" onsubmit="return confirm('Are you sure you want to remove your profile photo?')">
                <button type="submit" name="remove_photo" class="btn btn-danger" style="width: 100%;">
                    <i class="fas fa-trash-alt"></i> Remove Photo
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const photoInput = document.getElementById('photoInput');
        const profilePreview = document.getElementById('profilePreview');
        const uploadForm = document.getElementById('uploadForm');
        const uploadBtn = document.getElementById('uploadBtn');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        
        // Preview image before upload
        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    profilePreview.src = event.target.result;
                };
                reader.readAsDataURL(file);
                
                // Show file info
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                const fileType = file.type;
                
                // Auto submit if file is selected
                if (confirm(`Selected: ${file.name} (${fileSize} MB)\nDo you want to upload this photo?`)) {
                    uploadForm.submit();
                }
            }
        });
        
        // AJAX upload (optional)
        function uploadWithAJAX(file) {
            const formData = new FormData();
            formData.append('profile_photo', file);
            
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percent = (e.loaded / e.total) * 100;
                    progressFill.style.width = percent + '%';
                    progressBar.style.display = 'block';
                }
            });
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showMessage(response.message, 'success');
                                if (response.photo_path) {
                                    profilePreview.src = '../' + response.photo_path;
                                }
                            } else {
                                showMessage(response.message, 'error');
                            }
                        } catch(e) {
                            showMessage('Upload completed!', 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        }
                    } else {
                        showMessage('Upload failed. Please try again.', 'error');
                    }
                    progressBar.style.display = 'none';
                    progressFill.style.width = '0%';
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Photo';
                }
            };
            
            xhr.open('POST', window.location.href);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        }
        
        function showMessage(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            
            const container = document.querySelector('.profile-section');
            const firstChild = container.firstChild;
            container.insertBefore(alertDiv, firstChild);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
        
        // Handle form submission with loading state
        uploadForm.addEventListener('submit', function() {
            if (photoInput.files.length === 0) {
                alert('Please select a photo first!');
                return false;
            }
            
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="loading"></span> Uploading...';
            return true;
        });
        
        // Drag and drop support
        const dropZone = document.querySelector('.profile-photo-wrapper');
        
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.opacity = '0.7';
        });
        
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.opacity = '1';
        });
        
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.opacity = '1';
            
            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(file);
                photoInput.files = dt.files;
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    profilePreview.src = event.target.result;
                };
                reader.readAsDataURL(file);
                
                if (confirm('Upload this photo?')) {
                    uploadForm.submit();
                }
            } else {
                alert('Please drop an image file!');
            }
        });
    </script>
</body>
</html>