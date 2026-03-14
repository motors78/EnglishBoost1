<?php

require_once '../config.php';

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'register') {
    $data = getRequestData();
    
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($username) || empty($email) || empty($password)) {
        jsonResponse(['error' => 'Tutti i campi sono obbligatori'], 400);
    }
    
    if (strlen($username) < 3) {
        jsonResponse(['error' => 'Username deve essere almeno 3 caratteri'], 400);
    }
    
    if (!validateEmail($email)) {
        jsonResponse(['error' => 'Email non valida'], 400);
    }
    
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Password deve essere almeno 6 caratteri'], 400);
    }
    

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email o username già registrati'], 400);
    }
    

    $hashedPassword = hashPassword($password);
    $stmt = $db->prepare("
        INSERT INTO users (username, email, password, level, total_xp, current_xp, max_xp, streak_days, last_login)
        VALUES (?, ?, ?, 1, 0, 0, 300, 0, CURDATE())
    ");
    
    if ($stmt->execute([$username, $email, $hashedPassword])) {
        $userId = $db->lastInsertId();
        

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        
        $stmt = $db->prepare("SELECT id, username, email, level, total_xp, current_xp, max_xp, streak_days, avatar FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        jsonResponse([
            'success' => true,
            'message' => 'Registrazione completata!',
            'user' => $user
        ]);
    } else {
        jsonResponse(['error' => 'Errore durante la registrazione'], 500);
    }
}

if ($method === 'POST' && $action === 'login') {
    $data = getRequestData();
    
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        jsonResponse(['error' => 'Email e password obbligatori'], 400);
    }
    
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !verifyPassword($password, $user['password'])) {
        jsonResponse(['error' => 'Credenziali non valide'], 401);
    }
    

    $today = date('Y-m-d');
    $lastLogin = $user['last_login'];
    
    $newStreak = $user['streak_days'];
    if ($lastLogin !== $today) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($lastLogin === $yesterday) {
            $newStreak++;
        } elseif ($lastLogin < $yesterday) {
            $newStreak = 1;
        }
        

        $stmt = $db->prepare("UPDATE users SET last_login = ?, streak_days = ? WHERE id = ?");
        $stmt->execute([$today, $newStreak, $user['id']]);
    }
    
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    
    unset($user['password']);
    $user['streak_days'] = $newStreak;
    
    jsonResponse([
        'success' => true,
        'message' => 'Login effettuato!',
        'user' => $user
    ]);
}

if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    jsonResponse([
        'success' => true,
        'message' => 'Logout effettuato'
    ]);
}

if ($method === 'GET' && $action === 'check') {
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("SELECT id, username, email, level, total_xp, current_xp, max_xp, streak_days, avatar FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            jsonResponse([
                'authenticated' => true,
                'user' => $user
            ]);
        }
    }
    
    jsonResponse(['authenticated' => false]);
}

if ($method === 'GET' && $action === 'profile') {
    requireLogin();
    
    $userId = getCurrentUserId();
    
    $stmt = $db->prepare("SELECT id, username, email, level, total_xp, current_xp, max_xp, streak_days, avatar, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT lesson_id) as lessons_completed FROM lessons_completed WHERE user_id = ?");
    $stmt->execute([$userId]);
    $lessonsData = $stmt->fetch();
    
    $stmt = $db->prepare("SELECT COUNT(*) as words_learned FROM vocabulary_learned WHERE user_id = ?");
    $stmt->execute([$userId]);
    $vocabData = $stmt->fetch();
    
    $stmt = $db->prepare("SELECT COUNT(*) as verbs_practiced FROM verbs_practiced WHERE user_id = ?");
    $stmt->execute([$userId]);
    $verbsData = $stmt->fetch();
    
    $stmt = $db->prepare("
        SELECT a.*, ua.unlocked_at
        FROM achievements a
        JOIN user_achievements ua ON a.id = ua.achievement_id
        WHERE ua.user_id = ?
        ORDER BY ua.unlocked_at DESC
    ");
    $stmt->execute([$userId]);
    $achievements = $stmt->fetchAll();
    
    jsonResponse([
        'user' => $user,
        'stats' => [
            'lessons_completed' => $lessonsData['lessons_completed'],
            'words_learned' => $vocabData['words_learned'],
            'verbs_practiced' => $verbsData['verbs_practiced']
        ],
        'achievements' => $achievements
    ]);
}

if ($method === 'PUT' && $action === 'update-profile') {
    requireLogin();
    
    $userId = getCurrentUserId();
    $data = getRequestData();
    
    $avatar = $data['avatar'] ?? null;
    $username = trim($data['username'] ?? '');
    
    $updates = [];
    $params = [];
    
    if ($avatar) {
        $updates[] = "avatar = ?";
        $params[] = $avatar;
    }
    
    if ($username && strlen(string: $username) >= 3) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $userId]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Username già in uso'], 400);
        }
        $updates[] = "username = ?";
        $params[] = $username;
    }
    
    if (empty($updates)) {
        jsonResponse(['error' => 'Nessun dato da aggiornare'], 400);
    }
    
    $params[] = $userId;
    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    
    if ($stmt->execute($params)) {
        jsonResponse(['success' => true, 'message' => 'Profilo aggiornato']);
    } else {
        jsonResponse(['error' => 'Errore aggiornamento'], 500);
    }
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>