<?php
/**
 * EnglishBoost - Gamification API
 * File: api/gamification.php
 * 
 * Gestisce: achievements, sfide giornaliere, skill tree, leghe, power-ups
 */

require_once '../config.php';

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ================================================
// ACHIEVEMENTS
// ================================================

// GET: Lista tutti gli achievement
if ($method === 'GET' && $action === 'achievements') {
    $stmt = $db->query("
        SELECT id, name, description, emoji, category, 
               requirement_type, requirement_value, xp_reward, rarity, hidden
        FROM achievements
        ORDER BY 
            FIELD(category, 'beginner', 'intermediate', 'advanced', 'expert', 'legendary'),
            id
    ");
    $achievements = $stmt->fetchAll();
    
    // Se utente loggato, aggiungi info su quali ha sbloccato
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("
            SELECT achievement_id, unlocked_at 
            FROM user_achievements 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $unlocked = [];
        foreach ($stmt->fetchAll() as $row) {
            $unlocked[$row['achievement_id']] = $row['unlocked_at'];
        }
        
        foreach ($achievements as &$achievement) {
            $achievement['unlocked'] = isset($unlocked[$achievement['id']]);
            $achievement['unlocked_at'] = $unlocked[$achievement['id']] ?? null;
        }
    }
    
    jsonResponse(['achievements' => $achievements]);
}

// GET: Achievement utente (solo sbloccati)
if ($method === 'GET' && $action === 'my-achievements') {
    requireLogin();
    $userId = getCurrentUserId();
    
    $stmt = $db->prepare("
        SELECT a.*, ua.unlocked_at
        FROM user_achievements ua
        JOIN achievements a ON ua.achievement_id = a.id
        WHERE ua.user_id = ?
        ORDER BY ua.unlocked_at DESC
    ");
    $stmt->execute([$userId]);
    
    jsonResponse(['achievements' => $stmt->fetchAll()]);
}

// POST: Check e sblocca achievement (chiamato automaticamente dopo azioni)
if ($method === 'POST' && $action === 'check-achievements') {
    requireLogin();
    $userId = getCurrentUserId();
    
    $newAchievements = checkAndUnlockAchievements($userId, $db);
    
    jsonResponse([
        'success' => true,
        'new_achievements' => $newAchievements,
        'count' => count($newAchievements)
    ]);
}

// ================================================
// SFIDE GIORNALIERE
// ================================================

// GET: Sfide del giorno
if ($method === 'GET' && $action === 'daily-challenges') {
    $today = date('Y-m-d');
    
    // Controlla se esistono sfide per oggi
    $stmt = $db->prepare("SELECT * FROM daily_challenges WHERE challenge_date = ?");
    $stmt->execute([$today]);
    $challenges = $stmt->fetch();
    
    // Se non esistono, creale
    if (!$challenges) {
        $challenges = generateDailyChallenges($today, $db);
    }
    
    // Se utente loggato, prendi il suo progresso
    $progress = ['challenge_1_progress' => 0, 'challenge_2_progress' => 0, 'challenge_3_progress' => 0];
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("
            SELECT * FROM user_daily_progress 
            WHERE user_id = ? AND challenge_date = ?
        ");
        $stmt->execute([$userId, $today]);
        $userProgress = $stmt->fetch();
        
        if ($userProgress) {
            $progress = $userProgress;
        } else {
            // Crea record progresso
            $stmt = $db->prepare("
                INSERT INTO user_daily_progress (user_id, challenge_date) 
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $today]);
            $progress['id'] = $db->lastInsertId();
        }
    }
    
    jsonResponse([
        'challenges' => [
            [
                'id' => 1,
                'type' => $challenges['challenge_1_type'],
                'target' => $challenges['challenge_1_target'],
                'xp' => $challenges['challenge_1_xp'],
                'description' => $challenges['challenge_1_description'],
                'progress' => $progress['challenge_1_progress'] ?? 0,
                'completed' => $progress['challenge_1_completed'] ?? false
            ],
            [
                'id' => 2,
                'type' => $challenges['challenge_2_type'],
                'target' => $challenges['challenge_2_target'],
                'xp' => $challenges['challenge_2_xp'],
                'description' => $challenges['challenge_2_description'],
                'progress' => $progress['challenge_2_progress'] ?? 0,
                'completed' => $progress['challenge_2_completed'] ?? false
            ],
            [
                'id' => 3,
                'type' => $challenges['challenge_3_type'],
                'target' => $challenges['challenge_3_target'],
                'xp' => $challenges['challenge_3_xp'],
                'description' => $challenges['challenge_3_description'],
                'progress' => $progress['challenge_3_progress'] ?? 0,
                'completed' => $progress['challenge_3_completed'] ?? false
            ]
        ],
        'bonus_available' => isset($progress['challenge_1_completed']) && 
                           $progress['challenge_1_completed'] && 
                           $progress['challenge_2_completed'] && 
                           $progress['challenge_3_completed'] &&
                           !$progress['bonus_claimed'],
        'bonus_claimed' => $progress['bonus_claimed'] ?? false
    ]);
}

// POST: Claim bonus sfide completate
if ($method === 'POST' && $action === 'claim-daily-bonus') {
    requireLogin();
    $userId = getCurrentUserId();
    $today = date('Y-m-d');
    
    $stmt = $db->prepare("
        SELECT * FROM user_daily_progress 
        WHERE user_id = ? AND challenge_date = ?
    ");
    $stmt->execute([$userId, $today]);
    $progress = $stmt->fetch();
    
    if (!$progress) {
        jsonResponse(['error' => 'Progresso non trovato'], 404);
    }
    
    if (!$progress['challenge_1_completed'] || 
        !$progress['challenge_2_completed'] || 
        !$progress['challenge_3_completed']) {
        jsonResponse(['error' => 'Completa tutte le sfide prima'], 400);
    }
    
    if ($progress['bonus_claimed']) {
        jsonResponse(['error' => 'Bonus già reclamato'], 400);
    }
    
    // Dai bonus XP (50% in più)
    $bonusXP = 150; // Bonus fisso per tutte e 3
    
    $stmt = $db->prepare("
        UPDATE users 
        SET current_xp = current_xp + ?, total_xp = total_xp + ? 
        WHERE id = ?
    ");
    $stmt->execute([$bonusXP, $bonusXP, $userId]);
    
    $stmt = $db->prepare("
        UPDATE user_daily_progress 
        SET bonus_claimed = TRUE 
        WHERE user_id = ? AND challenge_date = ?
    ");
    $stmt->execute([$userId, $today]);
    
    jsonResponse([
        'success' => true,
        'bonus_xp' => $bonusXP,
        'message' => 'Bonus reclamato! +' . $bonusXP . ' XP'
    ]);
}

// ================================================
// SKILL TREE
// ================================================

// GET: Skill dell'utente
if ($method === 'GET' && $action === 'skills') {
    requireLogin();
    $userId = getCurrentUserId();
    
    // Inizializza skill se non esistono
    $skills = ['grammar', 'vocabulary', 'speaking', 'listening', 'reading', 'writing'];
    foreach ($skills as $skill) {
        $stmt = $db->prepare("
            INSERT IGNORE INTO user_skills (user_id, skill_type) 
            VALUES (?, ?)
        ");
        $stmt->execute([$userId, $skill]);
    }
    
    // Prendi skill
    $stmt = $db->prepare("
        SELECT skill_type, skill_level, skill_xp, skill_max_xp, points_allocated 
        FROM user_skills 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $userSkills = $stmt->fetchAll();
    
    // Prendi bonus attivi
    $stmt = $db->prepare("
        SELECT skill_type, bonus_type, bonus_value 
        FROM skill_bonuses 
        WHERE user_id = ? AND active = TRUE
    ");
    $stmt->execute([$userId]);
    $bonuses = $stmt->fetchAll();
    
    // Calcola skill points disponibili
    $stmt = $db->prepare("SELECT level FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userLevel = $stmt->fetch()['level'];
    $totalPoints = $userLevel; // 1 punto per livello
    $usedPoints = array_sum(array_column($userSkills, 'points_allocated'));
    $availablePoints = $totalPoints - $usedPoints;
    
    jsonResponse([
        'skills' => $userSkills,
        'bonuses' => $bonuses,
        'available_points' => $availablePoints,
        'total_points' => $totalPoints
    ]);
}

// POST: Alloca punto skill
if ($method === 'POST' && $action === 'allocate-skill-point') {
    requireLogin();
    $userId = getCurrentUserId();
    $data = getRequestData();
    $skillType = $data['skill_type'] ?? '';
    
    if (!in_array($skillType, ['grammar', 'vocabulary', 'speaking', 'listening', 'reading', 'writing'])) {
        jsonResponse(['error' => 'Skill type non valido'], 400);
    }
    
    // Check punti disponibili
    $stmt = $db->prepare("SELECT level FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userLevel = $stmt->fetch()['level'];
    
    $stmt = $db->prepare("
        SELECT SUM(points_allocated) as used 
        FROM user_skills 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $usedPoints = $stmt->fetch()['used'] ?? 0;
    
    if ($usedPoints >= $userLevel) {
        jsonResponse(['error' => 'Nessun punto disponibile'], 400);
    }
    
    // Alloca punto
    $stmt = $db->prepare("
        UPDATE user_skills 
        SET points_allocated = points_allocated + 1,
            skill_level = skill_level + 1,
            skill_max_xp = skill_max_xp + 50
        WHERE user_id = ? AND skill_type = ?
    ");
    $stmt->execute([$userId, $skillType]);
    
    // Sblocca bonus ogni 5 livelli
    $stmt = $db->prepare("
        SELECT skill_level FROM user_skills 
        WHERE user_id = ? AND skill_type = ?
    ");
    $stmt->execute([$userId, $skillType]);
    $newLevel = $stmt->fetch()['skill_level'];
    
    if ($newLevel % 5 === 0) {
        // Sblocca bonus
        $bonusType = 'xp_multiplier';
        $bonusValue = 1.0 + ($newLevel / 5) * 0.1; // +10% ogni 5 livelli
        
        $stmt = $db->prepare("
            INSERT INTO skill_bonuses (user_id, skill_type, bonus_type, bonus_value) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $skillType, $bonusType, $bonusValue]);
    }
    
    jsonResponse([
        'success' => true,
        'new_level' => $newLevel,
        'bonus_unlocked' => $newLevel % 5 === 0
    ]);
}

// ================================================
// LEGHE E COMPETIZIONI
// ================================================

// GET: Lega corrente dell'utente
if ($method === 'GET' && $action === 'my-league') {
    requireLogin();
    $userId = getCurrentUserId();
    
    // Determina lega basata su total_xp
    $stmt = $db->prepare("SELECT total_xp FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $totalXP = $stmt->fetch()['total_xp'];
    
    $stmt = $db->prepare("
        SELECT * FROM leagues 
        WHERE min_xp <= ? AND max_xp >= ? 
        LIMIT 1
    ");
    $stmt->execute([$totalXP, $totalXP]);
    $league = $stmt->fetch();
    
    if (!$league) {
        // Default bronze
        $stmt = $db->query("SELECT * FROM leagues WHERE tier = 'bronze'");
        $league = $stmt->fetch();
    }
    
    // Classifica della lega (top 10 nella stessa lega)
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.avatar, u.total_xp, u.streak_days
        FROM users u
        WHERE u.total_xp BETWEEN ? AND ?
        ORDER BY u.total_xp DESC
        LIMIT 10
    ");
    $stmt->execute([$league['min_xp'], $league['max_xp']]);
    $leagueLeaderboard = $stmt->fetchAll();
    
    // Rank utente nella lega
    $myRank = 0;
    foreach ($leagueLeaderboard as $index => $user) {
        if ($user['id'] == $userId) {
            $myRank = $index + 1;
            break;
        }
    }
    
    jsonResponse([
        'league' => $league,
        'leaderboard' => $leagueLeaderboard,
        'my_rank' => $myRank,
        'next_league' => $league['tier'] !== 'master' ? getNextLeague($league['tier'], $db) : null
    ]);
}

// GET: Torneo settimanale attivo
if ($method === 'GET' && $action === 'weekly-tournament') {
    $today = date('Y-m-d');
    
    $stmt = $db->prepare("
        SELECT * FROM weekly_tournaments 
        WHERE start_date <= ? AND end_date >= ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$today, $today]);
    $tournament = $stmt->fetch();
    
    if (!$tournament) {
        jsonResponse(['tournament' => null, 'message' => 'Nessun torneo attivo']);
    }
    
    // Top 10 partecipanti
    $stmt = $db->prepare("
        SELECT tp.*, u.username, u.avatar
        FROM tournament_participants tp
        JOIN users u ON tp.user_id = u.id
        WHERE tp.tournament_id = ?
        ORDER BY tp.score DESC
        LIMIT 10
    ");
    $stmt->execute([$tournament['id']]);
    $participants = $stmt->fetchAll();
    
    // Rank dell'utente corrente
    $myRank = null;
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("
            SELECT rank, score FROM tournament_participants 
            WHERE tournament_id = ? AND user_id = ?
        ");
        $stmt->execute([$tournament['id'], $userId]);
        $myData = $stmt->fetch();
        if ($myData) {
            $myRank = $myData;
        }
    }
    
    jsonResponse([
        'tournament' => $tournament,
        'leaderboard' => $participants,
        'my_rank' => $myRank
    ]);
}

// ================================================
// POWER-UPS
// ================================================

// GET: Lista power-ups disponibili
if ($method === 'GET' && $action === 'power-ups') {
    $stmt = $db->query("SELECT * FROM power_ups ORDER BY xp_cost ASC");
    $powerUps = $stmt->fetchAll();
    
    // Se loggato, mostra quanti ne possiede
    if (isLoggedIn()) {
        $userId = getCurrentUserId();
        $stmt = $db->prepare("
            SELECT power_up_id, SUM(quantity) as total, 
                   SUM(CASE WHEN active = TRUE THEN 1 ELSE 0 END) as active_count
            FROM user_power_ups 
            WHERE user_id = ? 
            GROUP BY power_up_id
        ");
        $stmt->execute([$userId]);
        $owned = [];
        foreach ($stmt->fetchAll() as $row) {
            $owned[$row['power_up_id']] = [
                'quantity' => $row['total'],
                'active' => $row['active_count'] > 0
            ];
        }
        
        foreach ($powerUps as &$powerUp) {
            $powerUp['owned'] = $owned[$powerUp['id']]['quantity'] ?? 0;
            $powerUp['active'] = $owned[$powerUp['id']]['active'] ?? false;
        }
    }
    
    jsonResponse(['power_ups' => $powerUps]);
}

// POST: Compra power-up
if ($method === 'POST' && $action === 'buy-power-up') {
    requireLogin();
    $userId = getCurrentUserId();
    $data = getRequestData();
    $powerUpId = (int)($data['power_up_id'] ?? 0);
    
    // Check power-up exists
    $stmt = $db->prepare("SELECT * FROM power_ups WHERE id = ?");
    $stmt->execute([$powerUpId]);
    $powerUp = $stmt->fetch();
    
    if (!$powerUp) {
        jsonResponse(['error' => 'Power-up non trovato'], 404);
    }
    
    // Check XP sufficienti
    $stmt = $db->prepare("SELECT total_xp FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userXP = $stmt->fetch()['total_xp'];
    
    if ($userXP < $powerUp['xp_cost']) {
        jsonResponse(['error' => 'XP insufficienti'], 400);
    }
    
    // Sottrai XP
    $stmt = $db->prepare("
        UPDATE users 
        SET total_xp = total_xp - ? 
        WHERE id = ?
    ");
    $stmt->execute([$powerUp['xp_cost'], $userId]);
    
    // Aggiungi power-up
    $stmt = $db->prepare("
        INSERT INTO user_power_ups (user_id, power_up_id, quantity) 
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ");
    $stmt->execute([$userId, $powerUpId]);
    
    jsonResponse([
        'success' => true,
        'message' => 'Power-up acquistato!',
        'remaining_xp' => $userXP - $powerUp['xp_cost']
    ]);
}

// POST: Attiva power-up
if ($method === 'POST' && $action === 'activate-power-up') {
    requireLogin();
    $userId = getCurrentUserId();
    $data = getRequestData();
    $powerUpId = (int)($data['power_up_id'] ?? 0);
    
    // Check possesso
    $stmt = $db->prepare("
        SELECT * FROM user_power_ups 
        WHERE user_id = ? AND power_up_id = ? AND quantity > 0 AND active = FALSE
        LIMIT 1
    ");
    $stmt->execute([$userId, $powerUpId]);
    $userPowerUp = $stmt->fetch();
    
    if (!$userPowerUp) {
        jsonResponse(['error' => 'Non possiedi questo power-up'], 400);
    }
    
    // Prendi info power-up
    $stmt = $db->prepare("SELECT * FROM power_ups WHERE id = ?");
    $stmt->execute([$powerUpId]);
    $powerUp = $stmt->fetch();
    
    // Attiva
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $powerUp['duration_hours'] . ' hours'));
    
    $stmt = $db->prepare("
        UPDATE user_power_ups 
        SET active = TRUE, 
            activated_at = NOW(), 
            expires_at = ?,
            quantity = quantity - 1
        WHERE id = ?
    ");
    $stmt->execute([$expiresAt, $userPowerUp['id']]);
    
    jsonResponse([
        'success' => true,
        'message' => 'Power-up attivato!',
        'expires_at' => $expiresAt
    ]);
}

// ================================================
// HELPER FUNCTIONS
// ================================================

function checkAndUnlockAchievements($userId, $db) {
    $newAchievements = [];
    
    // Prendi achievement non ancora sbloccati
    $stmt = $db->prepare("
        SELECT a.* FROM achievements a
        WHERE a.id NOT IN (
            SELECT achievement_id FROM user_achievements WHERE user_id = ?
        )
    ");
    $stmt->execute([$userId]);
    $pending = $stmt->fetchAll();
    
    foreach ($pending as $achievement) {
        $unlocked = false;
        $count = 0;
        
        switch ($achievement['requirement_type']) {
            case 'lessons':
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT lesson_id) as count 
                    FROM lessons_completed 
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
                
            case 'xp':
                $stmt = $db->prepare("SELECT total_xp as count FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
                
            case 'streak':
                $stmt = $db->prepare("SELECT streak_days as count FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
                
            case 'vocabulary':
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM vocabulary_learned WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
                
            case 'verbs':
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM verbs_practiced WHERE user_id = ?");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
                
            case 'daily_challenge':
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count 
                    FROM user_daily_progress 
                    WHERE user_id = ? AND challenge_1_completed = TRUE AND challenge_2_completed = TRUE AND challenge_3_completed = TRUE
                ");
                $stmt->execute([$userId]);
                $count = $stmt->fetch()['count'];
                break;
        }
        
        if ($count >= $achievement['requirement_value']) {
            // Sblocca!
            $stmt = $db->prepare("
                INSERT IGNORE INTO user_achievements (user_id, achievement_id) 
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $achievement['id']]);
            
            if ($db->lastInsertId()) {
                // Dai XP reward
                if ($achievement['xp_reward'] > 0) {
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET current_xp = current_xp + ?, total_xp = total_xp + ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$achievement['xp_reward'], $achievement['xp_reward'], $userId]);
                }
                
                $newAchievements[] = $achievement;
            }
        }
    }
    
    return $newAchievements;
}

function generateDailyChallenges($date, $db) {
    $challenges = [
        ['type' => 'complete_lessons', 'min' => 1, 'max' => 3, 'desc' => 'Completa {n} lezioni'],
        ['type' => 'learn_words', 'min' => 5, 'max' => 15, 'desc' => 'Impara {n} nuove parole'],
        ['type' => 'practice_verbs', 'min' => 3, 'max' => 8, 'desc' => 'Pratica {n} verbi'],
        ['type' => 'earn_xp', 'min' => 50, 'max' => 200, 'desc' => 'Guadagna {n} XP'],
        ['type' => 'perfect_score', 'min' => 1, 'max' => 2, 'desc' => 'Ottieni 100% in {n} lezioni']
    ];
    
    shuffle($challenges);
    $selected = array_slice($challenges, 0, 3);
    
    $challenge1 = $selected[0];
    $challenge2 = $selected[1];
    $challenge3 = $selected[2];
    
    $target1 = rand($challenge1['min'], $challenge1['max']);
    $target2 = rand($challenge2['min'], $challenge2['max']);
    $target3 = rand($challenge3['min'], $challenge3['max']);
    
    $stmt = $db->prepare("
        INSERT INTO daily_challenges (
            challenge_date,
            challenge_1_type, challenge_1_target, challenge_1_xp, challenge_1_description,
            challenge_2_type, challenge_2_target, challenge_2_xp, challenge_2_description,
            challenge_3_type, challenge_3_target, challenge_3_xp, challenge_3_description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $date,
        $challenge1['type'], $target1, 50, str_replace('{n}', $target1, $challenge1['desc']),
        $challenge2['type'], $target2, 75, str_replace('{n}', $target2, $challenge2['desc']),
        $challenge3['type'], $target3, 100, str_replace('{n}', $target3, $challenge3['desc'])
    ]);
    
    $stmt = $db->prepare("SELECT * FROM daily_challenges WHERE challenge_date = ?");
    $stmt->execute([$date]);
    return $stmt->fetch();
}

function getNextLeague($currentTier, $db) {
    $tiers = ['bronze', 'silver', 'gold', 'platinum', 'diamond', 'master'];
    $currentIndex = array_search($currentTier, $tiers);
    
    if ($currentIndex === false || $currentIndex >= count($tiers) - 1) {
        return null;
    }
    
    $nextTier = $tiers[$currentIndex + 1];
    $stmt = $db->prepare("SELECT * FROM leagues WHERE tier = ?");
    $stmt->execute([$nextTier]);
    return $stmt->fetch();
}

jsonResponse(['error' => 'Endpoint non trovato'], 404);
?>