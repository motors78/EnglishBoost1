<?php
require_once '../config.php';
$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET lista verbi
if ($method === 'GET' && $action === 'list') {
    $level = $_GET['level'] ?? null;
    $sql = "SELECT * FROM verbs";
    if ($level) {
        $sql .= " WHERE level = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$level]);
    } else {
        $stmt = $db->query($sql);
    }
    jsonResponse(['verbs' => $stmt->fetchAll()]);
}

// Verifica risposta verbo
if ($method === 'POST' && $action === 'check') {
    requireLogin();
    $userId = getCurrentUserId();
    $data = getRequestData();
    
    $verbId = (int)($data['verb_id'] ?? 0);
    $pastSimple = strtolower(trim($data['past_simple'] ?? ''));
    $pastParticiple = strtolower(trim($data['past_participle'] ?? ''));
    
    if (!$verbId) jsonResponse(['error' => 'verb_id obbligatorio'], 400);
    
    // Get verbo corretto
    $stmt = $db->prepare("SELECT * FROM verbs WHERE id = ?");
    $stmt->execute([$verbId]);
    $verb = $stmt->fetch();
    
    if (!$verb) jsonResponse(['error' => 'Verbo non trovato'], 404);
    
    // Verifica risposta (accetta varianti come got/gotten)
    $psCorrect = $pastSimple === strtolower($verb['past_simple']) || 
                 (strpos($verb['past_simple'], '/') !== false && 
                  in_array($pastSimple, array_map('trim', explode('/', strtolower($verb['past_simple'])))));
    
    $ppCorrect = $pastParticiple === strtolower($verb['past_participle']) || 
                 (strpos($verb['past_participle'], '/') !== false && 
                  in_array($pastParticiple, array_map('trim', explode('/', strtolower($verb['past_participle'])))));
    
    $isCorrect = $psCorrect && $ppCorrect;
    
    // Update stats
    $stmt = $db->prepare("SELECT * FROM verbs_practiced WHERE user_id = ? AND verb_id = ?");
    $stmt->execute([$userId, $verbId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $stmt = $db->prepare("UPDATE verbs_practiced SET total_attempts = total_attempts + 1, correct_attempts = correct_attempts + ?, last_practiced = NOW() WHERE user_id = ? AND verb_id = ?");
        $stmt->execute([$isCorrect ? 1 : 0, $userId, $verbId]);
    } else {
        $stmt = $db->prepare("INSERT INTO verbs_practiced (user_id, verb_id, correct_attempts, total_attempts) VALUES (?, ?, ?, 1)");
        $stmt->execute([$userId, $verbId, $isCorrect ? 1 : 0]);
    }
    
    // +25 XP se corretto
    if ($isCorrect) {
        $stmt = $db->prepare("UPDATE users SET current_xp = current_xp + 25, total_xp = total_xp + 25 WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    jsonResponse([
        'correct' => $isCorrect,
        'xp_earned' => $isCorrect ? 25 : 0,
        'correct_answer' => [
            'past_simple' => $verb['past_simple'],
            'past_participle' => $verb['past_participle']
        ]
    ]);
}

// Progresso verbi
if ($method === 'GET' && $action === 'progress') {
    requireLogin();
    $userId = getCurrentUserId();
    
    $stmt = $db->prepare("
        SELECT v.*, vp.correct_attempts, vp.total_attempts, vp.last_practiced
        FROM verbs_practiced vp
        JOIN verbs v ON vp.verb_id = v.id
        WHERE vp.user_id = ?
        ORDER BY vp.last_practiced DESC
    ");
    $stmt->execute([$userId]);
    
    jsonResponse(['practiced' => $stmt->fetchAll()]);
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>