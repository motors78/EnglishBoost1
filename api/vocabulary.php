w<?php
require_once '../config.php';
$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET lista vocabolario
if ($method === 'GET' && $action === 'list') {
    $difficulty = $_GET['difficulty'] ?? null;
    $sql = "SELECT * FROM vocabulary";
    if ($difficulty) {
        $sql .= " WHERE difficulty = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$difficulty]);
    } else {
        $stmt = $db->query($sql);
    }
    jsonResponse(['vocabulary' => $stmt->fetchAll()]);
}

// Segna parola come appresa
if ($method === 'POST' && $action === 'learn') {
    requireLogin();
    $userId = getCurrentUserId();
    $data = getRequestData();
    $vocabId = (int)($data['vocabulary_id'] ?? 0);
    
    if (!$vocabId) jsonResponse(['error' => 'vocabulary_id obbligatorio'], 400);
    
    // Check se già appresa
    $stmt = $db->prepare("SELECT * FROM vocabulary_learned WHERE user_id = ? AND vocabulary_id = ?");
    $stmt->execute([$userId, $vocabId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Incrementa review
        $stmt = $db->prepare("UPDATE vocabulary_learned SET times_reviewed = times_reviewed + 1, mastery_level = LEAST(mastery_level + 1, 5), last_reviewed = NOW() WHERE user_id = ? AND vocabulary_id = ?");
        $stmt->execute([$userId, $vocabId]);
    } else {
        // Prima volta
        $stmt = $db->prepare("INSERT INTO vocabulary_learned (user_id, vocabulary_id) VALUES (?, ?)");
        $stmt->execute([$userId, $vocabId]);
    }
    
    // +10 XP
    $stmt = $db->prepare("UPDATE users SET current_xp = current_xp + 10, total_xp = total_xp + 10 WHERE id = ?");
    $stmt->execute([$userId]);
    
    jsonResponse(['success' => true, 'xp_earned' => 10]);
}

// Progresso vocabolario utente
if ($method === 'GET' && $action === 'progress') {
    requireLogin();
    $userId = getCurrentUserId();
    
    $stmt = $db->prepare("
        SELECT v.*, vl.times_reviewed, vl.mastery_level, vl.last_reviewed
        FROM vocabulary_learned vl
        JOIN vocabulary v ON vl.vocabulary_id = v.id
        WHERE vl.user_id = ?
        ORDER BY vl.last_reviewed DESC
    ");
    $stmt->execute([$userId]);
    
    jsonResponse(['learned' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>