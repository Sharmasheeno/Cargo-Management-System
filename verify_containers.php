<?php
require_once __DIR__ . '/config/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');

// ✅ U celin qeybta luqada
$language = $_SESSION['language'] ?? 'so';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ Hubi inay jirto qeybta bedelka luqada
    if (isset($_POST['change_language'])) {
        $_SESSION['language'] = $_POST['language'];
        $language = $_SESSION['language'];
        
        // Marka la beddelayo luqada, u celi xogta
        if (isset($_SESSION['student_verified'])) {
            $reg_no = $_SESSION['student_verified'];
            
            // Soo qaad xogta ardayga mar kale
            $stmt = $pdo->prepare("
                SELECT s.*, u.password AS user_password,
                       f.faculty_name, d.department_name, p.program_name,
                       c.class_name, sem.semester_name, camp.campus_name
                FROM students s
                JOIN users u ON u.linked_id = s.student_id
                LEFT JOIN faculties f ON f.faculty_id = s.faculty_id
                LEFT JOIN departments d ON d.department_id = s.department_id
                LEFT JOIN programs p ON p.program_id = s.program_id
                LEFT JOIN classes c ON c.class_id = s.class_id
                LEFT JOIN semester sem ON sem.semester_id = s.semester_id
                LEFT JOIN campus camp ON camp.campus_id = s.campus_id
                WHERE s.reg_no = ? AND u.linked_table = 'student'
                LIMIT 1
            ");
            $stmt->execute([$reg_no]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                // U celi xogta cusub ee luqada
                displayStudentInfo($student, $pdo, $language);
                exit;
            }
        }
    }
    
    // ✅ Habka caadiga ah ee xaqiijinta
    $reg_no   = trim($_POST['reg_no'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($reg_no === '' || $password === '') {
        $error_msg = ($language === 'en') 
            ? "Please fill in all fields." 
            : "Fadlan buuxi meelaha oo dhan.";
        
        echo "<div class='error-message'>
                <div class='error-icon'><i class='fas fa-exclamation-triangle'></i></div>
                <div class='error-text'>{$error_msg}</div>
              </div>";
        exit;
    }

    // ✅ Soo qaad macluumaadka ardayga
    $stmt = $pdo->prepare("
        SELECT s.*, u.password AS user_password,
               f.faculty_name, d.department_name, p.program_name,
               c.class_name, sem.semester_name, camp.campus_name
        FROM students s
        JOIN users u ON u.linked_id = s.student_id
        LEFT JOIN faculties f ON f.faculty_id = s.faculty_id
        LEFT JOIN departments d ON d.department_id = s.department_id
        LEFT JOIN programs p ON p.program_id = s.program_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN semester sem ON sem.semester_id = s.semester_id
        LEFT JOIN campus camp ON camp.campus_id = s.campus_id
        WHERE s.reg_no = ? AND u.linked_table = 'student'
        LIMIT 1
    ");
    $stmt->execute([$reg_no]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $error_msg = ($language === 'en')
            ? "Registration number not found!"
            : "Lambarka diiwaangelinta aan la helin arday!";
        
        echo "<div class='error-message'>
                <div class='error-icon'><i class='fas fa-times-circle'></i></div>
                <div class='error-text'>{$error_msg}</div>
              </div>";
        exit;
    }

    if (!password_verify($password, $student['user_password'])) {
        $error_msg = ($language === 'en')
            ? "Incorrect password!"
            : "Furaha sirta ah waa khalad!";
        
        echo "<div class='error-message'>
                <div class='error-icon'><i class='fas fa-times-circle'></i></div>
                <div class='error-text'>{$error_msg}</div>
              </div>";
        exit;
    }

    $_SESSION['student_verified'] = $student['reg_no'];
    
    // ✅ Bandhig xogta ardayga
    displayStudentInfo($student, $pdo, $language);
    exit;
}

// ✅ Function-ka lagu muujinayo xogta ardayga
function displayStudentInfo($student, $pdo, $language = 'so') {
    // ✅ Soo qaad macluumaadka waalidka
    $parent_stmt = $pdo->prepare("
        SELECT ps.relation_type, p.full_name AS parent_name, p.phone AS parent_phone, p.email AS parent_email
        FROM parent_student ps
        LEFT JOIN parents p ON p.parent_id = ps.parent_id
        WHERE ps.student_id = ?
        LIMIT 1
    ");
    $parent_stmt->execute([$student['student_id']]);
    $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);

    // ✅ SOO QAAD LAST PROMATION - CUSUB
    $promotion_stmt = $pdo->prepare("
        SELECT 
            ph.*,
            old_sem.semester_name AS old_semester_name,
            new_sem.semester_name AS new_semester_name,
            old_camp.campus_name AS old_campus_name,
            new_camp.campus_name AS new_campus_name,
            old_fac.faculty_name AS old_faculty_name,
            new_fac.faculty_name AS new_faculty_name,
            old_dept.department_name AS old_department_name,
            new_dept.department_name AS new_department_name,
            old_prog.program_name AS old_program_name,
            new_prog.program_name AS new_program_name,
            old_class.class_name AS old_class_name,
            new_class.class_name AS new_class_name
        FROM promotion_history ph
        LEFT JOIN semester old_sem ON old_sem.semester_id = ph.old_semester_id
        LEFT JOIN semester new_sem ON new_sem.semester_id = ph.new_semester_id
        LEFT JOIN campus old_camp ON old_camp.campus_id = ph.old_campus_id
        LEFT JOIN campus new_camp ON new_camp.campus_id = ph.new_campus_id
        LEFT JOIN faculties old_fac ON old_fac.faculty_id = ph.old_faculty_id
        LEFT JOIN faculties new_fac ON new_fac.faculty_id = ph.new_faculty_id
        LEFT JOIN departments old_dept ON old_dept.department_id = ph.old_department_id
        LEFT JOIN departments new_dept ON new_dept.department_id = ph.new_department_id
        LEFT JOIN programs old_prog ON old_prog.program_id = ph.old_program_id
        LEFT JOIN programs new_prog ON new_prog.program_id = ph.new_program_id
        LEFT JOIN classes old_class ON old_class.class_id = ph.old_class_id
        LEFT JOIN classes new_class ON new_class.class_id = ph.new_class_id
        WHERE ph.student_id = ?
        ORDER BY ph.promotion_date DESC
        LIMIT 1
    ");
    $promotion_stmt->execute([$student['student_id']]);
    $last_promotion = $promotion_stmt->fetch(PDO::FETCH_ASSOC);

    // ✅ Soo qaad jadwalka maanta
    $today = date('D');
    $timetable_stmt = $pdo->prepare("
        SELECT tt.*, sub.subject_name, r.room_name, t.teacher_name AS teacher_name
        FROM timetable tt
        LEFT JOIN subject sub ON sub.subject_id = tt.subject_id
        LEFT JOIN rooms r ON r.room_id = tt.room_id
        LEFT JOIN teachers t ON t.teacher_id = tt.teacher_id
        WHERE tt.class_id = ? AND tt.day_of_week = ? AND tt.status = 'active'
        ORDER BY tt.start_time ASC
    ");
    $timetable_stmt->execute([$student['class_id'], $today]);
    $classes_today = $timetable_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Ku habeyn luqadaha
    $translations = [
        'so' => [
            'title' => 'Xaqiijinta Arday | Jaamacadda Hormuud',
            'header' => 'Xaqiijinta Arday',
            'active' => 'Firfircoon',
            'inactive' => 'Aan Firfircoonin / Dhaafay',
            'education_info' => 'Macluumaadka Waxbarashada',
            'personal_info' => 'Macluumaadka Shakhsiga',
            'parent_info' => 'Macluumaadka Waalidka',
            'campus' => 'Dhismaha',
            'faculty' => 'Kulliyad',
            'department' => 'Waaxda',
            'program' => 'Barnaamijka',
            'class' => 'Fasalka',
            'semester' => 'Muddada',
            'address' => 'Cinwaanka',
            'phone' => 'Telefoonka',
            'gender' => 'Jinsiga',
            'dob' => 'Taariikhda Dhalashada',
            'relation' => 'Xiriirka',
            'timetable' => 'Jadwalka Maanta',
            'classes_today' => 'Fasallo Maanta',
            'yes_classes' => 'Haa, waxaa uu leeyahay fasallo maanta',
            'no_classes' => 'Ma jiro fasallo maanta',
            'course' => 'Koorsada',
            'start_time' => 'Bilaawga Wakhtiga',
            'end_time' => 'Dhamaadka Wakhtiga',
            'room' => 'Qolka',
            'teacher' => 'Macallinka',
            'no_classes_msg' => 'Ma jiro fasallo qorsheysan maanta. Ku raaxayso wakhtigaada bilaashka ah!',
            'back_login' => 'Ku soo noqo Galitaanka',
            'download' => 'Soo deji Xogta',
            'print' => 'Daabac',
            'change_lang' => 'Bedel Luqada',
            'somali' => 'Af-Soomaali',
            'english' => 'English',
            // PROMATION TRANSLATIONS
            'last_promotion' => 'Promationka ugu Danbeeyay',
            'old_semester' => 'Semesterka Hore',
            'new_semester' => 'Semesterka Cusub',
            'promotion_date' => 'Taariikhda Promation',
            'no_promotion' => 'Ma jiro promation hore',
            'from_semester' => 'Ka Bilaw',
            'to_semester' => 'U Gudbay',
            'promoted_on' => 'Taariikhda',
            'old_campus' => 'Dhismaha Hore',
            'new_campus' => 'Dhismaha Cusub',
            'old_faculty' => 'Kulliyadda Hore',
            'new_faculty' => 'Kulliyadda Cusub',
            'old_department' => 'Waaxda Hore',
            'new_department' => 'Waaxda Cusub',
            'old_program' => 'Barnaamijka Hore',
            'new_program' => 'Barnaamijka Cusub',
            'old_class' => 'Fasalka Hore',
            'new_class' => 'Fasalka Cusub',
            'campus_changed' => 'Dhismaha wuu isbeddelay',
            'campus_same' => 'Dhismaha isku mid ah'
        ],
        'en' => [
            'title' => 'Student Verification | Hormuud University',
            'header' => 'Student Verification',
            'active' => 'Active',
            'inactive' => 'Inactive / Graduated',
            'education_info' => 'Education Information',
            'personal_info' => 'Personal Information',
            'parent_info' => 'Parent Information',
            'campus' => 'Campus',
            'faculty' => 'Faculty',
            'department' => 'Department',
            'program' => 'Program',
            'class' => 'Class',
            'semester' => 'Semester',
            'address' => 'Address',
            'phone' => 'Phone',
            'gender' => 'Gender',
            'dob' => 'Date of Birth',
            'relation' => 'Relation',
            'timetable' => "Today's Timetable",
            'classes_today' => 'Classes Today',
            'yes_classes' => 'Yes, there are classes today',
            'no_classes' => 'No classes today',
            'course' => 'Course',
            'start_time' => 'Start Time',
            'end_time' => 'End Time',
            'room' => 'Room',
            'teacher' => 'Teacher',
            'no_classes_msg' => 'No scheduled classes today. Enjoy your free time!',
            'back_login' => 'Back to Login',
            'download' => 'Download Data',
            'print' => 'Print',
            'change_lang' => 'Change Language',
            'somali' => 'Somali',
            'english' => 'English',
            // PROMOTION TRANSLATIONS
            'last_promotion' => 'Last Promotion',
            'old_semester' => 'Old Semester',
            'new_semester' => 'New Semester',
            'promotion_date' => 'Promotion Date',
            'no_promotion' => 'No previous promotion',
            'from_semester' => 'From Semester',
            'to_semester' => 'To Semester',
            'promoted_on' => 'Promoted On',
            'old_campus' => 'Old Campus',
            'new_campus' => 'New Campus',
            'old_faculty' => 'Old Faculty',
            'new_faculty' => 'New Faculty',
            'old_department' => 'Old Department',
            'new_department' => 'New Department',
            'old_program' => 'Old Program',
            'new_program' => 'New Program',
            'old_class' => 'Old Class',
            'new_class' => 'New Class',
            'campus_changed' => 'Campus changed',
            'campus_same' => 'Same campus'
        ]
    ];
    
    $t = $translations[$language];
    
    // ✅ Qeybaha kale ee xogta
    $photo   = htmlspecialchars($student['photo_path'] ?: 'assets/img/default_avatar.png', ENT_QUOTES, 'UTF-8');
    $name    = htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8');
    $email   = htmlspecialchars($student['email'] ?: 'N/A', ENT_QUOTES, 'UTF-8');
    $isActive = strtolower($student['status']) === 'active';
    $statusText = $isActive ? $t['active'] : $t['inactive'];
    $status  = $isActive ? '<i class="fas fa-check-circle"></i> ' . $statusText : '<i class="fas fa-times-circle"></i> ' . $statusText;
    $statusColor = $isActive ? '#00843D' : '#C62828';
    $statusIcon = $isActive ? 'fa-check-circle' : 'fa-times-circle';
    $statusIndicator = $isActive ? 'fa-check' : 'fa-times';

    $parentName  = $parent['parent_name']  ?? 'N/A';
    $parentPhone = $parent['parent_phone'] ?? 'N/A';
    $parentEmail = $parent['parent_email'] ?? 'N/A';
    $relation    = $language === 'so' ? ucfirst($parent['relation_type'] ?? 'Waalid') : ucfirst($parent['relation_type'] ?? 'Parent');

    // ✅ Maalinta maanta
    $dayMap = [
        'so' => [
            'Mon' => 'Isniin',
            'Tue' => 'Talaado',
            'Wed' => 'Arbaco',
            'Thu' => 'Khamiis',
            'Fri' => 'Jimco',
            'Sat' => 'Sabti',
            'Sun' => 'Axad'
        ],
        'en' => [
            'Mon' => 'Monday',
            'Tue' => 'Tuesday',
            'Wed' => 'Wednesday',
            'Thu' => 'Thursday',
            'Fri' => 'Friday',
            'Sat' => 'Saturday',
            'Sun' => 'Sunday'
        ]
    ];
    
    $todayFull = $dayMap[$language][$today] ?? $today;

    echo "
    <!DOCTYPE html>
    <html lang='{$language}'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$t['title']}</title>
        <link rel='icon' type='image/png' href='images.png'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css'>
        <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
    </head>
    <body>
    <div class='verify-container'>
        <div class='verify-card'>
            <div class='card-header'>
                <div class='header-left'>
                    <h1><i class='fas fa-user-check'></i> {$t['header']}</h1>
                    <div class='status-badge' style='background-color: {$statusColor};'>
                        {$status}
                    </div>
                </div>
                
                <div class='header-right'>
                    <form method='POST' class='language-form' id='languageForm'>
                        <input type='hidden' name='change_language' value='1'>
                        <div class='language-selector'>
                            <i class='fas fa-language'></i>
                            <select name='language' class='language-dropdown' onchange='document.getElementById(\"languageForm\").submit()'>
                                <option value='so' " . ($language === 'so' ? 'selected' : '') . ">{$t['somali']}</option>
                                <option value='en' " . ($language === 'en' ? 'selected' : '') . ">{$t['english']}</option>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class='profile-section'>
                <div class='profile-image-container'>
                    <img src='{$photo}' alt='Sawir' class='profile-image'>
                    <div class='profile-status-indicator' style='background-color: {$statusColor};'>
                        <i class='fas {$statusIndicator}'></i>
                    </div>
                </div>
                <div class='profile-info'>
                    <h2 class='student-name'><i class='fas fa-user-graduate'></i> {$name}</h2>
                    <p class='student-email'><i class='fas fa-envelope'></i> {$email}</p>
                    <p class='student-reg'><i class='fas fa-id-card'></i> {$student['reg_no']}</p>
                </div>
            </div>

            <div class='info-grid'>
                <div class='info-section'>
                    <h3 class='section-title'>
                        <i class='fas fa-graduation-cap'></i>
                        {$t['education_info']}
                    </h3>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-university'></i> {$t['campus']}:</span>
                        <span class='info-value'>{$student['campus_name']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-school'></i> {$t['faculty']}:</span>
                        <span class='info-value'>{$student['faculty_name']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-building'></i> {$t['department']}:</span>
                        <span class='info-value'>{$student['department_name']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-book'></i> {$t['program']}:</span>
                        <span class='info-value'>{$student['program_name']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-users'></i> {$t['class']}:</span>
                        <span class='info-value'>{$student['class_name']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-calendar-alt'></i> {$t['semester']}:</span>
                        <span class='info-value'>{$student['semester_name']}</span>
                    </div>
                    
                    <!-- LAST PROMATION SECTION -->
                    <div class='promation-section'>
                        <h4 class='promation-title'>
                            <i class='fas fa-history'></i>
                            {$t['last_promotion']}
                        </h4>";
    
    if ($last_promotion) {
        $old_campus_name = $last_promotion['old_campus_name'] ?? 'N/A';
        $new_campus_name = $last_promotion['new_campus_name'] ?? 'N/A';
        $campus_changed = ($old_campus_name != $new_campus_name && $old_campus_name != 'N/A' && $new_campus_name != 'N/A');
        
        echo "<div class='promation-details'>
                <div class='promation-row'>
                    <span class='promation-label'><i class='fas fa-calendar'></i> {$t['promotion_date']}:</span>
                    <span class='promation-value'>" . htmlspecialchars($last_promotion['promotion_date'] ?? 'N/A') . "</span>
                </div>
                <div class='promation-row'>
                    <span class='promation-label'><i class='fas fa-arrow-right'></i> {$t['from_semester']}:</span>
                    <span class='promation-value'>" . htmlspecialchars($last_promotion['old_semester_name'] ?? ($last_promotion['old_semester_id'] ?? 'N/A')) . "</span>
                </div>
                <div class='promation-row'>
                    <span class='promation-label'><i class='fas fa-arrow-right'></i> {$t['to_semester']}:</span>
                    <span class='promation-value'>" . htmlspecialchars($last_promotion['new_semester_name'] ?? ($last_promotion['new_semester_id'] ?? 'N/A')) . "</span>
                </div>";
        
        // Show campus change if occurred
        if ($campus_changed) {
            echo "<div class='promation-row'>
                    <span class='promation-label'><i class='fas fa-exchange-alt'></i> {$t['campus']}:</span>
                    <span class='promation-value'>
                        " . htmlspecialchars($old_campus_name) . " → " . 
                        htmlspecialchars($new_campus_name) . "
                    </span>
                </div>";
        }
        
        // Show other hierarchy changes if they exist
        $changes = [];
        
        // Faculty change
        if (!empty($last_promotion['old_faculty_name']) && !empty($last_promotion['new_faculty_name']) && 
            $last_promotion['old_faculty_name'] != $last_promotion['new_faculty_name']) {
            $changes[] = "{$t['faculty']}: " . htmlspecialchars($last_promotion['old_faculty_name']) . " → " . htmlspecialchars($last_promotion['new_faculty_name']);
        }
        
        // Department change
        if (!empty($last_promotion['old_department_name']) && !empty($last_promotion['new_department_name']) && 
            $last_promotion['old_department_name'] != $last_promotion['new_department_name']) {
            $changes[] = "{$t['department']}: " . htmlspecialchars($last_promotion['old_department_name']) . " → " . htmlspecialchars($last_promotion['new_department_name']);
        }
        
        // Program change
        if (!empty($last_promotion['old_program_name']) && !empty($last_promotion['new_program_name']) && 
            $last_promotion['old_program_name'] != $last_promotion['new_program_name']) {
            $changes[] = "{$t['program']}: " . htmlspecialchars($last_promotion['old_program_name']) . " → " . htmlspecialchars($last_promotion['new_program_name']);
        }
        
        // Class change
        if (!empty($last_promotion['old_class_name']) && !empty($last_promotion['new_class_name']) && 
            $last_promotion['old_class_name'] != $last_promotion['new_class_name']) {
            $changes[] = "{$t['class']}: " . htmlspecialchars($last_promotion['old_class_name']) . " → " . htmlspecialchars($last_promotion['new_class_name']);
        }
        
        // If there are any changes, show them
        if (!empty($changes)) {
            echo "<div class='promation-changes'>
                    <span class='changes-label'><i class='fas fa-exchange-alt'></i> Isbeddelada Kale:</span>
                    <div class='changes-list'>";
            foreach ($changes as $change) {
                echo "<div class='change-item'><i class='fas fa-caret-right'></i> {$change}</div>";
            }
            echo "</div>
                  </div>";
        }
        
        // Show remarks if exists
        if (!empty($last_promotion['remarks'])) {
            echo "<div class='promation-remarks'>
                    <span class='remarks-label'><i class='fas fa-comment'></i> Qoraal:</span>
                    <span class='remarks-text'>" . htmlspecialchars($last_promotion['remarks']) . "</span>
                  </div>";
        }
        
        echo "</div>";
    } else {
        echo "<div class='no-promation-message'>
                <div class='no-promation-icon'>
                    <i class='fas fa-info-circle'></i>
                </div>
                <p>{$t['no_promotion']}</p>
              </div>";
    }
    
    echo "</div>
                </div>

                <div class='info-section'>
                    <h3 class='section-title'>
                        <i class='fas fa-user-circle'></i>
                        {$t['personal_info']}
                    </h3>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-home'></i> {$t['address']}:</span>
                        <span class='info-value'>{$student['address']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-phone'></i> {$t['phone']}:</span>
                        <span class='info-value'>{$student['phone_number']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-venus-mars'></i> {$t['gender']}:</span>
                        <span class='info-value'>{$student['gender']}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-birthday-cake'></i> {$t['dob']}:</span>
                        <span class='info-value'>{$student['dob']}</span>
                    </div>
                </div>

                <div class='info-section'>
                    <h3 class='section-title'>
                        <i class='fas fa-users'></i>
                        {$t['parent_info']}
                    </h3>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-user-tie'></i> Magaca:</span>
                        <span class='info-value'>{$parentName}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-handshake'></i> {$t['relation']}:</span>
                        <span class='info-value'>{$relation}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-mobile-alt'></i> {$t['phone']}:</span>
                        <span class='info-value'>{$parentPhone}</span>
                    </div>
                    <div class='info-item'>
                        <span class='info-label'><i class='fas fa-at'></i> Emailka:</span>
                        <span class='info-value'>{$parentEmail}</span>
                    </div>
                </div>
            </div>

            <div class='timetable-section'>
                <h3 class='section-title'>
                    <i class='fas fa-calendar-day'></i>
                    {$t['timetable']} (" . htmlspecialchars($todayFull) . ")
                </h3>
                
                <div class='class-status-indicator'>
                    <span class='indicator-label'><i class='fas fa-clock'></i> {$t['classes_today']}:</span>
                    <span class='indicator-value'>" . 
                    (count($classes_today) > 0 ? 
                    "<i class='fas fa-check-circle' style='color: #00843D;'></i> {$t['yes_classes']}" : 
                    "<i class='fas fa-times-circle' style='color: #C62828;'></i> {$t['no_classes']}") . 
                    "</span>
                </div>";

    if (count($classes_today) > 0) {
        echo "<div class='table-container'>
                <table class='timetable-table'>
                    <thead>
                        <tr>
                            <th><i class=''></i> {$t['course']}</th>
                            <th><i class=''></i> {$t['start_time']}</th>
                            <th><i class=''></i> {$t['end_time']}</th>
                            <th><i class=''></i> {$t['room']}</th>
                            <th><i class=''></i> {$t['teacher']}</th>
                        </tr>
                    </thead>
                    <tbody>";
        foreach ($classes_today as $class) {
            echo "<tr>
                    <td><i class='fas fa-book text-muted'></i> " . htmlspecialchars($class['subject_name'] ?? 'N/A') . "</td>
                    <td><i class='fas fa-clock text-muted'></i> " . htmlspecialchars(substr($class['start_time'], 0, 5)) . "</td>
                    <td><i class='fas fa-clock text-muted'></i> " . htmlspecialchars(substr($class['end_time'], 0, 5)) . "</td>
                    <td><i class='fas fa-door-open text-muted'></i> " . htmlspecialchars($class['room_name'] ?? 'N/A') . "</td>
                    <td><i class='fas fa-user-tie text-muted'></i> " . htmlspecialchars($class['teacher_name'] ?? 'N/A') . "</td>
                </tr>";
        }
        echo "</tbody>
                </table>
              </div>";
    } else {
        echo "<div class='no-classes-message'>
                <div class='no-classes-icon'>
                    <i class='fas fa-calendar-times'></i>
                </div>
                <p>{$t['no_classes_msg']}</p>
              </div>";
    }

    echo "
            </div>

            <div class='action-buttons'>
                <button class='btn-primary' onclick='redirectLogin()'>
                    <i class='fas fa-sign-out-alt'></i> {$t['back_login']}
                </button>
            </div>
        </div>
    </div>

    <script>
    function redirectLogin() {
        window.location.href = 'login.php';
    }
    
    function printDocument() {
        window.print();
    }
    
    function downloadData() {
        alert('" . ($language === 'en' ? 'Download feature will be implemented soon!' : 'Qeybta soo dejinta waa la dhamaystiri doonaa!') . "');
    }
    </script>

    <style>
    :root {
        --primary-green: #00843D;
        --blue-accent: #0072CE;
        --light-green: #00A651;
        --dark-gray: #333333;
        --light-gray-bg: #F5F9F7;
        --danger-red: #C62828;
        --warning-amber: #FFB400;
        --surface-white: #FFFFFF;
        --border-color: #E0E0E0;
        --shadow-light: rgba(0, 132, 61, 0.08);
        --shadow-medium: rgba(0, 132, 61, 0.12);
        --text-muted: #666666;
        --promation-blue: #1E88E5;
    }
    
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }
    
    body {
        font-family: 'Poppins', 'Segoe UI', sans-serif;
        background: var(--light-gray-bg);
        color: var(--dark-gray);
        line-height: 1.6;
        font-weight: 400;
    }
    
    .verify-container {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 20px;
        min-height: 100vh;
        animation: fadeIn 0.5s ease-in-out;
    }
    
    .verify-card {
        background: var(--surface-white);
        border-radius: 16px;
        box-shadow: 0 8px 30px var(--shadow-medium);
        width: 100%;
        max-width: 1000px;
        overflow: hidden;
        color: var(--dark-gray);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 25px 30px;
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: white;
    }
    
    .header-left {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .card-header h1 {
        font-size: 26px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .status-badge {
        padding: 10px 18px;
        border-radius: 25px;
        font-size: 15px;
        font-weight: 600;
        color: white;
        display: flex;
        align-items: center;
        gap: 10px;
        background-color: var(--primary-green);
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    
    .header-right {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .language-form {
        margin: 0;
    }
    
    .language-selector {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.2);
        padding: 8px 15px;
        border-radius: 25px;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    
    .language-selector i {
        font-size: 18px;
    }
    
    .language-dropdown {
        background: transparent;
        border: none;
        color: white;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        outline: none;
        min-width: 100px;
    }
    
    .language-dropdown option {
        background: var(--primary-green);
        color: white;
    }
    
    .profile-section {
        display: flex;
        align-items: center;
        padding: 30px;
        border-bottom: 2px solid var(--border-color);
        background: linear-gradient(to right, rgba(245, 249, 247, 0.8), rgba(0, 132, 61, 0.05));
    }
    
    .profile-image-container {
        position: relative;
        margin-right: 25px;
    }
    
    .profile-image {
        width: 130px;
        height: 130px;
        border-radius: 50%;
        border: 5px solid var(--light-green);
        object-fit: cover;
        box-shadow: 0 8px 20px rgba(0, 132, 61, 0.15);
    }
    
    .profile-status-indicator {
        position: absolute;
        bottom: 10px;
        right: 10px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 3px solid white;
        background: var(--primary-green);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 12px;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
    }
    
    .profile-info {
        flex: 1;
    }
    
    .student-name {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary-green);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .student-email, .student-reg {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 8px;
        font-size: 17px;
    }
    
    .student-email {
        color: var(--blue-accent);
    }
    
    .student-reg {
        color: var(--dark-gray);
        font-weight: 500;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 25px;
        padding: 30px;
    }
    
    .info-section {
        background: var(--light-gray-bg);
        border-radius: 14px;
        padding: 25px;
        border-left: 5px solid var(--primary-green);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .info-section:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 18px rgba(0, 132, 61, 0.1);
    }
    
    .section-title {
        display: flex;
        align-items: center;
        font-size: 20px;
        font-weight: 600;
        color: var(--primary-green);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid rgba(0, 132, 61, 0.2);
        gap: 12px;
    }
    
    .info-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 14px;
        padding-bottom: 14px;
        border-bottom: 1px dashed rgba(0, 132, 61, 0.15);
    }
    
    .info-item:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .info-label {
        font-weight: 600;
        color: var(--dark-gray);
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 15px;
    }
    
    .info-value {
        color: var(--dark-gray);
        text-align: right;
        max-width: 60%;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
        font-size: 15px;
    }
    
    /* PROMATION SECTION STYLES */
    .promation-section {
        margin-top: 25px;
        padding-top: 20px;
        border-top: 2px solid var(--promation-blue);
    }
    
    .promation-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--promation-blue);
        font-size: 17px;
        margin-bottom: 15px;
        font-weight: 600;
    }
    
    .promation-details {
        background: rgba(30, 136, 229, 0.08);
        border-radius: 10px;
        padding: 18px;
        border-left: 4px solid var(--promation-blue);
        box-shadow: 0 3px 10px rgba(30, 136, 229, 0.1);
    }
    
    .promation-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px dashed rgba(30, 136, 229, 0.3);
    }
    
    .promation-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    
    .promation-label {
        font-weight: 600;
        color: var(--promation-blue);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
    }
    
    .promation-value {
        color: var(--dark-gray);
        font-weight: 500;
        font-size: 14px;
        text-align: right;
    }
    
    .promation-changes {
        margin-top: 15px;
        padding: 12px;
        background: rgba(255, 193, 7, 0.08);
        border-radius: 8px;
        border: 1px dashed #FFB400;
    }
    
    .changes-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: #FFB400;
        margin-bottom: 8px;
        font-size: 13px;
    }
    
    .changes-list {
        padding-left: 20px;
    }
    
    .change-item {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 13px;
        color: var(--dark-gray);
    }
    
    .change-item i {
        color: #FFB400;
        font-size: 10px;
    }
    
    .promation-remarks {
        margin-top: 15px;
        padding: 12px;
        background: rgba(76, 175, 80, 0.08);
        border-radius: 8px;
        border-left: 3px solid #4CAF50;
    }
    
    .remarks-label {
        font-weight: 600;
        color: #4CAF50;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 5px;
        font-size: 13px;
    }
    
    .remarks-text {
        color: var(--dark-gray);
        font-style: italic;
        font-size: 13px;
        line-height: 1.4;
    }
    
    .no-promation-message {
        background: rgba(158, 158, 158, 0.08);
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        border: 1px dashed #9E9E9E;
    }
    
    .no-promation-icon {
        font-size: 30px;
        margin-bottom: 10px;
        color: #9E9E9E;
    }
    
    .no-promation-message p {
        color: #9E9E9E;
        font-weight: 500;
        font-size: 14px;
    }
    
    .timetable-section {
        padding: 0 30px 30px;
    }
    
    .class-status-indicator {
        display: flex;
        justify-content: space-between;
        background: linear-gradient(to right, rgba(0, 114, 206, 0.1), rgba(0, 132, 61, 0.1));
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        border-left: 5px solid var(--blue-accent);
        border-right: 5px solid var(--blue-accent);
        font-size: 16px;
    }
    
    .indicator-label, .indicator-value {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
    }
    
    .indicator-label {
        color: var(--blue-accent);
    }
    
    .table-container {
        overflow-x: auto;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0, 132, 61, 0.1);
        border: 1px solid rgba(0, 132, 61, 0.1);
    }
    
    .timetable-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--surface-white);
    }
    
    .timetable-table th {
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: white;
        padding: 16px 15px;
        text-align: left;
        font-weight: 600;
        font-size: 15px;
    }
    
    .timetable-table th i {
        margin-right: 10px;
        font-size: 16px;
    }
    
    .timetable-table td {
        padding: 14px 15px;
        border-bottom: 1px solid rgba(0, 132, 61, 0.1);
        font-weight: 500;
    }
    
    .timetable-table td i.text-muted {
        margin-right: 10px;
        color: var(--text-muted);
        font-size: 14px;
        width: 20px;
        text-align: center;
    }
    
    .timetable-table tr:last-child td {
        border-bottom: none;
    }
    
    .timetable-table tr:hover {
        background: rgba(0, 132, 61, 0.05);
    }
    
    .no-classes-message {
        text-align: center;
        padding: 50px 30px;
        background: linear-gradient(to right, rgba(245, 249, 247, 0.8), rgba(0, 114, 206, 0.05));
        border-radius: 12px;
        border: 2px dashed var(--blue-accent);
    }
    
    .no-classes-icon {
        font-size: 60px;
        margin-bottom: 20px;
        color: var(--blue-accent);
        opacity: 0.8;
    }
    
    .no-classes-message p {
        font-size: 18px;
        color: var(--dark-gray);
        font-weight: 500;
    }
    
    .action-buttons {
        padding: 0 30px 30px;
        text-align: center;
        display: flex;
        gap: 20px;
        justify-content: center;
    }
    
    .btn-primary {
        border: none;
        padding: 14px 28px;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(0, 114, 206, 0.2);
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 200px;
        justify-content: center;
        background: linear-gradient(135deg, var(--blue-accent), #005bb5);
        color: white;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #005bb5, #004a9b);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0, 114, 206, 0.3);
    }
    
    .error-message {
        display: flex;
        align-items: center;
        background: #FFEBEE;
        color: var(--danger-red);
        padding: 15px 20px;
        border-radius: 10px;
        margin: 15px 0;
        border-left: 5px solid var(--danger-red);
        box-shadow: 0 4px 12px rgba(198, 40, 40, 0.1);
    }
    
    .error-icon {
        margin-right: 15px;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
    }
    
    .error-text {
        font-weight: 600;
        font-size: 15px;
    }
    
    .text-muted {
        color: var(--text-muted) !important;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .verify-container {
            padding: 10px;
        }
        
        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
        }
        
        .header-left, .header-right {
            width: 100%;
            justify-content: space-between;
        }
        
        .card-header h1 {
            font-size: 22px;
        }
        
        .status-badge {
            align-self: stretch;
            justify-content: center;
        }
        
        .language-selector {
            margin-top: 10px;
            width: 100%;
            justify-content: center;
        }
        
        .profile-section {
            flex-direction: column;
            text-align: center;
            padding: 25px;
        }
        
        .profile-image-container {
            margin-right: 0;
            margin-bottom: 20px;
        }
        
        .profile-image {
            width: 120px;
            height: 120px;
        }
        
        .student-name {
            font-size: 26px;
            justify-content: center;
        }
        
        .student-email, .student-reg {
            justify-content: center;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
            padding: 20px;
            gap: 20px;
        }
        
        .info-section {
            padding: 20px;
        }
        
        .timetable-section {
            padding: 0 20px 20px;
        }
        
        .class-status-indicator {
            flex-direction: column;
            gap: 10px;
            padding: 15px;
        }
        
        .action-buttons {
            padding: 0 20px 20px;
            flex-direction: column;
        }
        
        .btn-primary {
            width: 100%;
            min-width: unset;
        }
        
        .info-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        .info-value {
            text-align: left;
            max-width: 100%;
            margin-top: 5px;
        }
        
        .promation-row {
            flex-direction: column;
            gap: 5px;
        }
        
        .promation-value {
            text-align: left;
            margin-top: 5px;
        }
        
        .timetable-table th, 
        .timetable-table td {
            padding: 12px 10px;
            font-size: 14px;
        }
        
        .timetable-table th i,
        .timetable-table td i {
            font-size: 14px;
        }
    }
    
    @media (max-width: 480px) {
        .card-header h1 {
            font-size: 20px;
        }
        
        .student-name {
            font-size: 22px;
        }
        
        .student-email, .student-reg {
            font-size: 15px;
        }
        
        .section-title {
            font-size: 18px;
        }
        
        .info-label, .info-value {
            font-size: 14px;
        }
        
        .promation-title {
            font-size: 16px;
        }
    }
    </style>
    </body>
    </html>";
}

// ✅ Haddii aan POST ahayn, tus form-ka galitaanka
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $language = $_SESSION['language'] ?? 'so';
    $translations = [
        'so' => [
            'title' => 'Xaqiijinta Arday | Jaamacadda Hormuud',
            'welcome' => 'Ku soo dhawoow Xaqiijinta Arday',
            'subtitle' => 'Gali macluumaadkaaga si aad u xaqiijiso',
            'reg_no' => 'Lambarka Diiwaangelinta',
            'password' => 'Furaha Sirta Ah',
            'verify' => 'Xaqiiji',
            'change_lang' => 'Bedel Luqada',
            'somali' => 'Af-Soomaali',
            'english' => 'English'
        ],
        'en' => [
            'title' => 'Student Verification | Hormuud University',
            'welcome' => 'Welcome to Student Verification',
            'subtitle' => 'Enter your details to verify',
            'reg_no' => 'Registration Number',
            'password' => 'Password',
            'verify' => 'Verify',
            'change_lang' => 'Change Language',
            'somali' => 'Somali',
            'english' => 'English'
        ]
    ];
    
    $t = $translations[$language];
    
    echo "
    <!DOCTYPE html>
    <html lang='{$language}'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$t['title']}</title>
        <link rel='icon' type='image/png' href='images.png'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css'>
        <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
    </head>
    <body>
    <div class='login-container'>
        <div class='login-card'>
            <div class='login-header'>
                <h1><i class='fas fa-user-check'></i> {$t['welcome']}</h1>
                <p>{$t['subtitle']}</p>
                
                <form method='POST' class='language-form-top'>
                    <input type='hidden' name='change_language' value='1'>
                    <div class='language-selector-top'>
                        <i class='fas fa-language'></i>
                        <select name='language' class='language-dropdown-top' onchange='this.form.submit()'>
                            <option value='so' " . ($language === 'so' ? 'selected' : '') . ">{$t['somali']}</option>
                            <option value='en' " . ($language === 'en' ? 'selected' : '') . ">{$t['english']}</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <form method='POST' class='login-form' id='loginForm'>
                <div class='form-group'>
                    <label for='reg_no'><i class='fas fa-id-card'></i> {$t['reg_no']}</label>
                    <input type='text' id='reg_no' name='reg_no' placeholder='Gali lambarkaaga diiwaangelinta' required>
                </div>
                
                <div class='form-group'>
                    <label for='password'><i class='fas fa-lock'></i> {$t['password']}</label>
                    <input type='password' id='password' name='password' placeholder='Gali furahaaga sirta ah' required>
                    <button type='button' class='toggle-password' onclick='togglePassword()'>
                        <i class='fas fa-eye'></i>
                    </button>
                </div>
                
                <button type='submit' class='login-btn'>
                    <i class='fas fa-check-circle'></i> {$t['verify']}
                </button>
            </form>
            
            <div class='login-footer'>
                <p><i class='fas fa-info-circle'></i> Macluumaadka waa mid sir ah oo looma wadaagin dad kale</p>
            </div>
        </div>
    </div>
    
    <div id='errorContainer'></div>
    
    <script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleBtn = document.querySelector('.toggle-password i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleBtn.className = 'fas fa-eye-slash';
        } else {
            passwordInput.type = 'password';
            toggleBtn.className = 'fas fa-eye';
        }
    }
    
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            if (data.includes('verify-container')) {
                document.body.innerHTML = data;
            } else {
                document.getElementById('errorContainer').innerHTML = data;
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    });
    </script>
    
    <style>
    :root {
        --primary-green: #00843D;
        --blue-accent: #0072CE;
        --light-green: #00A651;
        --dark-gray: #333333;
        --light-gray-bg: #F5F9F7;
        --surface-white: #FFFFFF;
        --border-color: #E0E0E0;
        --shadow-light: rgba(0, 132, 61, 0.08);
        --shadow-medium: rgba(0, 132, 61, 0.12);
    }
    
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }
    
    body {
        font-family: 'Poppins', 'Segoe UI', sans-serif;
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: var(--dark-gray);
        line-height: 1.6;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    
    .login-container {
        width: 100%;
        max-width: 450px;
        animation: fadeIn 0.5s ease-in-out;
    }
    
    .login-card {
        background: var(--surface-white);
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        overflow: hidden;
    }
    
    .login-header {
        padding: 40px 30px 30px;
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: white;
        text-align: center;
        position: relative;
    }
    
    .login-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
    }
    
    .login-header p {
        font-size: 16px;
        opacity: 0.9;
        margin-bottom: 20px;
    }
    
    .language-form-top {
        position: absolute;
        top: 20px;
        right: 20px;
    }
    
    .language-selector-top {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.2);
        padding: 8px 15px;
        border-radius: 25px;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    
    .language-dropdown-top {
        background: transparent;
        border: none;
        color: white;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        outline: none;
        min-width: 90px;
    }
    
    .language-dropdown-top option {
        background: var(--primary-green);
        color: white;
    }
    
    .login-form {
        padding: 40px 30px;
    }
    
    .form-group {
        margin-bottom: 25px;
        position: relative;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--primary-green);
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
    }
    
    .form-group input {
        width: 100%;
        padding: 15px 20px;
        border: 2px solid var(--border-color);
        border-radius: 10px;
        font-size: 16px;
        font-family: 'Poppins', sans-serif;
        transition: all 0.3s ease;
        background: var(--light-gray-bg);
    }
    
    .form-group input:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 3px rgba(0, 132, 61, 0.1);
        background: white;
    }
    
    .form-group input::placeholder {
        color: #999;
    }
    
    .toggle-password {
        position: absolute;
        right: 15px;
        top: 42px;
        background: none;
        border: none;
        color: var(--primary-green);
        cursor: pointer;
        font-size: 18px;
        padding: 5px;
    }
    
    .login-btn {
        width: 100%;
        padding: 17px;
        background: linear-gradient(135deg, var(--primary-green), var(--light-green));
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 18px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-top: 10px;
        box-shadow: 0 5px 15px rgba(0, 132, 61, 0.3);
    }
    
    .login-btn:hover {
        background: linear-gradient(135deg, var(--light-green), #00843D);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0, 132, 61, 0.4);
    }
    
    .login-btn:active {
        transform: translateY(-1px);
    }
    
    .login-footer {
        padding: 20px 30px;
        text-align: center;
        background: var(--light-gray-bg);
        border-top: 1px solid var(--border-color);
    }
    
    .login-footer p {
        font-size: 14px;
        color: #666;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    #errorContainer {
        margin-top: 20px;
        width: 100%;
        max-width: 450px;
    }
    
    .error-message {
        display: flex;
        align-items: center;
        background: #FFEBEE;
        color: #C62828;
        padding: 15px 20px;
        border-radius: 10px;
        border-left: 5px solid #C62828;
        box-shadow: 0 4px 12px rgba(198, 40, 40, 0.1);
    }
    
    .error-icon {
        margin-right: 15px;
        font-size: 20px;
    }
    
    .error-text {
        font-weight: 600;
        font-size: 15px;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @media (max-width: 480px) {
        .login-header {
            padding: 30px 20px 25px;
        }
        
        .login-header h1 {
            font-size: 24px;
            flex-direction: column;
            gap: 10px;
        }
        
        .login-form {
            padding: 30px 20px;
        }
        
        .form-group input {
            padding: 14px 18px;
            font-size: 15px;
        }
        
        .login-btn {
            padding: 16px;
            font-size: 16px;
        }
        
        .language-form-top {
            position: relative;
            top: 0;
            right: 0;
            margin-top: 15px;
        }
        
        .language-selector-top {
            justify-content: center;
            width: 100%;
        }
    }
    </style>
    </body>
    </html>";
}
?>