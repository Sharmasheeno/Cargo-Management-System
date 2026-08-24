<?php
/*******************************************************************************************
 * Cargo Management System — Maareynta Qaababka Fariimaha
 * Fayl: tenant_admin/template_message.php
 * Astaamaha:
 * - Maareynta qaababka fariimaha ee Tenant-ka
 * - Sameyn, wax ka bedel, tirtir, iyo daabacan
 * - Waxaa ka shaqeeya WhatsApp, SMS, Email, iyo nidaamka
 * - Firfircooni / Aan firfircoonayn (Toggle)
 * - Raadin, shaandhayn, faahfaahin, nuqul
 * - Sameynta miiska haddii uusan jirin
 * - DHAMMAAN Qaababka waxay ku qoran yihiin AF SOOMAALI
 *******************************************************************************************/

// Hadii aanay jirin session, samee mid cusub
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

//==============================================================
// XAKAMAYNTA GALITAANKA (ACCESS CONTROL)
//==============================================================
$allowed_roles = ['tenant_admin', 'company_admin', 'admin', 'superadmin', 'super_admin'];

// Hadii aanuu galin ama uusan saaxib ahayn, u dir login page-ga
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    header("Location: ../login.php");
    exit;
}

// Hel tenant_id-ga
$session_tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
if ($session_tenant_id <= 0 && !in_array($_SESSION['role'] ?? '', ['superadmin', 'super_admin'], true)) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

// Ku dar xiriiriyaha database-ka
require_once __DIR__ . '/../config/db_connect.php';

//==============================================================
// HAWLWADE CAWEEYA (HELPERS)
//==============================================================
/**
 * Badbaadi qoraal ka hor muujinta HTML-ga
 */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Jawaab JSON ah u dir browser-ka
 */
function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Hubi haddii miisku jiro database-ka
 */
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Hubi haddii tiirku jiro miiska
 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

