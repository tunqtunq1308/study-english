<?php
/* ====== CẤU HÌNH ====== */
const DB_HOST = 'fdb1029.awardspace.net';
const DB_NAME = '4796688_studyenglish';
const DB_USER = '4796688_studyenglish';
const DB_PASS = '@Tunq1308';
const APP_PASS = ''; // để trống = không yêu cầu mật khẩu ứng dụng
/* ======================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, $c = 200) {
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function slugify($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[\s_]+/', '-', $value);
    $value = preg_replace('/[^a-z0-9\-]/u', '', $value);
    $value = preg_replace('/-+/', '-', $value);
    return trim($value, '-');
}

function normalizeTopicName($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/\s+/', ' ', $value);
    return ucwords(mb_strtolower($value, 'UTF-8'));
}

if (APP_PASS !== '') {
    $tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if (!hash_equals(APP_PASS, (string)$tok)) out(['error' => 'Sai mật khẩu'], 401);
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    out(['error' => 'Không kết nối được MySQL: ' . $e->getMessage()], 500);
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    color VARCHAR(20) NOT NULL DEFAULT '#ff7a45',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_topics_parent (parent_id),
    KEY idx_topics_name (name)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    w VARCHAR(190) NOT NULL,
    m VARCHAR(255) NOT NULL,
    topic_id INT NULL,
    topic VARCHAR(100) NOT NULL DEFAULT '',
    lv TINYINT NOT NULL DEFAULT 0,
    due BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_words_word (w),
    KEY idx_words_topic_id (topic_id),
    KEY idx_words_due (due),
    CONSTRAINT fk_words_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS word_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    word_id INT NOT NULL,
    suggested_topic_id INT NULL,
    user_topic_id INT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_word_feedback_word (word_id),
    CONSTRAINT fk_feedback_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
");

$pdo->exec("CREATE TABLE IF NOT EXISTS kv (
    k VARCHAR(50) PRIMARY KEY,
    v TEXT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

function ensureTopic(PDO $pdo, string $name): int {
    $name = normalizeTopicName($name);
    if ($name === '') return 0;

    $slug = slugify($name);
    if ($slug === '') return 0;

    $existing = $pdo->prepare('SELECT id FROM topics WHERE slug = ? LIMIT 1');
    $existing->execute([$slug]);
    $id = (int) $existing->fetchColumn();
    if ($id > 0) return $id;

    $ins = $pdo->prepare('INSERT INTO topics (slug, name, description, color) VALUES (?, ?, ?, ?)');
    $ins->execute([
        $slug,
        $name,
        'Tạo tự động từ hệ thống học từ vựng',
        '#ff7a45'
    ]);

    return (int) $pdo->lastInsertId();
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$a = $_GET['a'] ?? 'list';
$GAP = [0, 1, 2, 4, 8, 16];

try {
    switch ($a) {
        case 'list':
            $rows = $pdo->query(
                'SELECT w.id, w.w, w.m, w.topic, w.topic_id, t.name AS topic_name, w.lv, w.due
                 FROM words w
                 LEFT JOIN topics t ON t.id = w.topic_id
                 ORDER BY w.id ASC'
            )->fetchAll();

            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['topic_id'] = $r['topic_id'] !== null ? (int)$r['topic_id'] : null;
                $r['lv'] = (int)$r['lv'];
                $r['due'] = (float)$r['due'];
                $r['topic'] = $r['topic'] !== '' ? $r['topic'] : ($r['topic_name'] ?? '');
            }
            unset($r);

            $topics = $pdo->query('SELECT id, slug, name, description, color FROM topics WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
            foreach ($topics as &$tp) {
                $tp['id'] = (int)$tp['id'];
            }
            unset($tp);

            $st = $pdo->query("SELECT v FROM kv WHERE k='stats'")->fetchColumn();
            $se = $pdo->query("SELECT v FROM kv WHERE k='settings'")->fetchColumn();

            out([
                'words' => $rows,
                'topics' => $topics,
                'stats' => $st ? json_decode($st, true) : null,
                'settings' => $se ? json_decode($se, true) : null,
            ]);

        case 'topics':
            $topics = $pdo->query('SELECT id, slug, name, description, color FROM topics WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
            foreach ($topics as &$tp) {
                $tp['id'] = (int)$tp['id'];
            }
            unset($tp);
            out(['topics' => $topics]);

        case 'setset':
            $s = is_array($in['settings'] ?? null) ? $in['settings'] : [];
            $clean = ['provider' => mb_substr((string)($s['provider'] ?? 'gemini'), 0, 30), 'keys' => [], 'models' => [], 'bases' => []];
            foreach (['keys' => 300, 'models' => 100, 'bases' => 200] as $f => $len) {
                if (is_array($s[$f] ?? null)) {
                    foreach ($s[$f] as $k => $v) {
                        if (is_scalar($v) && preg_match('/^[a-z0-9_-]{1,30}$/i', (string)$k)) {
                            $clean[$f][(string)$k] = mb_substr(trim((string)$v), 0, $len);
                        }
                    }
                }
                $clean[$f] = (object)$clean[$f];
            }
            $pdo->prepare("REPLACE INTO kv (k,v) VALUES ('settings',?)")->execute([json_encode($clean, JSON_UNESCAPED_UNICODE)]);
            out(['settings' => $clean]);

        case 'bulk':
            $items = is_array($in['items'] ?? null) ? $in['items'] : [];
            $res = [];
            $skip = 0;
            $ins = $pdo->prepare('INSERT INTO words (w, m, topic, topic_id) VALUES (?, ?, ?, ?)');
            $chk = $pdo->prepare('SELECT COUNT(*) FROM words WHERE LOWER(w)=LOWER(?)');

            foreach ($items as $it) {
                $w = mb_substr(trim((string)($it['w'] ?? '')), 0, 190);
                $m = mb_substr(trim((string)($it['m'] ?? '')), 0, 255);
                $topicName = normalizeTopicName((string)($it['topic'] ?? ''));
                $topicId = isset($it['topic_id']) ? (int)$it['topic_id'] : null;

                if ($w === '' || $m === '') { $skip++; continue; }
                $chk->execute([$w]);
                if ((int)$chk->fetchColumn() > 0) { $skip++; continue; }

                if ($topicId <= 0 && $topicName !== '') {
                    $topicId = ensureTopic($pdo, $topicName);
                }

                $ins->execute([$w, $m, $topicName, $topicId > 0 ? $topicId : null]);
                $newId = (int)$pdo->lastInsertId();
                $res[] = [
                    'id' => $newId,
                    'w' => $w,
                    'm' => $m,
                    'topic' => $topicName,
                    'topic_id' => $topicId > 0 ? $topicId : null
                ];
            }

            out(['items' => $res, 'skipped' => $skip]);

        case 'edit':
            $id = (int)($in['id'] ?? 0);
            $w = mb_substr(trim((string)($in['w'] ?? '')), 0, 190);
            $m = mb_substr(trim((string)($in['m'] ?? '')), 0, 255);
            $topicName = normalizeTopicName((string)($in['topic'] ?? ''));
            $topicId = null;

            if ($id <= 0 || $w === '' || $m === '') out(['error' => 'Thiếu từ hoặc nghĩa'], 400);

            if ($topicName !== '') {
                $topicId = ensureTopic($pdo, $topicName);
            }

            $pdo->prepare('UPDATE words SET w=?, m=?, topic=?, topic_id=? WHERE id=?')->execute([$w, $m, $topicName, $topicId, $id]);
            out(['ok' => true, 'w' => $w, 'm' => $m, 'topic' => $topicName, 'topic_id' => $topicId]);

        case 'del':
            $pdo->prepare('DELETE FROM words WHERE id=?')->execute([(int)($in['id'] ?? 0)]);
            out(['ok' => true]);

        case 'review':
            $id = (int)($in['id'] ?? 0);
            $s = $pdo->prepare('SELECT lv FROM words WHERE id=?');
            $s->execute([$id]);
            $lv = (int)$s->fetchColumn();
            $lv = !empty($in['ok']) ? min(5, $lv + 1) : 0;
            $due = (int)(microtime(true) * 1000) + $GAP[$lv] * 86400000;
            $pdo->prepare('UPDATE words SET lv=?, due=? WHERE id=?')->execute([$lv, $due, $id]);
            out(['lv' => $lv, 'due' => $due]);

        case 'stats':
            $pdo->prepare("REPLACE INTO kv (k,v) VALUES ('stats',?)")->execute([json_encode($in, JSON_UNESCAPED_UNICODE)]);
            out(['ok' => true]);

        case 'settopics':
            $topics = is_array($in['topics'] ?? null) ? $in['topics'] : [];
            foreach ($topics as $id => $t) {
                $name = normalizeTopicName((string)$t);
                $topicId = $name !== '' ? ensureTopic($pdo, $name) : null;
                $pdo->prepare('UPDATE words SET topic=?, topic_id=? WHERE id=?')->execute([$name, $topicId, (int)$id]);
            }
            out(['ok' => true]);

        case 'create_topic':
            $name = normalizeTopicName((string)($in['name'] ?? ''));
            if ($name === '') out(['error' => 'Tên chủ đề không được trống'], 400);
            $id = ensureTopic($pdo, $name);
            out(['ok' => true, 'topic' => ['id' => $id, 'name' => $name]]);

        case 'feedback':
            $wordId = (int)($in['word_id'] ?? 0);
            $suggestedTopicId = isset($in['suggested_topic_id']) ? (int)$in['suggested_topic_id'] : null;
            $userTopicId = isset($in['user_topic_id']) ? (int)$in['user_topic_id'] : null;
            $isCorrect = !empty($in['is_correct']) ? 1 : 0;
            $note = trim((string)($in['note'] ?? ''));

            if ($wordId <= 0) out(['error' => 'word_id không hợp lệ'], 400);
            $pdo->prepare('INSERT INTO word_feedback (word_id, suggested_topic_id, user_topic_id, is_correct, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$wordId, $suggestedTopicId > 0 ? $suggestedTopicId : null, $userTopicId > 0 ? $userTopicId : null, $isCorrect, $note]);
            out(['ok' => true]);

        default:
            out(['error' => 'Action không hợp lệ'], 400);
    }
} catch (Exception $e) {
    out(['error' => $e->getMessage()], 500);
}
