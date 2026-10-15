<?php
/* ====== CẤU HÌNH ====== */
const DB_HOST = 'fdb1029.awardspace.net';
const DB_NAME = '4796688_studyenglish';
const DB_USER = '4796688_studyenglish';
const DB_PASS = '@Tunq1308';
const APP_PASS = '';   // đặt mật khẩu ứng dụng ở đây (để trống = không yêu cầu). Trang sẽ hỏi mật khẩu 1 lần.
/* ======================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (APP_PASS !== '') {
    $tok = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if (!hash_equals(APP_PASS, (string)$tok)) out(['error' => 'Sai mật khẩu'], 401);
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) { out(['error' => 'Không kết nối được MySQL: ' . $e->getMessage()], 500); }

$pdo->exec("CREATE TABLE IF NOT EXISTS words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    w VARCHAR(190) NOT NULL,
    m VARCHAR(255) NOT NULL,
    topic VARCHAR(100) NOT NULL DEFAULT '',
    lv TINYINT NOT NULL DEFAULT 0,
    due BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS kv (
    k VARCHAR(50) PRIMARY KEY,
    v TEXT
) CHARACTER SET utf8mb4");

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$a = $_GET['a'] ?? 'list';
$GAP = [0, 1, 2, 4, 8, 16];

try {
    switch ($a) {
        case 'list':
            $rows = $pdo->query('SELECT id,w,m,topic,lv,due FROM words ORDER BY id')->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['lv'] = (int)$r['lv']; $r['due'] = (float)$r['due']; }
            unset($r);
            $st = $pdo->query("SELECT v FROM kv WHERE k='stats'")->fetchColumn();
            $se = $pdo->query("SELECT v FROM kv WHERE k='settings'")->fetchColumn();
            out(['words' => $rows, 'stats' => $st ? json_decode($st, true) : null, 'settings' => $se ? json_decode($se, true) : null]);

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
            $res = []; $skip = 0;
            $ins = $pdo->prepare('INSERT INTO words (w,m,topic) VALUES (?,?,?)');
            $chk = $pdo->prepare('SELECT COUNT(*) FROM words WHERE LOWER(w)=LOWER(?)');
            foreach ($items as $it) {
                $w = mb_substr(trim((string)($it['w'] ?? '')), 0, 190);
                $m = mb_substr(trim((string)($it['m'] ?? '')), 0, 255);
                $t = mb_substr(trim((string)($it['topic'] ?? '')), 0, 100);
                if ($w === '' || $m === '') { $skip++; continue; }
                $chk->execute([$w]);
                if ((int)$chk->fetchColumn() > 0) { $skip++; continue; }
                $ins->execute([$w, $m, $t]);
                $res[] = ['id' => (int)$pdo->lastInsertId(), 'w' => $w, 'm' => $m, 'topic' => $t];
            }
            out(['items' => $res, 'skipped' => $skip]);

        case 'edit':
            $id = (int)($in['id'] ?? 0);
            $w = mb_substr(trim((string)($in['w'] ?? '')), 0, 190);
            $m = mb_substr(trim((string)($in['m'] ?? '')), 0, 255);
            $t = mb_substr(trim((string)($in['topic'] ?? '')), 0, 100);
            if ($id <= 0 || $w === '' || $m === '') out(['error' => 'Thiếu từ hoặc nghĩa'], 400);
            $pdo->prepare('UPDATE words SET w=?, m=?, topic=? WHERE id=?')->execute([$w, $m, $t, $id]);
            out(['ok' => true, 'w' => $w, 'm' => $m, 'topic' => $t]);

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
            $up = $pdo->prepare('UPDATE words SET topic=? WHERE id=?');
            $topics = is_array($in['topics'] ?? null) ? $in['topics'] : [];
            foreach ($topics as $id => $t) {
                $up->execute([mb_substr(trim((string)$t), 0, 100), (int)$id]);
            }
            out(['ok' => true]);

        default:
            out(['error' => 'Action không hợp lệ'], 400);
    }
} catch (Exception $e) { out(['error' => $e->getMessage()], 500); }