//==============================================================
// SAMEYNTA MIIska QAABABKA FARIIMAHA
//==============================================================
function ensureTemplateMessagesSchema(PDO $pdo): void {
    // Samee miiska haddii uusan jirin
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS template_messages (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NULL,
            template_name VARCHAR(150) NOT NULL,
            template_key VARCHAR(150) NOT NULL,
            channel ENUM('whatsapp','sms','email','system') NOT NULL DEFAULT 'whatsapp',
            category VARCHAR(80) NOT NULL DEFAULT 'general',
            subject VARCHAR(190) NULL,
            message_body TEXT NOT NULL,
            variables TEXT NULL,
            language VARCHAR(20) NOT NULL DEFAULT 'so',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_template_tenant_key (tenant_id, template_key),
            KEY idx_template_tenant (tenant_id),
            KEY idx_template_channel (channel),
            KEY idx_template_category (category),
            KEY idx_template_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Tiirarka loo baahan yahay
    $required = [
        'tenant_id' => "ALTER TABLE template_messages ADD COLUMN tenant_id INT NULL AFTER id",
        'template_name' => "ALTER TABLE template_messages ADD COLUMN template_name VARCHAR(150) NOT NULL DEFAULT '' AFTER tenant_id",
        'template_key' => "ALTER TABLE template_messages ADD COLUMN template_key VARCHAR(150) NOT NULL DEFAULT '' AFTER template_name",
        'channel' => "ALTER TABLE template_messages ADD COLUMN channel ENUM('whatsapp','sms','email','system') NOT NULL DEFAULT 'whatsapp' AFTER template_key",
        'category' => "ALTER TABLE template_messages ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'general' AFTER channel",
        'subject' => "ALTER TABLE template_messages ADD COLUMN subject VARCHAR(190) NULL AFTER category",
        'message_body' => "ALTER TABLE template_messages ADD COLUMN message_body TEXT NOT NULL AFTER subject",
        'variables' => "ALTER TABLE template_messages ADD COLUMN variables TEXT NULL AFTER message_body",
        'language' => "ALTER TABLE template_messages ADD COLUMN language VARCHAR(20) NOT NULL DEFAULT 'so' AFTER variables",
        'is_default' => "ALTER TABLE template_messages ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER language",
        'is_active' => "ALTER TABLE template_messages ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default",
        'created_by' => "ALTER TABLE template_messages ADD COLUMN created_by INT NULL AFTER is_active",
        'updated_by' => "ALTER TABLE template_messages ADD COLUMN updated_by INT NULL AFTER created_by",
        'created_at' => "ALTER TABLE template_messages ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by",
        'updated_at' => "ALTER TABLE template_messages ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    // Ku dar tiirarka maqan
    foreach ($required as $column => $sql) {
        if (!columnExists($pdo, 'template_messages', $column)) {
            $pdo->exec($sql);
        }
    }
}

//==============================================================
// SAMAYNTA FURAHA QAABKA
//==============================================================
function makeTemplateKey(string $name): string {
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/i', '_', $key);
    $key = trim($key, '_');
    return $key ?: 'template_' . time();
}

//==============================================================
// BEDDELAYAASHA (VARIABLES) OO AF SOOMAALI AH
//==============================================================
function defaultVariables(): array {
    return [
        '{magaca_macmiil}',      // Magaca macmiilka
        '{magaca_shirkada}',     // Magaca shirkada
        '{telefonka}',           // Lambarka telefanka
        '{lambar_invoice}',      // Lambarka Invoice-ga
        '{lambar_risiit}',       // Lambarka Risiihta
        '{qadarka}',             // Qadarka lacagta
        '{qadarka_la_bixiyay}',  // Qadarka la bixiyay
        '{hadhay}',              // Hada hadhay
        '{deynta}',              // Deynta
        '{taariikhda_dhamaadka}',// Taariikhda dhamaadka
        '{lambar_raadraaca}',    // Lambarka raadraaca
        '{xaaladda_rarka}',      // Xaaladda rarka
        '{taariikhda}',          // Taariikhda
        '{linka_galitaanka}',    // Linka galitaanka
        '{cinwaanka_email}',     // Cinwaanka email-ka
        '{boostada}',            // Boostada
        '{qoraal_dheeri}'        // Qoraal dheeri ah
    ];
}

//==============================================================
// QAABABKA ASALKA AH (DEFAULT TEMPLATES) OO AF SOOMAALI AH
//==============================================================
function seedDefaultTemplates(PDO $pdo, int $tenant_id, int $user_id): void {
    // Hadii qaabab horay u jiraan, ha ku celin
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM template_messages WHERE tenant_id = ?");
    $countStmt->execute([$tenant_id]);
    if ((int)$countStmt->fetchColumn() > 0) {
        return;
    }

    // Dhammaan qaababka waa af Soomaali
    $defaults = [
        // 1. Soo Dhawoow Macmiil (Welcome Customer)
        [
            'Soo Dhawoow Macmiil',
            'welcome_customer_somali',
            'email',
            'customer',
            'Soo Dhawoow {magaca_macmiil} - {magaca_shirkada}',
            "Salaan {magaca_macmiil},\n\nKu soo dhowow {magaca_shirkada}! Koontadaada ayaa si guul leh loo sameeyay.\n\nLinka galitaanka: {linka_galitaanka}\n\nFadlan isbedel password-ka marka aad markiisa hore soo gasho.\n\nHaddii aad qabto wax su'aalo ah, nagala soo xiriir.\n\nMahadsanid,\n{magaca_shirkada}",
            '{magaca_macmiil},{magaca_shirkada},{linka_galitaanka}',
            'so'
        ],
        // 2. Xusuusinta Deynta (Debt Reminder)
        [
            'Xusuusinta Deynta',
            'debt_reminder_somali',
            'whatsapp',
            'debt',
            '📢 XUSUUSIN DEGDEG AH - Deyntaada',
            "📢 XUSUUSIN DEGDEG AH\n\nWaa kumuu qaaliga {magaca_macmiil},\n\nWaxaad {magaca_shirkada} kuleedahay deyn dhan {deynta}.\n\nFadlan ku bixi deyntaada kahor {taariikhda_dhamaadka}.\n\nHaddii aad horey u bixisay fadlan iska indha tir.\n\nMahadsanid!\n{magaca_shirkada}",
            '{magaca_macmiil},{magaca_shirkada},{deynta},{taariikhda_dhamaadka}',
            'so'
        ],
        // 3. Ogeysiiska Invoiska (Invoice Notification)
        [
            'Ogeysiiska Invoiska',
            'invoice_notification_somali',
            'whatsapp',
            'invoice',
            'OGEYSIIS: Invoice cusub',
            "Waa kumuu {magaca_macmiil},\n\nInvoice #{lambar_invoice} ayaa loo sameeyay.\nQadarka guud: {qadarka}\nTaariikhda: {taariikhda}\n\nFadlan ku bixi kahor {taariikhda_dhamaadka}.\n\nMahadsanid!\n{magaca_shirkada}",
            '{magaca_macmiil},{lambar_invoice},{qadarka},{taariikhda},{taariikhda_dhamaadka},{magaca_shirkada}',
            'so'
        ],
        // 4. Xaqiijinta Risiihta (Receipt Confirmation)
        [
            'Xaqiijinta Risiihta',
            'receipt_confirmation_somali',
            'sms',
            'receipt',
            'XAQIIJI: Lacagtaa waa la helay',
            "Waad ku mahadsantahay {magaca_macmiil},\n\nWaxaan ku helnay lacag dhan {qadarka_la_bixiyay}. Risiihtu waa #{lambar_risiit}. Hada hadhay {hadhay}.\n\n{magaca_shirkada}",
            '{magaca_macmiil},{qadarka_la_bixiyay},{lambar_risiit},{hadhay},{magaca_shirkada}',
            'so'
        ],
        // 5. Cusboonaynta Rarka (Shipment Update)
        [
            'Cusboonaynta Rarka',
            'shipment_update_somali',
            'whatsapp',
            'shipment',
            'CUSBOONAYN: Rarkaaga',
            "Waa kumuu {magaca_macmiil},\n\nRarkaaga lambarkiisu yahay {lambar_raadraaca} hadda wuxuu ku jiraa xaaladda: {xaaladda_rarka}.\n\nWaxaan kugula soo socodsiin doonaa marka uu yimaado.\n\n{magaca_shirkada}",
            '{magaca_macmiil},{lambar_raadraaca},{xaaladda_rarka},{magaca_shirkada}',
            'so'
        ],
        // 6. Xusuusinta Lacag Bixinta (Payment Reminder)
        [
            'Xusuusinta Lacag Bixinta',
            'payment_reminder_somali',
            'whatsapp',
            'payment',
            'XUSUUSIN: Lacag bixin',
            "📌 XUSUUSIN LACAG BIXIN\n\n{magaca_macmiil} waan kugu soo jeedinaynaa inaad bixiso lacagta kuugu haysa {magaca_shirkada} oo ah {deynta} kahor {taariikhda_dhamaadka}.\n\nFadlan nagala soo xiriir haddii aad wax su'aalo ah qabtid.\n\nMahadsanid!",
            '{magaca_macmiil},{magaca_shirkada},{deynta},{taariikhda_dhamaadka}',
            'so'
        ],
        // 7. Ciwaanka Galitaanka (Login Credentials)
        [
            'Ciwaanka Galitaanka',
            'login_credentials_somali',
            'email',
            'login',
            'Ciwaanka Galitaankaaga - {magaca_shirkada}',
            "Salaan {magaca_macmiil},\n\nCiwaanka galitaankaaga koontadaada {magaca_shirkada} ayaa la sameeyay.\n\nEmail-ka: {cinwaanka_email}\n\nLinka galitaanka: {linka_galitaanka}\n\nFadlan isbedel ciwaanka sirtaada marka aad soo gasho.\n\nMahadsanid!",
            '{magaca_macmiil},{magaca_shirkada},{cinwaanka_email},{linka_galitaanka}',
            'so'
        ],
        // 8. Xog Ogeysiis Guud (General Notification)
        [
            'Xog Ogeysiis Guud',
            'general_notification_somali',
            'sms',
            'general',
            'OGAYSII: Xog Muhiim ah',
            "Waa kumuu {magaca_macmiil},\n\nXog muhiim ah ayaa laga helay {magaca_shirkada}. Fadlan nagala soo xiriir faahfaahin dheeri ah.\n\nMahadsanid!",
            '{magaca_macmiil},{magaca_shirkada}',
            'so'
        ],
        // 9. Taariikhda Dhamaadka Invoiska (Invoice Due Date Reminder)
        [
            'Taariikhda Dhamaadka Invoiska',
            'invoice_due_reminder_somali',
            'sms',
            'invoice',
            'XUSUUSIN: Invoice waa dhacayaa',
            "Waa kumuu {magaca_macmiil},\n\nInvoice #{lambar_invoice} oo ah {qadarka} waxaa kuugu dhowanaysa taariikhda dhamaadka ({taariikhda_dhamaadka}). Fadlan ku bixi waqtiga si aad uga fogaato ganaax.\n\n{magaca_shirkada}",
            '{magaca_macmiil},{lambar_invoice},{qadarka},{taariikhda_dhamaadka},{magaca_shirkada}',
            'so'
        ],
        // 10. Shakiga Bixinta (Payment Receipt)
        [
            'Shakiga Bixinta',
            'payment_receipt_somali',
            'email',
            'receipt',
            'Shakiga Bixinta Lacagta',
            "Waad ku mahadsantahay {magaca_macmiil},\n\nWaxaan xaqiijinaynaa inaad bixisay {qadarka_la_bixiyay}.\n\nRisiihtu waa #{lambar_risiit}.\n\nHada hadhay {hadhay}.\n\nWaxaa lagugu mahadcelinayaa bixintaada waqtiga ku habboon.\n\n{magaca_shirkada}",
            '{magaca_macmiil},{qadarka_la_bixiyay},{lambar_risiit},{hadhay},{magaca_shirkada}',
            'so'
        ],
        // 11. Xaqiijinta Diyaarinta Alabka (Stock Ready Confirmation)
        [
            'Xaqiijinta Diyaarinta Alabka',
            'stock_ready_somali',
            'whatsapp',
            'general',
            'Alabkaaga waa diyaar',
            "Waa kumuu {magaca_macmiil},\n\nAlabka aad dalbatay oo ah {qoraal_dheeri} ayaa hadda diyaar u ah inaad soo qaadato.\n\nFadlan nagala soo xiriir si aad u soo qaadato.\n\nMahadsanid!\n{magaca_shirkada}",
            '{magaca_macmiil},{magaca_shirkada},{qoraal_dheeri}',
            'so'
        ],
        // 12. Booska Cusboonaynta (Order Update)
        [
            'Booska Cusboonaynta',
            'order_update_somali',
            'sms',
            'shipment',
            'CUSBOONAYN: Booskaada',
            "{magaca_macmiil},\n\nBooskaada #{lambar_raadraaca} hadda wuxuu yahay: {xaaladda_rarka}.\n\nWaxaan kugu soo wargelin doonaa mar kasta oo uu isbeddelo.\n\n{magaca_shirkada}",
            '{magaca_macmiil},{lambar_raadraaca},{xaaladda_rarka},{magaca_shirkada}',
            'so'
        ],
    ];

    // Ku dar qaababka database-ka
    $stmt = $pdo->prepare("
        INSERT INTO template_messages
        (tenant_id, template_name, template_key, channel, category, subject, message_body, variables, language, is_default, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)
    ");

    foreach ($defaults as $tpl) {
        $stmt->execute([
            $tenant_id,
            $tpl[0],
            $tpl[1],
            $tpl[2],
            $tpl[3],
            $tpl[4],
            $tpl[5],
            $tpl[6],
            $tpl[7],
            $user_id
        ]);
    }
}

// Samee miiska iyo qaababka asalka ah
ensureTemplateMessagesSchema($pdo);
seedDefaultTemplates($pdo, $session_tenant_id, (int)($_SESSION['user_id'] ?? 0));

//==============================================================
// MACLUMAADKA SHIRKADA
//==============================================================
$tenant_name = 'Shirkadeyda';
try {
    if ($session_tenant_id > 0 && tableExists($pdo, 'tenants')) {
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$session_tenant_id]);
        $tenant_name = $stmt->fetchColumn() ?: 'Shirkadeyda';
    }
} catch (Exception $e) {
    $tenant_name = 'Shirkadeyda';
}

//==============================================================
// AJAX FICILLADA
//==============================================================
$action = $_REQUEST['ajax_action'] ?? '';

if ($action !== '') {
    try {
        //======================================================
        // Liiska Qaababka (LIST)
        //======================================================
        if ($action === 'list') {
            $search = trim($_POST['search'] ?? '');
            $channel = trim($_POST['channel'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $status = trim($_POST['status'] ?? '');

            $where = ["tenant_id = ?"];
            $params = [$session_tenant_id];

            if ($search !== '') {
                $where[] = "(template_name LIKE ? OR template_key LIKE ? OR subject LIKE ? OR message_body LIKE ?)";
                $like = "%{$search}%";
                array_push($params, $like, $like, $like, $like);
            }

            if ($channel !== '') {
                $where[] = "channel = ?";
                $params[] = $channel;
            }

            if ($category !== '') {
                $where[] = "category = ?";
                $params[] = $category;
            }

            if ($status === 'active') {
                $where[] = "is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "is_active = 0";
            }

            $whereSql = implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT *
                FROM template_messages
                WHERE {$whereSql}
                ORDER BY is_default DESC, created_at DESC, id DESC
            ");
            $stmt->execute($params);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            if (!$templates): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-message"></i>
                    <h5>Ma jiraan wax qaab fariin ah</h5>
                    <p>Samee qaabkaaga fariinta koowaad.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table template-table">
                        <thead>
                            <tr>
                                <th>Magaca Qaabka</th>
                                <th>Kanaalka</th>
                                <th>Qeybta</th>
                                <th>Luqadda</th>
                                <th>Xaaladda</th>
                                <th>La cusboonaystay</th>
                                <th class="text-right">Ficillo</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($templates as $row): ?>
                            <tr>
                                <td>
                                    <div class="tpl-title"><?= e($row['template_name']) ?></div>
                                    <div class="tpl-key"><?= e($row['template_key']) ?></div>
                                    <?php if ((int)$row['is_default'] === 1): ?>
                                        <span class="badge badge-soft">Asalka</span>
                                    <?php endif; ?>
                                 </div>
                                <td><span class="channel-pill channel-<?= e($row['channel']) ?>"><?= e(strtoupper($row['channel'])) ?></span></div>
                                <td><?= e(ucwords(str_replace('_', ' ', $row['category']))) ?></div>
                                <td><?= e(strtoupper($row['language'])) ?></div>
                                <td>
                                    <?php if ((int)$row['is_active'] === 1): ?>
                                        <span class="badge badge-success">Firfircoon</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Aan firfircoonayn</span>
                                    <?php endif; ?>
                                 </div>
                                <td><?= e($row['updated_at'] ?: $row['created_at']) ?></div>
                                <td class="text-right">
                                    <button class="btn btn-sm btn-outline-info preview-btn" data-id="<?= (int)$row['id'] ?>" title="Faahfaahin">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary edit-btn" data-id="<?= (int)$row['id'] ?>" title="Wax ka bedel">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning toggle-btn" data-id="<?= (int)$row['id'] ?>" title="Bedel xaaladda">
                                        <i class="fa-solid fa-power-off"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?= (int)$row['id'] ?>" title="Tirtir">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                 </div>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif;
            jsonResponse(['success' => true, 'html' => ob_get_clean(), 'count' => count($templates)]);
        }

        //======================================================
        // Hel Qaabka (GET)
        //======================================================
        if ($action === 'get') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM template_messages WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$template) {
                jsonResponse(['success' => false, 'message' => 'Qaabka lama helin.']);
            }

            jsonResponse(['success' => true, 'data' => $template]);
        }

        //======================================================
        // Keydi / Wax ka bedel Qaabka (SAVE/UPDATE)
        //======================================================
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $template_name = trim($_POST['template_name'] ?? '');
            $template_key = trim($_POST['template_key'] ?? '');
            $channel = trim($_POST['channel'] ?? 'whatsapp');
            $category = trim($_POST['category'] ?? 'general');
            $subject = trim($_POST['subject'] ?? '');
            $message_body = trim($_POST['message_body'] ?? '');
            $variables = trim($_POST['variables'] ?? '');
            $language = trim($_POST['language'] ?? 'so');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($template_name === '') {
                jsonResponse(['success' => false, 'message' => 'Magaca qaabka waa lama huraan.']);
            }

            if ($message_body === '') {
                jsonResponse(['success' => false, 'message' => 'Qoraalka fariinta waa lama huraan.']);
            }

            if ($template_key === '') {
                $template_key = makeTemplateKey($template_name);
            } else {
                $template_key = makeTemplateKey($template_key);
            }

            if (!in_array($channel, ['whatsapp', 'sms', 'email', 'system'], true)) {
                $channel = 'whatsapp';
            }

            if ($id > 0) {
                // Wax ka bedel qaab jira
                $check = $pdo->prepare("SELECT id FROM template_messages WHERE template_key = ? AND tenant_id = ? AND id != ?");
                $check->execute([$template_key, $session_tenant_id, $id]);
                if ($check->fetch()) {
                    jsonResponse(['success' => false, 'message' => 'Furaha qaabkan ayaa horay u jiray.']);
                }

                $stmt = $pdo->prepare("
                    UPDATE template_messages
                    SET template_name = ?,
                        template_key = ?,
                        channel = ?,
                        category = ?,
                        subject = ?,
                        message_body = ?,
                        variables = ?,
                        language = ?,
                        is_active = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([
                    $template_name,
                    $template_key,
                    $channel,
                    $category,
                    $subject,
                    $message_body,
                    $variables,
                    $language,
                    $is_active,
                    (int)($_SESSION['user_id'] ?? 0),
                    $id,
                    $session_tenant_id
                ]);

                jsonResponse(['success' => true, 'message' => 'Qaabka si guul leh ayaa loo cusboonaystay.']);
            } else {
                // Samee qaab cusub
                $check = $pdo->prepare("SELECT id FROM template_messages WHERE template_key = ? AND tenant_id = ?");
                $check->execute([$template_key, $session_tenant_id]);
                if ($check->fetch()) {
                    jsonResponse(['success' => false, 'message' => 'Furaha qaabkan ayaa horay u jiray.']);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO template_messages
                    (tenant_id, template_name, template_key, channel, category, subject, message_body, variables, language, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $session_tenant_id,
                    $template_name,
                    $template_key,
                    $channel,
                    $category,
                    $subject,
                    $message_body,
                    $variables,
                    $language,
                    $is_active,
                    (int)($_SESSION['user_id'] ?? 0)
                ]);

                jsonResponse(['success' => true, 'message' => 'Qaabka si guul leh ayaa loo sameeyay.']);
            }
        }

        //======================================================
        // Tirtir Qaabka (DELETE)
        //======================================================
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM template_messages WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);

            jsonResponse(['success' => true, 'message' => 'Qaabka si guul leh ayaa loo tirtiray.']);
        }

        //======================================================
        // Bedel Xaaladda (TOGGLE - Firfircoon/Aan firfircoonayn)
        //======================================================
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE template_messages
                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([(int)($_SESSION['user_id'] ?? 0), $id, $session_tenant_id]);

            jsonResponse(['success' => true, 'message' => 'Xaaladda qaabka waa la bedelay.']);
        }

        //======================================================
        // Tirakoobka Qaababka (STATS)
        //======================================================
        if ($action === 'stats') {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_templates,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_templates,
                    SUM(CASE WHEN channel = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapp_templates,
                    SUM(CASE WHEN channel = 'sms' THEN 1 ELSE 0 END) AS sms_templates,
                    SUM(CASE WHEN channel = 'email' THEN 1 ELSE 0 END) AS email_templates
                FROM template_messages
                WHERE tenant_id = ?
            ");
            $stmt->execute([$session_tenant_id]);
            jsonResponse(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        }

        // Ficil aan la aqoonsan
        jsonResponse(['success' => false, 'message' => 'Ficil aan sax ahayn.']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Ku dar header-ka page-ga
require_once __DIR__ . '/../includes/header.php';
$variables = defaultVariables();
?>
<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <title>Qaababka Fariimaha - <?= e($tenant_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"/>

    <style>
        :root {
            --brand: #2D1859;
            --brand-2: #4B2C85;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #f59e0b;
            --info: #0891b2;
            --muted: #64748b;
            --soft: #f8fafc;
            --border: #e5e7eb;
        }

        body {
            background: #f4f6f9;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .tm-wrapper {
            padding: 0 24px 30px;
        }

        .page-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 20px 24px;
            margin: 0 -24px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: #111827;
        }

        .page-header h1 i {
            color: var(--brand);
            margin-right: 8px;
        }

        .btn-brand {
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            padding: 9px 16px;
        }

        .btn-brand:hover {
            color: #fff;
            opacity: .95;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .06);
            border: 1px solid #edf0f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-card h6 {
            margin: 0 0 4px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 800;
        }

        .stat-card .number {
            font-size: 26px;
            font-weight: 900;
            color: #111827;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand);
            background: rgba(82,0,102,.09);
            font-size: 20px;
        }

        .filter-card,
        .table-card {
            background: #fff;
            border: 1px solid #edf0f5;
            border-radius: 16px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, .05);
            margin-bottom: 20px;
        }

        .filter-card {
            padding: 18px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .form-label-small {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .form-control,
        .custom-select {
            border-radius: 10px;
            border-color: #d9dee8;
            min-height: 42px;
        }

        .table-card {
            overflow: hidden;
        }

        .table-card-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .template-table {
            margin-bottom: 0;
        }

        .template-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            border-top: 0;
            font-weight: 900;
        }

        .template-table td {
            vertical-align: middle;
        }

        .tpl-title {
            font-weight: 800;
            color: #111827;
        }

        .tpl-key {
            color: var(--muted);
            font-size: 12px;
            font-family: Consolas, monospace;
        }

        .badge-soft {
            background: rgba(82,0,102,.10);
            color: var(--brand);
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 10px;
        }

        .channel-pill {
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
        }

        .channel-whatsapp { background: #dcfce7; color: #15803d; }
        .channel-sms { background: #e0f2fe; color: #0369a1; }
        .channel-email { background: #fef3c7; color: #92400e; }
        .channel-system { background: #ede9fe; color: #5b21b6; }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 14px;
        }

        .variables-box {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 10px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
        }

        .variable-chip {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: var(--brand);
            border-radius: 999px;
            padding: 5px 9px;
            font-family: Consolas, monospace;
            font-size: 12px;
            cursor: pointer;
        }

        .preview-box {
            white-space: pre-wrap;
            min-height: 170px;
            padding: 16px;
            border-radius: 12px;
            background: #0f172a;
            color: #e5e7eb;
            font-family: Consolas, monospace;
        }

        .modal-content {
            border-radius: 16px;
            border: 0;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            color: #fff;
        }

        .modal-header .close {
            color: #fff;
            opacity: 1;
        }

        .alert-float {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 310px;
            border-radius: 12px;
        }

        @media (max-width: 992px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 576px) {
            .tm-wrapper {
                padding: 0 12px 25px;
            }
            .page-header {
                margin: 0 -12px 18px;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="tm-wrapper">
    <div id="alertBox"></div>

    <div class="page-header">
        <div>
            <h1><i class="fa-solid fa-comments"></i> Qaababka Fariimaha</h1>
            <div class="text-muted small">Maamul qaababka WhatsApp, SMS, Email, iyo fariimaha nidaamka.</div>
        </div>
        <button class="btn btn-brand" id="addTemplateBtn">
            <i class="fa-solid fa-plus"></i> Qaab Cusub
        </button>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div><h6>Wadar Qaababka</h6><div class="number" id="statTotal">0</div></div>
            <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
        </div>
        <div class="stat-card">
            <div><h6>Kuwa Firfircoon</h6><div class="number" id="statActive">0</div></div>
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="stat-card">
            <div><h6>WhatsApp</h6><div class="number" id="statWhatsapp">0</div></div>
            <div class="stat-icon"><i class="fa-brands fa-whatsapp"></i></div>
        </div>
        <div class="stat-card">
            <div><h6>SMS / Email</h6><div class="number" id="statSmsEmail">0</div></div>
            <div class="stat-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
        </div>
    </div>

    <div class="filter-card">
        <div class="filter-grid">
            <div>
                <label class="form-label-small">Raadin</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Raadi magaca qaabka, furaha, cinwaanka, fariinta...">
            </div>
            <div>
                <label class="form-label-small">Kanaalka</label>
                <select class="custom-select" id="channelFilter">
                    <option value="">Dhammaan Kanaalada</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                    <option value="email">Email</option>
                    <option value="system">Nidaamka</option>
                </select>
            </div>
            <div>
                <label class="form-label-small">Qeybta</label>
                <select class="custom-select" id="categoryFilter">
                    <option value="">Dhammaan Qeybaha</option>
                    <option value="general">Guud</option>
                    <option value="customer">Macmiil</option>
                    <option value="invoice">Invoice</option>
                    <option value="receipt">Risiit</option>
                    <option value="debt">Deyn</option>
                    <option value="shipment">Rar</option>
                    <option value="payment">Lacag bixin</option>
                    <option value="login">Galitaan</option>
                </select>
            </div>
            <div>
                <label class="form-label-small">Xaaladda</label>
                <select class="custom-select" id="statusFilter">
                    <option value="">Dhammaan Xaaladaha</option>
                    <option value="active">Kuwa Firfircoon</option>
                    <option value="inactive">Kuwa Aan Firfircoonayn</option>
                </select>
            </div>
            <div>
                <button class="btn btn-brand btn-block" id="filterBtn">
                    <i class="fa-solid fa-filter"></i> Shaandhayn
                </button>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card-header">
            <strong>Liiska Qaababka</strong>
            <button class="btn btn-sm btn-outline-secondary" id="refreshBtn">
                <i class="fa-solid fa-rotate"></i> Cusboonaysii
            </button>
        </div>
        <div id="templatesContainer">
            <div class="empty-state">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <h5>Qaababka waa la soo shaqeynayaa...</h5>
            </div>
        </div>
    </div>
</div>

<!-- Qaabka Qaab Cusub / Wax ka Bedel (Modal) -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form class="modal-content" id="templateForm">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Qaab Cusub</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="id" id="templateId">

                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label-small">Magaca Qaabka *</label>
                        <input type="text" class="form-control" name="template_name" id="templateName" required placeholder="Tusaale: Soo Dhawoow Macmiil">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-small">Furaha Qaabka</label>
                        <input type="text" class="form-control" name="template_key" id="templateKey" placeholder="Iskeed ayaa u samaysan haddii madhan">
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-3">
                        <label class="form-label-small">Kanaalka *</label>
                        <select class="custom-select" name="channel" id="templateChannel" required>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                            <option value="system">Nidaamka</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-small">Qeybta *</label>
                        <select class="custom-select" name="category" id="templateCategory" required>
                            <option value="general">Guud</option>
                            <option value="customer">Macmiil</option>
                            <option value="invoice">Invoice</option>
                            <option value="receipt">Risiit</option>
                            <option value="debt">Deyn</option>
                            <option value="shipment">Rar</option>
                            <option value="payment">Lacag bixin</option>
                            <option value="login">Galitaan</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-small">Luqadda</label>
                        <select class="custom-select" name="language" id="templateLanguage">
                            <option value="so">Soomaali</option>
                            <option value="en">Ingiriisi</option>
                            <option value="ar">Carabi</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-small">Xaaladda</label>
                        <div class="custom-control custom-switch mt-2">
                            <input type="checkbox" class="custom-control-input" id="templateActive" name="is_active" checked>
                            <label class="custom-control-label" for="templateActive">Firfircoon</label>
                        </div>
                    </div>
                </div>

                <div class="mt-3" id="subjectGroup">
                    <label class="form-label-small">Cinwaanka / Qeybta</label>
                    <input type="text" class="form-control" name="subject" id="templateSubject" placeholder="Wax ku ool ah qaababka email-ka">
                </div>

                <div class="mt-3">
                    <label class="form-label-small">Qoraalka Fariinta *</label>
                    <textarea class="form-control" name="message_body" id="templateBody" rows="8" required placeholder="Qor fariintaada halkan..."></textarea>
                </div>

                <div class="mt-3">
                    <label class="form-label-small">Beddelayaasha la heli karo — guji si aad u geliso</label>
                    <div class="variables-box">
                        <?php foreach ($variables as $var): ?>
                            <span class="variable-chip" data-var="<?= e($var) ?>"><?= e($var) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label-small">Beddelayaasha La Isticmaalay</label>
                    <input type="text" class="form-control" name="variables" id="templateVariables" placeholder="{magaca_macmiil},{qadarka},{taariikhda}">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Joogi</button>
                <button type="submit" class="btn btn-brand" id="saveBtn">
                    <i class="fa-solid fa-save"></i> Keydi Qaabka
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Qaabka Faahfaahinta (Preview Modal) -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Faahfaahinta Qaabka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <div class="modal-body">
                <div class="mb-2"><strong id="previewName"></strong></div>
                <div class="mb-2 text-muted" id="previewMeta"></div>
                <div class="mb-3" id="previewSubjectWrap" style="display:none;">
                    <strong>Cinwaanka:</strong> <span id="previewSubject"></span>
                </div>
                <div class="preview-box" id="previewBody"></div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-outline-primary" id="copyPreviewBtn">
                    <i class="fa-solid fa-copy"></i> Nuqul Fariinta
                </button>
                <button class="btn btn-secondary" data-dismiss="modal">Xidh</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>

<script>
$(function () {
    let lastPreviewText = '';

    // Muuji digniin (Alert)
    function showAlert(type, message) {
        const cls = type === 'success' ? 'alert-success' : 'alert-danger';
        $('#alertBox').html(`
            <div class="alert ${cls} alert-float alert-dismissible fade show">
                ${message}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `);
        setTimeout(() => $('.alert-float').fadeOut(300), 3500);
    }

    // Nadiifi foomka qaabka
    function resetForm() {
        $('#templateForm')[0].reset();
        $('#templateId').val('');
        $('#templateActive').prop('checked', true);
        $('#modalTitle').text('Qaab Cusub');
    }

    // Soo rar tirakoobka (Stats)
    function loadStats() {
        $.post(window.location.href, { ajax_action: 'stats' }, function (res) {
            if (res.success) {
                const d = res.data || {};
                $('#statTotal').text(d.total_templates || 0);
                $('#statActive').text(d.active_templates || 0);
                $('#statWhatsapp').text(d.whatsapp_templates || 0);
                $('#statSmsEmail').text((parseInt(d.sms_templates || 0) + parseInt(d.email_templates || 0)));
            }
        }, 'json');
    }

    // Soo rar liiska qaababka
    function loadTemplates() {
        $('#templatesContainer').html(`
            <div class="empty-state">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <h5>Qaababka waa la soo shaqeynayaa...</h5>
            </div>
        `);

        $.post(window.location.href, {
            ajax_action: 'list',
            search: $('#searchInput').val(),
            channel: $('#channelFilter').val(),
            category: $('#categoryFilter').val(),
            status: $('#statusFilter').val()
        }, function (res) {
            if (res.success) {
                $('#templatesContainer').html(res.html);
            } else {
                $('#templatesContainer').html('<div class="empty-state text-danger">' + res.message + '</div>');
            }
        }, 'json').fail(function () {
            $('#templatesContainer').html('<div class="empty-state text-danger">Qaababka waa la soo shaqeyn waayay.</div>');
        });
    }

    // Geliso qoraal meesha cursor-ku joogo
    function insertAtCursor(textarea, text) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        textarea.value = value.substring(0, start) + text + value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        textarea.focus();
    }

    // Otomatik u aqoonso beddelayaasha (variables)
    function autoDetectVariables() {
        const body = $('#templateBody').val();
        const subject = $('#templateSubject').val();
        const all = (subject + ' ' + body).match(/\{[a-zA-Z0-9_]+\}/g) || [];
        const unique = [...new Set(all)];
        $('#templateVariables').val(unique.join(','));
    }

    // Badhan "Qaab Cusub"
    $('#addTemplateBtn').on('click', function () {
        resetForm();
        $('#templateModal').modal('show');
    });

    // Badhannada Shaandhayn iyo Cusboonaysii
    $('#filterBtn, #refreshBtn').on('click', function () {
        loadTemplates();
        loadStats();
    });

    // Raadinta marka la riixo Enter
    $('#searchInput').on('keyup', function (e) {
        if (e.key === 'Enter') loadTemplates();
    });

    // Guji beddelaha si aad u geliso qoraalka
    $(document).on('click', '.variable-chip', function () {
        insertAtCursor(document.getElementById('templateBody'), $(this).data('var'));
        autoDetectVariables();
    });

    // Otomatik u samee furaha qaabka
    $('#templateName').on('keyup change', function () {
        if (!$('#templateId').val() && !$('#templateKey').val()) {
            let key = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            $('#templateKey').val(key);
        }
    });

    // Otomatik u aqoonso beddelayaasha marka la qoro fariinta
    $('#templateBody, #templateSubject').on('keyup change', autoDetectVariables);

    // Dhiibo foomka (Save)
    $('#templateForm').on('submit', function (e) {
        e.preventDefault();

        const btn = $('#saveBtn');
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Keydinaysa...');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=save',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    $('#templateModal').modal('hide');
                    showAlert('success', res.message);
                    loadTemplates();
                    loadStats();
                } else {
                    showAlert('error', res.message);
                }
                btn.prop('disabled', false).html('<i class="fa-solid fa-save"></i> Keydi Qaabka');
            },
            error: function () {
                showAlert('error', 'Server error while saving template.');
                btn.prop('disabled', false).html('<i class="fa-solid fa-save"></i> Keydi Qaabka');
            }
        });
    });

    // Wax ka bedel (Edit) qaabka
    $(document).on('click', '.edit-btn', function () {
        const id = $(this).data('id');

        $.post(window.location.href, { ajax_action: 'get', id: id }, function (res) {
            if (!res.success) {
                showAlert('error', res.message);
                return;
            }

            const d = res.data;
            $('#modalTitle').text('Wax ka bedel Qaabka');
            $('#templateId').val(d.id);
            $('#templateName').val(d.template_name);
            $('#templateKey').val(d.template_key);
            $('#templateChannel').val(d.channel);
            $('#templateCategory').val(d.category);
            $('#templateSubject').val(d.subject);
            $('#templateBody').val(d.message_body);
            $('#templateVariables').val(d.variables);
            $('#templateLanguage').val(d.language || 'so');
            $('#templateActive').prop('checked', parseInt(d.is_active) === 1);
            $('#templateModal').modal('show');
        }, 'json');
    });

    // Faahfaahin (Preview) qaabka
    $(document).on('click', '.preview-btn', function () {
        const id = $(this).data('id');

        $.post(window.location.href, { ajax_action: 'get', id: id }, function (res) {
            if (!res.success) {
                showAlert('error', res.message);
                return;
            }

            const d = res.data;
            lastPreviewText = d.message_body || '';

            $('#previewName').text(d.template_name);
            $('#previewMeta').text((d.channel || '').toUpperCase() + ' • ' + (d.category || 'general') + ' • ' + (d.language || 'so').toUpperCase());

            if (d.subject) {
                $('#previewSubjectWrap').show();
                $('#previewSubject').text(d.subject);
            } else {
                $('#previewSubjectWrap').hide();
                $('#previewSubject').text('');
            }

            $('#previewBody').text(lastPreviewText);
            $('#previewModal').modal('show');
        }, 'json');
    });

    // Tirtir qaabka
    $(document).on('click', '.delete-btn', function () {
        const id = $(this).data('id');

        if (!confirm('Ma hubtaa inaad tirtirto qaabkan? Falkaan dib looma noqon karo.')) return;

        $.post(window.location.href, { ajax_action: 'delete', id: id }, function (res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if (res.success) {
                loadTemplates();
                loadStats();
            }
        }, 'json');
    });

    // Bedel xaaladda (Firfircoon / Aan firfircoonayn)
    $(document).on('click', '.toggle-btn', function () {
        const id = $(this).data('id');

        $.post(window.location.href, { ajax_action: 'toggle', id: id }, function (res) {
            showAlert(res.success ? 'success' : 'error', res.message);
            if (res.success) {
                loadTemplates();
                loadStats();
            }
        }, 'json');
    });

    // Nuqul fariinta (Copy)
    $('#copyPreviewBtn').on('click', function () {
        if (!lastPreviewText) return;

        navigator.clipboard.writeText(lastPreviewText).then(function () {
            showAlert('success', 'Fariinta waa la nuqulay.');
        }).catch(function () {
            showAlert('error', 'Nuqulayntu way fashilantay.');
        });
    });

    // Bilow: soo rar qaababka iyo tirakoobka
    loadTemplates();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>