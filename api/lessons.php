<?php

require_once '../config.php';

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    $difficulty = $_GET['difficulty'] ?? null;
    
    $sql = "SELECT id, title, difficulty, xp_reward, theory, examples, exercises FROM lessons";
    
    if ($difficulty) {
        $sql .= " WHERE difficulty = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$difficulty]);
    } else {
        $stmt = $db->query($sql);
    }
    
    $lessons = $stmt->fetchAll();
    
    foreach ($lessons as &$lesson) {
        $lesson['examples'] = json_decode($lesson['examples'], true);
        $lesson['exercises'] = json_decode($lesson['exercises'], true);
    }
    
    jsonResponse(['lessons' => $lessons]);
}

if ($method === 'GET' && $action === 'get' && isset($_GET['id'])) {
    $lessonId = (int)$_GET['id'];
    
    $stmt = $db->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();
    
    if (!$lesson) {
        jsonResponse(['error' => 'Lezione non trovata'], 404);
    }
    
    $lesson['examples'] = json_decode($lesson['examples'], true);
    $lesson['exercises'] = json_decode($lesson['exercises'], true);
    
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("
            SELECT COUNT(*) as times_completed, MAX(score) as best_score
            FROM lessons_completed
            WHERE user_id = ? AND lesson_id = ?
        ");
        $stmt->execute([$userId, $lessonId]);
        $progress = $stmt->fetch();
        $lesson['progress'] = $progress;
    }
    
    jsonResponse(['lesson' => $lesson]);
}

if ($method === 'POST' && $action === 'complete') {
    requireLogin();
    
    $userId = getCurrentUserId();
    $data = getRequestData();
    
    $lessonId = (int)($data['lesson_id'] ?? 0);
    $score = (int)($data['score'] ?? 0);
    $answers = $data['answers'] ?? [];
    
    if (!$lessonId) {
        jsonResponse(['error' => 'lesson_id obbligatorio'], 400);
    }
    
    $stmt = $db->prepare("SELECT xp_reward, exercises FROM lessons WHERE id = ?");
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();
    
    if (!$lesson) {
        jsonResponse(['error' => 'Lezione non trovata'], 404);
    }
    
    $exercises = json_decode($lesson['exercises'], true);
    $totalQuestions = count($exercises);
    $correctAnswers = 0;
    
    foreach ($answers as $idx => $answer) {
        if (isset($exercises[$idx]) && $exercises[$idx]['correct'] === $answer) {
            $correctAnswers++;
        }
    }
    
    $xpEarned = floor(($correctAnswers / $totalQuestions) * $lesson['xp_reward']);
    
    $stmt = $db->prepare("
        INSERT INTO lessons_completed (user_id, lesson_id, score, xp_earned)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $lessonId, $score, $xpEarned]);
    
    $stmt = $db->prepare("
        UPDATE users 
        SET current_xp = current_xp + ?,
            total_xp = total_xp + ?
        WHERE id = ?
    ");
    $stmt->execute([$xpEarned, $xpEarned, $userId]);
    
    $stmt = $db->prepare("SELECT level, current_xp, max_xp FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    $leveledUp = false;
    $newLevel = $user['level'];
    
    while ($user['current_xp'] >= $user['max_xp']) {
        $newLevel++;
        $user['current_xp'] -= $user['max_xp'];
        $user['max_xp'] = $newLevel * 300;
        $leveledUp = true;
    }
    
    if ($leveledUp) {
        $stmt = $db->prepare("
            UPDATE users 
            SET level = ?, current_xp = ?, max_xp = ?
            WHERE id = ?
        ");
        $stmt->execute([$newLevel, $user['current_xp'], $user['max_xp'], $userId]);
    }
    
    $stmt = $db->prepare("
        INSERT INTO xp_history (user_id, xp_amount, source, description)
        VALUES (?, ?, 'lesson', ?)
    ");
    $stmt->execute([$userId, $xpEarned, "Lezione: {$lessonId}"]);
    
    checkAchievements($userId, $db);
    
    jsonResponse([
        'success' => true,
        'xp_earned' => $xpEarned,
        'correct_answers' => $correctAnswers,
        'total_questions' => $totalQuestions,
        'leveled_up' => $leveledUp,
        'new_level' => $newLevel,
        'current_xp' => $user['current_xp'],
        'max_xp' => $user['max_xp']
    ]);
}

if ($method === 'GET' && $action === 'progress') {
    requireLogin();
    
    $userId = getCurrentUserId();
    
    $stmt = $db->prepare("
        SELECT l.id, l.title, l.difficulty, lc.score, lc.xp_earned, lc.completed_at
        FROM lessons_completed lc
        JOIN lessons l ON lc.lesson_id = l.id
        WHERE lc.user_id = ?
        ORDER BY lc.completed_at DESC
    ");
    $stmt->execute([$userId]);
    $completedLessons = $stmt->fetchAll();
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT lesson_id) as unique_lessons,
            COUNT(*) as total_completions,
            SUM(xp_earned) as total_xp_from_lessons,
            AVG(score) as average_score
        FROM lessons_completed
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();
    
    jsonResponse([
        'completed_lessons' => $completedLessons,
        'stats' => $stats
    ]);
}

function checkAchievements($userId, $db) {
    $stmt = $db->prepare("
        SELECT a.* FROM achievements a
        WHERE a.id NOT IN (
            SELECT achievement_id FROM user_achievements WHERE user_id = ?
        )
    ");
    $stmt->execute([$userId]);
    $pendingAchievements = $stmt->fetchAll();
    
    foreach ($pendingAchievements as $achievement) {
        $unlocked = false;
        
        switch ($achievement['requirement_type']) {
            case 'lessons':
                $stmt = $db->prepare("SELECT COUNT(DISTINCT lesson_id) as count FROM lessons_completed WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                $unlocked = $count >= $achievement['requirement_value'];
                break;
                
            case 'xp':
                $stmt = $db->prepare("SELECT total_xp FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $xp = $stmt->fetch()['total_xp'];
                $unlocked = $xp >= $achievement['requirement_value'];
                break;
                
            case 'streak':
                $stmt = $db->prepare("SELECT streak_days FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $streak = $stmt->fetch()['streak_days'];
                $unlocked = $streak >= $achievement['requirement_value'];
                break;
                
            case 'vocabulary':
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM vocabulary_learned WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                $unlocked = $count >= $achievement['requirement_value'];
                break;
                
            case 'verbs':
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM verbs_practiced WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                $unlocked = $count >= $achievement['requirement_value'];
                break;
        }
        
        if ($unlocked) {
            $stmt = $db->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)");
            $stmt->execute([$userId, $achievement['id']]);
            
            if ($achievement['xp_reward'] > 0) {
                $stmt = $db->prepare("UPDATE users SET current_xp = current_xp + ?, total_xp = total_xp + ? WHERE id = ?");
                $stmt->execute([$achievement['xp_reward'], $achievement['xp_reward'], $userId]);
            }
        }
    }
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>