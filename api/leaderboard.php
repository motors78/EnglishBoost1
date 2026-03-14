<?php
require_once '../config.php';
$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET classifica globale
if ($method === 'GET' && $action === 'global') {
    $limit = (int)($_GET['limit'] ?? 100);
    $limit = min($limit, 100); // Max 100
    
    $stmt = $db->prepare("
        SELECT 
            id,
            username,
            avatar,
            level,
            total_xp,
            streak_days,
            (SELECT COUNT(DISTINCT lesson_id) FROM lessons_completed WHERE user_id = users.id) as lessons_completed
        FROM users
        ORDER BY total_xp DESC, level DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $leaderboard = $stmt->fetchAll();
    
    // Aggiungi rank
    foreach ($leaderboard as $index => &$user) {
        $user['rank'] = $index + 1;
    }
    
    jsonResponse(['leaderboard' => $leaderboard]);
}

// GET posizione utente
if ($method === 'GET' && $action === 'my-rank') {
    requireLogin();
    $userId = getCurrentUserId();
    
    // Trova posizione
    $stmt = $db->query("
        SELECT id, total_xp FROM users ORDER BY total_xp DESC, level DESC
    ");
    $users = $stmt->fetchAll();
    
    $myRank = 0;
    foreach ($users as $index => $user) {
        if ($user['id'] == $userId) {
            $myRank = $index + 1;
            break;
        }
    }
    
    // Dati utente
    $stmt = $db->prepare("
        SELECT 
            id, username, avatar, level, total_xp, streak_days,
            (SELECT COUNT(DISTINCT lesson_id) FROM lessons_completed WHERE user_id = ?) as lessons_completed
        FROM users WHERE id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $myData = $stmt->fetch();
    $myData['rank'] = $myRank;
    
    jsonResponse(['my_rank' => $myData, 'total_users' => count($users)]);
}

// GET classifica settimanale
if ($method === 'GET' && $action === 'weekly') {
    $stmt = $db->prepare("
        SELECT 
            u.id, u.username, u.avatar, u.level, u.streak_days,
            SUM(xh.xp_amount) as weekly_xp
        FROM users u
        LEFT JOIN xp_history xh ON u.id = xh.user_id 
            AND xh.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY u.id
        ORDER BY weekly_xp DESC
        LIMIT 50
    ");
    $stmt->execute();
    $weekly = $stmt->fetchAll();
    
    foreach ($weekly as $index => &$user) {
        $user['rank'] = $index + 1;
        $user['weekly_xp'] = $user['weekly_xp'] ?? 0;
    }
    
    jsonResponse(['weekly_leaderboard' => $weekly]);
}

// GET classifica amici (placeholder - richiede sistema amicizia)
if ($method === 'GET' && $action === 'friends') {
    requireLogin();
    // TODO: Implementare sistema amicizia
    jsonResponse(['friends_leaderboard' => []]);
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>