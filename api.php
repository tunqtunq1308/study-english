<?php
/* ====== CẤU HÌNH ====== */
const DB_HOST = 'localhost';
const DB_NAME = 'vocat';        // tạo sẵn database này trong MySQL
const DB_USER = 'root';
const DB_PASS = '';
const GEMINI_KEY   = 'AQ.Ab8RN6JU3-a3DIXtdxQ8zsbRG0qIJ3mHkOx5zkQ1x0b4nSA_jg';
const GEMINI_MODEL = 'gemini-2.5-flash';
const DEFAULT_TOPICS = ['Đồ ăn', 'Miêu tả', 'Hành động'];
const BATCH = 40;
/* ======================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

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

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? 'list';
$GAP = [0, 1, 2, 4, 8, 16];

function gemini($prompt) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_KEY],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json'],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new Exception(curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new Exception("Gemini HTTP $code: " . substr($res, 0, 200));
    $d = json_decode($res, true);
    $txt = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $txt = trim(preg_replace('/```json|```/', '', $txt));
    $arr = json_decode($txt, true);
    if (!is_array($arr)) throw new Exception('Gemini trả sai định dạng');
    return $arr;
}

try {
    switch ($a) {
        case 'list':
            $rows = $pdo->query('SELECT id,w,m,topic,lv,due FROM words ORDER BY id')->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['lv'] = (int)$r['lv']; $r['due'] = (float)$r['due']; }
            $st = $pdo->query("SELECT v FROM kv WHERE k='stats'")->fetchColumn();
            out(['words' => $rows, 'stats' => $st ? json_decode($st, true) : null]);

        case 'add':
            $w = trim($in['w'] ?? ''); $m = trim($in['m'] ?? '');
            if ($w === '' || $m === '') out(['error' => 'Thiếu từ hoặc nghĩa'], 400);
            $pdo->prepare('INSERT INTO words (w,m) VALUES (?,?)')->execute([$w, $m]);
            out(['id' => (int)$pdo->lastInsertId()]);

        case 'del':
            $pdo->prepare('DELETE FROM words WHERE id=?')->execute([(int)($in['id'] ?? 0)]);
            out(['ok' => true]);

        case 'review':
            $id = (int)($in['id'] ?? 0);
            $lv = (int)$pdo->query('SELECT lv FROM words WHERE id=' . $id)->fetchColumn();
            $lv = !empty($in['ok']) ? min(5, $lv + 1) : 0;
            $due = (int)(microtime(true) * 1000) + $GLOBALS['GAP'][$lv] * 86400000;
            $pdo->prepare('UPDATE words SET lv=?, due=? WHERE id=?')->execute([$lv, $due, $id]);
            out(['lv' => $lv, 'due' => $due]);

        case 'stats':
            $pdo->prepare("REPLACE INTO kv (k,v) VALUES ('stats',?)")->execute([json_encode($in, JSON_UNESCAPED_UNICODE)]);
            out(['ok' => true]);

        case 'classify':
            set_time_limit(300);
            if (!empty($in['ids']) && is_array($in['ids'])) {
                $ids = array_map('intval', $in['ids']);
                $q = $pdo->query('SELECT id,w,m FROM words WHERE id IN (' . implode(',', $ids) . ')');
            } elseif (!empty($in['all'])) {
                $q = $pdo->query('SELECT id,w,m FROM words');
            } else {
                $q = $pdo->query("SELECT id,w,m FROM words WHERE topic=''");
            }
            $todo = $q->fetchAll();
            $result = []; $err = null;
            foreach (array_chunk($todo, BATCH) as $batch) {
                $known = DEFAULT_TOPICS;
                foreach ($pdo->query("SELECT DISTINCT topic FROM words WHERE topic<>''")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    if (!in_array(mb_strtolower($t), array_map('mb_strtolower', $known))) $known[] = $t;
                }
                $list = array_map(fn($x) => ['id' => (string)$x['id'], 'word' => $x['w'], 'meaning' => $x['m']], $batch);
                $prompt = "Bạn là trợ lý phân loại từ vựng tiếng Anh cho người Việt học.\n"
                    . "Gán MỖI từ đúng 1 chủ đề (tiếng Việt, ngắn 1-3 từ, viết hoa chữ cái đầu).\n"
                    . "Chủ đề hiện có: " . implode(', ', $known) . ".\n"
                    . "Ưu tiên dùng lại chủ đề hiện có. Nếu không có chủ đề nào hợp lý thì tự tạo chủ đề mới (ví dụ: Du lịch, Cảm xúc, Công nghệ, Gia đình, Công việc, Thiên nhiên, Sức khoẻ). Đừng tạo quá nhiều chủ đề hẹp, hãy gộp từ gần nghĩa.\n"
                    . "Trả về DUY NHẤT mảng JSON [{\"id\":\"...\",\"topic\":\"...\"}], giữ nguyên id, đủ mọi từ.\n"
                    . "Danh sách:\n" . json_encode($list, JSON_UNESCAPED_UNICODE);
                try {
                    $res = gemini($prompt);
                    $up = $pdo->prepare('UPDATE words SET topic=? WHERE id=?');
                    foreach ($res as $r) {
                        if (!empty($r['id']) && !empty($r['topic'])) {
                            $t = mb_substr(trim($r['topic']), 0, 100);
                            $up->execute([$t, (int)$r['id']]);
                            $result[(int)$r['id']] = $t;
                        }
                    }
                } catch (Exception $e) { $err = $e->getMessage(); break; }
            }
            out(['topics' => (object)$result, 'error' => $err, 'total' => count($todo)]);

        default:
            out(['error' => 'Action không hợp lệ'], 400);
    }
} catch (Exception $e) { out(['error' => $e->getMessage()], 500); }
