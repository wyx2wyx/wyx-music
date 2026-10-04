<?php
/**
 * WYX Music Player v7
 * Copyright (c) 2024 WYX
 * Released under the MIT License.
 *
 * 网页访问：index.php
 * 客户端 API：index.php?api=1&action=xxx&token=xxx
 */

define('APP_NAME', 'WYX Music Player');
define('APP_VERSION', '7.0');
define('APP_AUTHOR', 'WYX');
define('APP_LICENSE', 'MIT');
define('DATA_DIR', __DIR__ . '/wyx_data');
define('ALLOWED_EXT', ['mp3','flac','wav','aac','ogg','m4a','wma','ape','opus','aiff','alac']);
define('COVER_MAX_DIM', 500);
define('CHUNK_SIZE', 1048576);
define('AUTH_FILE', DATA_DIR . '/auth.json');
define('SESSION_NAME', 'wyx_sid');
define('API_TOKEN', 'wyx-app-2024-52JD1314');

// ==================== 客户端 API 分支 ====================
if (!empty($_GET['api'])) {
    apiBranch();
    exit;
}

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
session_name(SESSION_NAME);
session_start();

initStorage();

$action = $_GET['action'] ?? '';
if ($action !== '') {
    switch ($action) {
        case 'list':          jsonOut(readData());
        case 'scan':          requireAdmin(); handleScan();
        case 'upload_chunk':  requireAdmin(); handleUploadChunk();
        case 'upload_finish': requireAdmin(); handleUploadFinish();
        case 'upload_abort':  requireAdmin(); handleUploadAbort();
        case 'cover_upload':  requireAdmin(); handleCoverUpload();
        case 'delete':        requireAdmin(); handleDelete();
        case 'download':      handleDownload();
        case 'lyric_get':     handleLyricGet();
        case 'lyric_save':    requireAdmin(); handleLyricSave();
        case 'meta_update':   requireAdmin(); handleMetaUpdate();
        case 'login':         handleLogin();
        case 'logout':        handleLogout();
        case 'whoami':        handleWhoAmI();
        case 'pwd_change':    requireAdmin(); handlePwdChange();
        default:              jsonOut(['ok' => false, 'msg' => '未知操作']);
    }
    exit;
}

renderPage();
exit;


// ==================== 客户端 API ====================

function apiBranch() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-WYX-Token, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    initApiStorage();

    $action = $_GET['action'] ?? '';
    $token  = $_GET['token'] ?? ($_POST['token'] ?? ($_SERVER['HTTP_X_WYX_TOKEN'] ?? ''));

    switch ($action) {
        case 'ping':
            apiOut(['ok' => true, 'name' => APP_NAME, 'version' => APP_VERSION,
                    'license' => APP_LICENSE, 'author' => APP_AUTHOR, 'time' => date('c')]);
        case 'list':          apiOut(['ok' => true, 'songs' => readApiData()]);
        case 'song':          handleApiSong();
        case 'stream':        handleApiStream();
        case 'download':      handleApiDownload();
        case 'lyric':         handleApiLyric();
        case 'scan':          apiRequireToken($token); handleApiScan();
        case 'upload_chunk':  apiRequireToken($token); handleApiUploadChunk();
        case 'upload_finish': apiRequireToken($token); handleApiUploadFinish();
        case 'upload_abort':  apiRequireToken($token); handleApiUploadAbort();
        case 'delete':        apiRequireToken($token); handleApiDelete();
        case 'meta_update':   apiRequireToken($token); handleApiMetaUpdate();
        default:              apiOut(['ok' => false, 'msg' => '未知操作']);
    }
}

function initApiStorage() {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['', '/music', '/covers', '/lyrics', '/tmp'] as $d) {
        $p = DATA_DIR . $d;
        if (!is_dir($p)) @mkdir($p, 0755, true);
    }
    if (!file_exists(DATA_DIR . '/music.json')) @file_put_contents(DATA_DIR . '/music.json', '[]');
}
function apiOut($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function apiRequireToken($t) {
    if ($t !== API_TOKEN) apiOut(['ok' => false, 'msg' => 'token 无效', 'need_auth' => true]);
}
function readApiData() {
    $f = DATA_DIR . '/music.json';
    if (!file_exists($f)) return [];
    $fp = @fopen($f, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($f);
    $c = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}
function writeApiData($d) {
    $f = DATA_DIR . '/music.json';
    $fp = @fopen($f, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function safeApiId($id) { return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$id); }
function apiParseName($name) {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $artist = ''; $title = $base;
    if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $base, $m)) {
        $artist = trim($m[1]); $title = trim($m[2]);
    }
    if ($artist === '') $artist = '未知歌手';
    return ['title' => $title, 'artist' => $artist];
}
function handleApiSong() {
    $id = safeApiId($_GET['id'] ?? '');
    foreach (readApiData() as $s) {
        if ($s['id'] === $id) {
            $out = $s;
            $out['stream_url']   = 'index.php?api=1&action=stream&id=' . urlencode($id);
            $out['download_url'] = 'index.php?api=1&action=download&id=' . urlencode($id);
            apiOut(['ok' => true, 'song' => $out]);
        }
    }
    apiOut(['ok' => false, 'msg' => '未找到']);
}
function handleApiStream() {
    $id = safeApiId($_GET['id'] ?? '');
    foreach (readApiData() as $s) {
        if ($s['id'] === $id) {
            $abs = __DIR__ . '/' . $s['file'];
            if (!file_exists($abs)) { http_response_code(404); exit; }
            $ext = pathinfo($s['file'], PATHINFO_EXTENSION);
            $mime = 'audio/mpeg';
            if ($ext === 'flac') $mime = 'audio/flac';
            if ($ext === 'wav')  $mime = 'audio/wav';
            if ($ext === 'ogg' || $ext === 'opus') $mime = 'audio/ogg';
            if ($ext === 'm4a' || $ext === 'aac')   $mime = 'audio/mp4';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($abs));
            header('Accept-Ranges: bytes');
            readfile($abs); exit;
        }
    }
    http_response_code(404);
}
function handleApiDownload() {
    $id = safeApiId($_GET['id'] ?? '');
    foreach (readApiData() as $s) {
        if ($s['id'] === $id) {
            $abs = __DIR__ . '/' . $s['file'];
            if (!file_exists($abs)) { http_response_code(404); exit; }
            $ext = pathinfo($s['file'], PATHINFO_EXTENSION);
            $rawName = $s['artist'] . ' - ' . $s['title'] . '.' . $ext;
            $rfc5987 = rawurlencode($rawName);
            $fallback = preg_replace('/[^\x20-\x7E]/', '_', $rawName);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . $rfc5987);
            header('Content-Length: ' . filesize($abs));
            readfile($abs); exit;
        }
    }
    http_response_code(404);
}
function handleApiLyric() {
    $id = safeApiId($_GET['id'] ?? '');
    foreach (readApiData() as $s) {
        if ($s['id'] === $id) {
            $text = '';
            if (!empty($s['lyric']) && file_exists(__DIR__ . '/' . $s['lyric'])) {
                $text = file_get_contents(__DIR__ . '/' . $s['lyric']);
            }
            apiOut(['ok' => true, 'text' => $text]);
        }
    }
    apiOut(['ok' => false, 'msg' => '未找到']);
}
function handleApiScan() {
    $list = readApiData();
    $known = [];
    foreach ($list as $s) $known[$s['file']] = true;
    $added = 0;
    $musicDir = DATA_DIR . '/music';
    if (is_dir($musicDir)) {
        foreach (scandir($musicDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $abs = $musicDir . '/' . $f;
            if (!is_file($abs)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXT, true)) continue;
            $rel = 'wyx_data/music/' . $f;
            if (!empty($known[$rel])) continue;
            $info = apiParseName($f);
            $id = str_replace('.', '', uniqid('wyx_', true));
            $list[] = [
                'id' => $id, 'title' => $info['title'], 'artist' => $info['artist'],
                'album' => '', 'file' => $rel, 'cover' => null, 'lyric' => null,
                'size' => filesize($abs),
                'time' => date('Y-m-d H:i:s', filemtime($abs) ?: time()),
                'auto' => true,
            ];
            $known[$rel] = true; $added++;
        }
    }
    if ($added > 0) writeApiData($list);
    apiOut(['ok' => true, 'added' => $added, 'total' => count($list)]);
}
function handleApiUploadChunk() {
    $uploadId = safeApiId($_POST['uploadId'] ?? '');
    $index = (int)($_POST['index'] ?? -1);
    if ($uploadId === '' || $index < 0) apiOut(['ok' => false, 'msg' => '参数缺失']);
    if (empty($_FILES['chunk'])) apiOut(['ok' => false, 'msg' => '无分片']);
    $dir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $target = $dir . '/' . str_pad($index, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $target)) apiOut(['ok' => false, 'msg' => '保存失败']);
    apiOut(['ok' => true, 'index' => $index]);
}
function handleApiUploadFinish() {
    $uploadId = safeApiId($_POST['uploadId'] ?? '');
    $name = $_POST['name'] ?? '';
    $metaJson = $_POST['meta'] ?? '{}';
    if ($uploadId === '' || $name === '') apiOut(['ok' => false, 'msg' => '参数缺失']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) apiOut(['ok' => false, 'msg' => '不支持的格式']);
    $dir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($dir)) apiOut(['ok' => false, 'msg' => '分片不存在']);
    $parts = [];
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..' && is_file($dir . '/' . $f)) $parts[] = $dir . '/' . $f;
    }
    sort($parts);
    $id = str_replace('.', '', uniqid('wyx_', true));
    $safeBase = preg_replace('/[^\p{Han}\p{L}\p{N}\-_. ]/u', '_', pathinfo($name, PATHINFO_FILENAME));
    if (trim($safeBase) === '') $safeBase = 'audio';
    $store = $id . '_' . $safeBase . '.' . $ext;
    $audioRel = "wyx_data/music/{$store}";
    $abs = __DIR__ . '/' . $audioRel;
    $out = @fopen($abs, 'wb');
    if (!$out) apiOut(['ok' => false, 'msg' => '无法创建文件']);
    foreach ($parts as $p) {
        $in = @fopen($p, 'rb');
        if ($in) { while (!feof($in)) fwrite($out, fread($in, 1048576)); fclose($in); }
    }
    fclose($out);
    foreach (scandir($dir) as $f) if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
    @rmdir($dir);
    $meta = json_decode($metaJson, true);
    if (!is_array($meta)) $meta = [];
    $lyricRel = null;
    if (!empty($meta['lyrics'])) {
        $lyricRel = "wyx_data/lyrics/{$id}.lrc";
        @file_put_contents(__DIR__ . '/' . $lyricRel, $meta['lyrics']);
    }
    $title = trim($meta['title'] ?? '');
    $artist = trim($meta['artist'] ?? '');
    if ($title === '') {
        $info = apiParseName($name);
        $title = $info['title'];
        if ($artist === '') $artist = $info['artist'];
    }
    if ($artist === '') $artist = '未知歌手';
    $list = readApiData();
    $item = [
        'id' => $id, 'title' => $title, 'artist' => $artist,
        'album' => trim($meta['album'] ?? ''),
        'file' => $audioRel, 'cover' => null, 'lyric' => $lyricRel,
        'size' => filesize($abs), 'time' => date('Y-m-d H:i:s'),
    ];
    $list[] = $item;
    writeApiData($list);
    apiOut(['ok' => true, 'id' => $id, 'song' => $item]);
}
function handleApiUploadAbort() {
    $uploadId = safeApiId($_POST['uploadId'] ?? '');
    if ($uploadId !== '') {
        $dir = DATA_DIR . '/tmp/' . $uploadId;
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
            @rmdir($dir);
        }
    }
    apiOut(['ok' => true]);
}
function handleApiDelete() {
    $id = safeApiId($_POST['id'] ?? '');
    if ($id === '') apiOut(['ok' => false, 'msg' => '缺少 id']);
    $list = readApiData();
    $found = false;
    foreach ($list as $k => $s) {
        if ($s['id'] === $id) {
            if (!empty($s['file'])  && strpos($s['file'], 'wyx_data/music/') === 0)  @unlink(__DIR__ . '/' . $s['file']);
            if (!empty($s['cover']) && strpos($s['cover'], 'wyx_data/covers/') === 0) @unlink(__DIR__ . '/' . $s['cover']);
            if (!empty($s['lyric']) && strpos($s['lyric'], 'wyx_data/lyrics/') === 0) @unlink(__DIR__ . '/' . $s['lyric']);
            unset($list[$k]); $found = true; break;
        }
    }
    if ($found) writeApiData(array_values($list));
    apiOut(['ok' => $found]);
}
function handleApiMetaUpdate() {
    $id = safeApiId($_POST['id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    if ($id === '') apiOut(['ok' => false, 'msg' => '缺少 id']);
    $list = readApiData();
    $found = false;
    foreach ($list as &$s) {
        if ($s['id'] === $id) {
            if ($title  !== '') $s['title']  = $title;
            if ($artist !== '') $s['artist'] = $artist;
            $found = true; break;
        }
    }
    unset($s);
    if (!$found) apiOut(['ok' => false, 'msg' => '未找到']);
    writeApiData($list);
    apiOut(['ok' => true]);
}


// ==================== 主站逻辑 ====================

function initStorage() {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['', '/music', '/covers', '/lyrics', '/tmp'] as $d) {
        $p = DATA_DIR . $d;
        if (!is_dir($p)) @mkdir($p, 0755, true);
    }
    if (!file_exists(DATA_DIR . '/music.json')) @file_put_contents(DATA_DIR . '/music.json', '[]');
    if (!file_exists(AUTH_FILE)) {
        @file_put_contents(AUTH_FILE, json_encode([
            'user' => 'wyx',
            'pwd'  => password_hash('52JD1314', PASSWORD_DEFAULT),
        ], JSON_UNESCAPED_UNICODE));
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Options -Indexes\n");
}
function readAuth() {
    if (!file_exists(AUTH_FILE)) initStorage();
    $d = json_decode(@file_get_contents(AUTH_FILE), true);
    if (!is_array($d)) $d = [];
    if (empty($d['user'])) $d['user'] = 'wyx';
    return $d;
}
function writeAuth($d) { @file_put_contents(AUTH_FILE, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }
function isAdmin() { return !empty($_SESSION['wyx_admin']); }
function requireAdmin() {
    if (!isAdmin()) jsonOut(['ok' => false, 'msg' => '需要管理员登录', 'need_login' => true]);
}
function handleLogin() {
    $user = trim($_POST['user'] ?? '');
    $pwd  = (string)($_POST['pwd'] ?? '');
    $auth = readAuth();
    if ($user !== $auth['user'] || !password_verify($pwd, $auth['pwd'])) {
        jsonOut(['ok' => false, 'msg' => '用户名或密码错误']);
    }
    $_SESSION['wyx_admin'] = true;
    jsonOut(['ok' => true, 'user' => $auth['user']]);
}
function handleLogout() {
    $_SESSION['wyx_admin'] = false;
    session_destroy();
    jsonOut(['ok' => true]);
}
function handleWhoAmI() { jsonOut(['ok' => true, 'admin' => isAdmin()]); }
function handlePwdChange() {
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (strlen($new) < 6) jsonOut(['ok' => false, 'msg' => '新密码至少 6 位']);
    $auth = readAuth();
    if (!password_verify($old, $auth['pwd'])) jsonOut(['ok' => false, 'msg' => '原密码错误']);
    $auth['pwd'] = password_hash($new, PASSWORD_DEFAULT);
    writeAuth($auth);
    jsonOut(['ok' => true]);
}
function readData() {
    $f = DATA_DIR . '/music.json';
    if (!file_exists($f)) return [];
    $fp = @fopen($f, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($f);
    $c = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}
function writeData($d) {
    $f = DATA_DIR . '/music.json';
    $fp = @fopen($f, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    fwrite($f, $d);
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function jsonOut($d) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function toBytes($v) {
    $v = trim((string)$v);
    if ($v === '') return 0;
    $unit = strtolower(substr($v, -1));
    $num = (int)$v;
    switch ($unit) {
        case 'g': return $num * 1073741824;
        case 'm': return $num * 1048576;
        case 'k': return $num * 1024;
        default:  return (int)$v;
    }
}
function getUploadLimit() {
    $u = toBytes(ini_get('upload_max_filesize'));
    $p = toBytes(ini_get('post_max_size'));
    $u = $u > 0 ? $u : 134217728;
    $p = $p > 0 ? $p : 134217728;
    return min($u, $p);
}
function safeId($id) { return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$id); }
function parseFilename($name) {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $artist = ''; $title = $base;
    if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $base, $m)) {
        $artist = trim($m[1]); $title = trim($m[2]);
    }
    if ($artist === '') $artist = '未知歌手';
    return ['title' => $title, 'artist' => $artist];
}
function handleScan() {
    $list = readData();
    $known = [];
    foreach ($list as $s) $known[$s['file']] = true;
    $added = 0;
    $musicDir = DATA_DIR . '/music';
    if (!is_dir($musicDir)) jsonOut(['ok' => true, 'added' => 0]);
    foreach (scandir($musicDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $abs = $musicDir . '/' . $f;
        if (!is_file($abs)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) continue;
        $rel = 'wyx_data/music/' . $f;
        if (!empty($known[$rel])) continue;
        $info = parseFilename($f);
        $id = str_replace('.', '', uniqid('wyx_', true));
        $list[] = [
            'id' => $id, 'title' => $info['title'], 'artist' => $info['artist'],
            'album' => '', 'file' => $rel, 'cover' => null, 'lyric' => null,
            'size' => filesize($abs),
            'time' => date('Y-m-d H:i:s', filemtime($abs) ?: time()),
            'auto' => true,
        ];
        $known[$rel] = true; $added++;
    }
    if ($added > 0) writeData(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => true, 'added' => $added, 'total' => count($list)]);
}
function handleUploadChunk() {
    $uploadId = safeId($_POST['uploadId'] ?? '');
    $index    = (int)($_POST['index'] ?? -1);
    if ($uploadId === '' || $index < 0) jsonOut(['ok' => false, 'msg' => '参数缺失']);
    if (empty($_FILES['chunk'])) jsonOut(['ok' => false, 'msg' => '没有收到分片']);
    $chunk = $_FILES['chunk'];
    if ($chunk['error'] !== UPLOAD_ERR_OK) jsonOut(['ok' => false, 'msg' => '分片错误 ' . $chunk['error']]);
    $dir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $target = $dir . '/' . str_pad($index, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($chunk['tmp_name'], $target)) jsonOut(['ok' => false, 'msg' => '保存分片失败']);
    jsonOut(['ok' => true, 'index' => $index]);
}
function handleUploadFinish() {
    $uploadId = safeId($_POST['uploadId'] ?? '');
    $name     = $_POST['name'] ?? '';
    $metaJson = $_POST['meta'] ?? '{}';
    if ($uploadId === '' || $name === '') jsonOut(['ok' => false, 'msg' => '参数缺失']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) { cleanTmp($uploadId); jsonOut(['ok' => false, 'msg' => '不支持的格式']); }
    $dir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($dir)) jsonOut(['ok' => false, 'msg' => '分片目录不存在']);
    $parts = [];
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..' && is_file($dir . '/' . $f)) $parts[] = $dir . '/' . $f;
    }
    if (count($parts) === 0) { cleanTmp($uploadId); jsonOut(['ok' => false, 'msg' => '没有分片']); }
    sort($parts);
    $id = str_replace('.', '', uniqid('wyx_', true));
    $safeBase = preg_replace('/[^\p{Han}\p{L}\p{N}\-_. ]/u', '_', pathinfo($name, PATHINFO_FILENAME));
    $safeBase = trim($safeBase);
    if ($safeBase === '') $safeBase = 'audio';
    if (mb_strlen($safeBase, 'UTF-8') > 80) $safeBase = mb_substr($safeBase, 0, 80, 'UTF-8');
    $storeName = $id . '_' . $safeBase . '.' . $ext;
    $audioRel = "wyx_data/music/{$storeName}";
    $audioAbs = __DIR__ . '/' . $audioRel;
    $out = @fopen($audioAbs, 'wb');
    if (!$out) { cleanTmp($uploadId); jsonOut(['ok' => false, 'msg' => '无法创建文件']); }
    foreach ($parts as $p) {
        $in = @fopen($p, 'rb');
        if ($in) { while (!feof($in)) fwrite($out, fread($in, 1048576)); fclose($in); }
    }
    fclose($out);
    $size = filesize($audioAbs);
    cleanTmp($uploadId);
    if ($size === 0) { @unlink($audioAbs); jsonOut(['ok' => false, 'msg' => '合并后为空']); }
    $meta = json_decode($metaJson, true);
    if (!is_array($meta)) $meta = [];
    $lyricRel = null;
    if (!empty($meta['lyrics'])) {
        $lyricRel = "wyx_data/lyrics/{$id}.lrc";
        if (@file_put_contents(__DIR__ . '/' . $lyricRel, $meta['lyrics']) === false) $lyricRel = null;
    }
    $title  = trim($meta['title']  ?? '');
    $artist = trim($meta['artist'] ?? '');
    if ($title === '') {
        $info = parseFilename($name);
        $title = $info['title'];
        if ($artist === '') $artist = $info['artist'];
    }
    if ($artist === '') $artist = '未知歌手';
    $list = readData();
    $item = [
        'id' => $id, 'title' => $title, 'artist' => $artist,
        'album' => trim($meta['album'] ?? ''),
        'file' => $audioRel, 'cover' => null, 'lyric' => $lyricRel,
        'size' => $size, 'time' => date('Y-m-d H:i:s'),
    ];
    $list[] = $item;
    writeData(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => true, 'id' => $id, 'item' => $item]);
}
function handleUploadAbort() {
    $uploadId = safeId($_POST['uploadId'] ?? '');
    if ($uploadId !== '') cleanTmp($uploadId);
    jsonOut(['ok' => true]);
}
function cleanTmp($uploadId) {
    $dir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) if ($f !== '.' && $f !== '..') @unlink($dir . '/' . $f);
    @rmdir($dir);
}
function handleCoverUpload() {
    $id = safeId($_POST['id'] ?? '');
    $data = $_POST['cover'] ?? '';
    if ($id === '' || $data === '') jsonOut(['ok' => false, 'msg' => '参数缺失']);
    if (!preg_match('#^data:image/(\w+);base64,(.+)$#s', $data, $m)) jsonOut(['ok' => false, 'msg' => '格式错误']);
    $imgExt = strtolower($m[1]);
    if ($imgExt === 'jpeg') $imgExt = 'jpg';
    if (!in_array($imgExt, ['jpg','png','gif','webp','bmp'], true)) jsonOut(['ok' => false, 'msg' => '不支持的图片']);
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 16) jsonOut(['ok' => false, 'msg' => '数据损坏']);
    $rel = "wyx_data/covers/{$id}.{$imgExt}";
    if (@file_put_contents(__DIR__ . '/' . $rel, $bin) === false) jsonOut(['ok' => false, 'msg' => '保存失败']);
    $list = readData();
    $found = false;
    foreach ($list as &$s) { if ($s['id'] === $id) { $s['cover'] = $rel; $found = true; break; } }
    unset($s);
    if (!$found) jsonOut(['ok' => false, 'msg' => '未找到歌曲']);
    writeData(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => true, 'cover' => $rel]);
}
function handleDelete() {
    $id = safeId($_POST['id'] ?? '');
    if ($id === '') jsonOut(['ok' => false, 'msg' => '缺少 id']);
    $list = readData();
    $found = false;
    foreach ($list as $k => $s) {
        if ($s['id'] === $id) {
            if (!empty($s['file'])  && strpos($s['file'], 'wyx_data/music/') === 0)  @unlink(__DIR__ . '/' . $s['file']);
            if (!empty($s['cover']) && strpos($s['cover'], 'wyx_data/covers/') === 0) @unlink(__DIR__ . '/' . $s['cover']);
            if (!empty($s['lyric']) && strpos($s['lyric'], 'wyx_data/lyrics/') === 0) @unlink(__DIR__ . '/' . $s['lyric']);
            unset($list[$k]); $found = true; break;
        }
    }
    if ($found) writeData(json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => $found]);
}
function handleDownload() {
    $id = safeId($_GET['id'] ?? '');
    if ($id === '') { http_response_code(400); exit('缺少 id'); }
    foreach (readData() as $s) {
        if ($s['id'] === $id) {
            $abs = __DIR__ . '/' . $s['file'];
            if (!file_exists($abs)) { http_response_code(404); exit('文件不存在'); }
            $ext = pathinfo($s['file'], PATHINFO_EXTENSION);
            $rawName = $s['artist'] . ' - ' . $s['title'] . '.' . $ext;
            $rawName = preg_replace('/[\x00-\x1F\x7F]/u', '', $rawName);
            $rfc5987 = rawurlencode($rawName);
            $fallback = preg_replace('/[^\x20-\x7E]/', '_', $rawName);
            if ($fallback === '') $fallback = 'music.' . $ext;
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . $rfc5987);
            header('Content-Length: ' . filesize($abs));
            header('Cache-Control: no-cache');
            readfile($abs); exit;
        }
    }
    http_response_code(404); exit('未找到');
}
function handleLyricGet() {
    $id = safeId($_GET['id'] ?? '');
    foreach (readData() as $s) {
        if ($s['id'] === $id) {
            $text = '';
            if (!empty($s['lyric']) && file_exists(__DIR__ . '/' . $s['lyric'])) {
                $text = file_get_contents(__DIR__ . '/' . $s['lyric']);
            }
            jsonOut(['ok' => true, 'text' => $text]);
        }
    }
    jsonOut(['ok' => false, 'msg' => '未找到']);
}
function handleLyricSave() {
    $id = safeId($_POST['id'] ?? '');
    $text = $_POST['text'] ?? '';
    if ($id === '') jsonOut(['ok' => false, 'msg' => '缺少 id']);
    $list = readData();
    $found = false;
    foreach ($list as &$s) {
        if ($s['id'] === $id) {
            $found = true;
            if ($text === '') {
                if (!empty($s['lyric']) && strpos($s['lyric'], 'wyx_data/lyrics/') === 0) @unlink(__DIR__ . '/' . $s['lyric']);
                $s['lyric'] = null;
            } else {
                $rel = "wyx_data/lyrics/{$id}.lrc";
                if (@file_put_contents(__DIR__ . '/' . $rel, $text) === false) {
                    unset($s); jsonOut(['ok' => false, 'msg' => '写入失败']);
                }
                $s['lyric'] = $rel;
            }
            break;
        }
    }
    unset($s);
    if (!$found) jsonOut(['ok' => false, 'msg' => '未找到']);
    writeData(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => true]);
}
function handleMetaUpdate() {
    $id     = safeId($_POST['id'] ?? '');
    $title  = trim($_POST['title']  ?? '');
    $artist = trim($_POST['artist'] ?? '');
    if ($id === '') jsonOut(['ok' => false, 'msg' => '缺少 id']);
    $list = readData();
    $found = false;
    foreach ($list as &$s) {
        if ($s['id'] === $id) {
            if ($title  !== '') $s['title']  = $title;
            if ($artist !== '') $s['artist'] = $artist;
            $found = true; break;
        }
    }
    unset($s);
    if (!$found) jsonOut(['ok' => false, 'msg' => '未找到']);
    writeData(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    jsonOut(['ok' => true]);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function renderPage() {
    $maxUpload   = getUploadLimit();
    $maxUploadMb = round($maxUpload / 1048576, 1);
    $coverMaxDim = COVER_MAX_DIM;
    $chunkSize   = CHUNK_SIZE;
    $appName     = APP_NAME;
    $appVersion  = APP_VERSION;
    $appAuthor   = APP_AUTHOR;
    $appLicense  = APP_LICENSE;
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f0c29">
<title><?= h($appName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aplayer@1.10.1/dist/APlayer.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Pacifico&display=swap');
* { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
:root {
    --bg1:#0f0c29; --bg2:#302b63; --bg3:#24243e;
    --accent:#667eea; --accent2:#a78bfa;
    --text:#e8e8f0; --text-dim:#9a9ab0;
    --panel:rgba(255,255,255,0.06);
    --panel-border:rgba(255,255,255,0.1);
    --hover:rgba(255,255,255,0.1);
    --safe-bottom: env(safe-area-inset-bottom, 0px);
}
html { background:#0f0c29; min-height:100%; height:100%; }
body {
    font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: linear-gradient(135deg, var(--bg1), var(--bg2), var(--bg3));
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    min-height: 100dvh;
    padding-bottom: calc(40px + var(--safe-bottom));
    position: relative;
    overflow-x: hidden;
}
body::before {
    content:''; position:fixed; inset:-20%;
    background: linear-gradient(135deg, var(--bg1), var(--bg2), var(--bg3));
    z-index:-1; pointer-events:none;
}
.header {
    padding:20px 24px 14px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px;
    border-bottom:1px solid var(--panel-border);
    position:relative; z-index:5;
}
.logo {
    font-size:24px; font-weight:700; letter-spacing:2px;
    background: linear-gradient(90deg, #fff, var(--accent2), var(--accent));
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent;
}
.logo-sub { font-size:11px; color:var(--text-dim); margin-top:2px; letter-spacing:1px; }
.header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.search-box {
    background:var(--panel); border:1px solid var(--panel-border);
    border-radius:10px; padding:8px 14px; color:var(--text);
    font-size:14px; width:180px; outline:none; transition:0.2s;
}
.search-box:focus { border-color:var(--accent); background:rgba(102,126,234,0.1); }
.search-box::placeholder { color:var(--text-dim); }
.btn {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border:none; color:#fff; padding:9px 16px; border-radius:10px;
    font-size:14px; font-weight:500; cursor:pointer;
    transition:0.2s; display:inline-flex; align-items:center; gap:6px;
    white-space:nowrap;
}
.btn:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(102,126,234,0.4); }
.btn:active { transform:translateY(0); }
.btn.secondary { background:var(--panel); border:1px solid var(--panel-border); }
.btn.secondary:hover { background:var(--hover); box-shadow:none; }
.user-chip {
    display:inline-flex; align-items:center; gap:6px;
    background:var(--panel); border:1px solid var(--panel-border);
    padding:7px 12px; border-radius:10px; font-size:13px;
    color:var(--text-dim); cursor:pointer; transition:0.2s;
    user-select:none;
}
.user-chip:hover { background:var(--hover); }
.user-chip .dot { width:7px; height:7px; border-radius:50%; background:#9a9ab0; }
.user-chip.admin .dot { background:#10b981; }
.container {
    max-width:1280px; margin:0 auto; padding:24px 20px;
    display:grid; grid-template-columns:380px 1fr; gap:20px; align-items:start;
    position:relative; z-index:1;
}
@media (max-width:900px) { .container { grid-template-columns:1fr; } }
.player-card {
    background:var(--panel); border:1px solid var(--panel-border);
    border-radius:18px; padding:16px; backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    position:sticky; top:16px;
}
@media (max-width:900px) { .player-card { position:static; } }
.player-card .aplayer { background:transparent !important; box-shadow:none !important; margin:0 !important; color:var(--text) !important; }
.player-card .aplayer .aplayer-info .aplayer-music .aplayer-title,
.player-card .aplayer .aplayer-info .aplayer-music .aplayer-author { color:var(--text) !important; }
.player-card .aplayer .aplayer-info .aplayer-controller .aplayer-time { color:var(--text-dim) !important; }
.player-card .aplayer .aplayer-list { max-height:380px; background:transparent !important; }
.player-card .aplayer .aplayer-list ol li { border-top:1px solid var(--panel-border) !important; color:var(--text) !important; }
.player-card .aplayer .aplayer-list ol li:hover { background:var(--hover) !important; }
.player-card .aplayer .aplayer-list ol li.aplayer-list-light { background:rgba(102,126,234,0.15) !important; }
.player-card .aplayer .aplayer-list ol li .aplayer-list-index,
.player-card .aplayer .aplayer-list ol li .aplayer-list-author { color:var(--text-dim) !important; }
.player-card .aplayer .aplayer-lrc:before,
.player-card .aplayer .aplayer-lrc:after { background: linear-gradient(to bottom, var(--bg2) 0, rgba(48,43,99,0) 100%) !important; }
.player-card .aplayer .aplayer-lrc p { color:var(--text-dim) !important; text-shadow:none !important; }
.player-card .aplayer .aplayer-lrc p.aplayer-lrc-current { color:var(--accent2) !important; text-shadow:0 0 8px rgba(167,139,250,0.5) !important; }
.list-card {
    background:var(--panel); border:1px solid var(--panel-border);
    border-radius:18px; backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px);
    overflow:hidden;
}
.list-header {
    padding:16px 20px; border-bottom:1px solid var(--panel-border);
    display:flex; justify-content:space-between; align-items:center;
    font-size:14px; color:var(--text-dim); gap:10px; flex-wrap:wrap;
}
.list-header strong { color:var(--text); font-weight:600; }
.song-list { list-style:none; }
.song-item {
    display:grid; grid-template-columns:52px 1fr auto;
    gap:12px; padding:12px 20px; align-items:center;
    border-bottom:1px solid var(--panel-border);
    transition:0.15s; cursor:pointer;
}
.song-item:last-child { border-bottom:none; }
.song-item:hover { background:var(--hover); }
.song-item.playing { background:rgba(102,126,234,0.15); }
.song-cover {
    width:52px; height:52px; border-radius:10px; object-fit:cover;
    background: linear-gradient(135deg, #4c1d95, #1e3a8a);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:20px; font-weight:700;
    overflow:hidden; flex-shrink:0;
}
.song-cover img { width:100%; height:100%; object-fit:cover; }
.song-info { min-width:0; }
.song-title { font-size:15px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.song-meta { font-size:12.5px; color:var(--text-dim); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.song-actions { display:flex; gap:6px; opacity:0.4; transition:0.15s; }
.song-item:hover .song-actions { opacity:1; }
@media (max-width:900px) { .song-actions { opacity:1; } }
.icon-btn {
    background:var(--panel); border:1px solid var(--panel-border);
    color:var(--text); width:34px; height:34px; border-radius:8px;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:0.15s; text-decoration:none; padding:0;
}
.icon-btn:hover { background:var(--accent); border-color:var(--accent); color:#fff; }
.icon-btn.danger:hover { background:#ef4444; border-color:#ef4444; }
.icon-btn svg { width:16px; height:16px; }
.empty { padding:60px 20px; text-align:center; color:var(--text-dim); }
.empty-title { font-size:17px; color:var(--text); margin-bottom:8px; }
.empty-sub { font-size:13px; }
.upload-bar {
    position:fixed; left:50%; bottom:calc(16px + var(--safe-bottom));
    transform:translateX(-50%) translateY(20px);
    background:rgba(20,20,40,0.97); border:1px solid var(--panel-border);
    border-radius:14px; padding:14px 18px;
    width:min(420px, calc(100vw - 32px));
    backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px);
    box-shadow:0 10px 40px rgba(0,0,0,0.6);
    z-index:999; opacity:0; pointer-events:none; transition:0.25s;
}
.upload-bar.show { opacity:1; pointer-events:auto; transform:translateX(-50%) translateY(0); }
.upload-title { font-size:12px; color:var(--accent2); margin-bottom:6px; font-weight:600; letter-spacing:0.5px; }
.upload-file { font-size:13px; margin-bottom:10px; color:var(--text); word-break:break-all; line-height:1.4; }
.progress-row { display:flex; align-items:center; gap:10px; }
.progress-track { flex:1; height:8px; background:rgba(255,255,255,0.1); border-radius:4px; overflow:hidden; }
.progress-fill { height:100%; width:0%; background: linear-gradient(90deg, var(--accent), var(--accent2)); border-radius:4px; transition:width 0.15s; }
.progress-pct { font-size:12px; color:var(--text-dim); font-variant-numeric:tabular-nums; min-width:42px; text-align:right; }
.upload-stats { font-size:12px; color:var(--text-dim); margin-top:8px; }
.upload-cancel { margin-top:10px; text-align:right; }
.upload-cancel button { background:transparent; border:1px solid var(--panel-border); color:var(--text-dim); padding:5px 12px; border-radius:8px; font-size:12px; cursor:pointer; }
.upload-cancel button:hover { background:rgba(239,68,68,0.15); color:#ef4444; border-color:#ef4444; }
.toast-wrap {
    position:fixed; top:20px; left:50%; transform:translateX(-50%);
    z-index:2000; display:flex; flex-direction:column; gap:8px;
    pointer-events:none; max-width:calc(100vw - 32px);
}
.toast {
    background:rgba(20,20,40,0.97); border:1px solid var(--panel-border);
    border-left:3px solid var(--accent); color:var(--text);
    padding:12px 18px; border-radius:10px; font-size:13.5px;
    box-shadow:0 8px 30px rgba(0,0,0,0.5); backdrop-filter:blur(20px);
    max-width:420px; word-break:break-all;
    opacity:0; transform:translateY(-10px); transition:0.25s;
}
.toast.show { opacity:1; transform:translateY(0); }
.toast.success { border-left-color:#10b981; }
.toast.error { border-left-color:#ef4444; }
.modal-mask {
    position:fixed; inset:0; background:rgba(0,0,0,0.75);
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    display:none; align-items:center; justify-content:center;
    z-index:1000; padding:20px;
}
.modal-mask.show { display:flex; }
.modal {
    background: linear-gradient(135deg, #1a1a2e, #24243e);
    border:1px solid var(--panel-border); border-radius:18px;
    padding:22px; width:100%; max-width:480px; max-height:85vh;
    overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.6);
}
.modal h3 { font-size:18px; margin-bottom:6px; font-weight:600; }
.modal .sub { font-size:13px; color:var(--text-dim); margin-bottom:14px; word-break:break-all; }
.modal textarea, .modal input {
    width:100%; background:var(--panel); border:1px solid var(--panel-border);
    border-radius:10px; padding:12px 14px; color:var(--text);
    font-size:14px; font-family:inherit; outline:none;
    transition:0.2s; margin-bottom:12px;
}
.modal textarea { min-height:200px; resize:vertical; font-family:"SF Mono", Consolas, monospace; font-size:13px; line-height:1.7; }
.modal textarea:focus, .modal input:focus { border-color:var(--accent); }
.modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:6px; }
.about-box { text-align:center; padding:12px 8px 8px; }
.about-icon {
    width:72px; height:72px; margin:0 auto 18px; border-radius:20px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 10px 40px rgba(102,126,234,0.5);
}
.about-icon svg { width:40px; height:40px; color:#fff; }
.about-name {
    font-size:24px; font-weight:700; letter-spacing:1px;
    background: linear-gradient(90deg, #fff, var(--accent2), var(--accent));
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent;
    margin-bottom:6px;
}
.about-tagline {
    font-size:12px; color:var(--text-dim);
    letter-spacing:2px; margin-bottom:26px; text-transform:uppercase;
}
.about-made {
    font-family:'Pacifico', 'Brush Script MT', cursive;
    font-size:22px; color:var(--accent2);
    margin:24px 0 18px; letter-spacing:0.5px; line-height:1.4;
}
.about-made span {
    font-family:'Pacifico', 'Brush Script MT', cursive;
    background: linear-gradient(90deg, var(--accent2), #f472b6, #60a5fa);
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent;
    font-size:26px;
}
.about-divider { height:1px; background:var(--panel-border); margin:18px 0; }
.about-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:10px 0; font-size:13.5px; color:var(--text-dim); text-align:left;
}
.about-row .label { color:var(--text); }
.about-row .value { color:var(--text-dim); font-size:13px; }
.license-box {
    font-size:11.5px; color:var(--text-dim); text-align:left;
    background:rgba(255,255,255,0.03); padding:12px 14px; border-radius:8px;
    line-height:1.6; margin-top:8px;
}
.license-box strong { color:var(--text); }
.switch { position:relative; display:inline-block; width:44px; height:24px; }
.switch input { opacity:0; width:0; height:0; }
.switch .slider { position:absolute; cursor:pointer; inset:0; background:rgba(255,255,255,0.15); border-radius:24px; transition:0.3s; }
.switch .slider:before { content:''; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:0.3s; }
.switch input:checked + .slider { background: linear-gradient(135deg, var(--accent), var(--accent2)); }
.switch input:checked + .slider:before { transform:translateX(20px); }
.notify-state {
    font-size:12px; color:var(--text-dim);
    padding:8px 12px; background:rgba(255,255,255,0.04);
    border-radius:8px; margin-top:8px; line-height:1.6; text-align:left;
}
.notify-state.ok { color:#10b981; }
.notify-state.err { color:#ef4444; }
.mediasession-state {
    font-size:12px; color:var(--text-dim);
    padding:8px 12px; background:rgba(255,255,255,0.04);
    border-radius:8px; margin-top:6px; line-height:1.6; text-align:left;
}
.mediasession-state.ok { color:#10b981; }
.site-footer {
    text-align:center; padding:20px; font-size:12px;
    color:var(--text-dim); position:relative; z-index:1;
}
.site-footer a { color:var(--accent2); text-decoration:none; }
@media (max-width:600px) {
    .header { padding:16px 14px 12px; }
    .container { padding:14px; gap:14px; }
    .search-box { width:100%; flex:1 1 auto; min-width:0; }
    .header-actions { width:100%; }
    .header-actions .btn { flex:1 1 auto; justify-content:center; }
    .song-item { padding:10px 14px; grid-template-columns:46px 1fr auto; gap:10px; }
    .song-cover { width:46px; height:46px; }
    .icon-btn { width:32px; height:32px; }
    .icon-btn svg { width:15px; height:15px; }
    .about-name { font-size:21px; }
    .about-made { font-size:19px; }
    .about-made span { font-size:23px; }
}
::-webkit-scrollbar { width:8px; height:8px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.15); border-radius:4px; }
::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.25); }
</style>
</head>
<body>

<div class="header">
    <div>
        <div class="logo"><?= h($appName) ?></div>
        <div class="logo-sub">PERSONAL MUSIC LIBRARY</div>
    </div>
    <div class="header-actions">
        <input type="text" class="search-box" id="searchInput" placeholder="搜索歌曲、歌手...">
        <button class="btn secondary" id="aboutBtn" title="关于">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            关于
        </button>
        <div class="user-chip" id="userChip">
            <span class="dot"></span>
            <span id="userLabel">访客</span>
        </div>
        <button class="btn" id="uploadBtn" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            上传
        </button>
        <button class="btn secondary" id="scanBtn" style="display:none;" title="扫描 music 文件夹">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"/></svg>
            扫描
        </button>
    </div>
</div>

<div class="container">
    <div class="player-card">
        <div id="player"></div>
    </div>
    <div class="list-card">
        <div class="list-header">
            <span><strong id="songCount">0</strong> 首歌曲</span>
            <span id="listHint">点击播放</span>
        </div>
        <ul class="song-list" id="songList"></ul>
        <div class="empty" id="emptyState">
            <div class="empty-title">音乐库是空的</div>
            <div class="empty-sub" id="emptySub">上传音乐或点击"扫描"识别 music 文件夹里的歌曲</div>
        </div>
    </div>
</div>

<footer class="site-footer">
    <?= h($appName) ?> v<?= h($appVersion) ?> &middot;
    Copyright &copy; 2024 <?= h($appAuthor) ?> &middot;
    Released under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a>
</footer>

<input type="file" id="fileInput" accept="audio/*,.mp3,.flac,.wav,.m4a,.aac,.ogg,.opus,.wma,.ape" style="display:none;">

<div class="upload-bar" id="uploadBar">
    <div class="upload-title" id="uploadTitle">准备中</div>
    <div class="upload-file" id="uploadFile"></div>
    <div class="progress-row">
        <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
        <div class="progress-pct" id="progressPct">0%</div>
    </div>
    <div class="upload-stats" id="uploadStats"></div>
    <div class="upload-cancel"><button id="cancelUpload">停止上传</button></div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<!-- 登录 -->
<div class="modal-mask" id="loginModal">
    <div class="modal" style="max-width:380px;">
        <h3>管理员登录</h3>
        <div class="sub" id="loginSub">输入用户名和密码</div>
        <input type="text" id="loginUser" placeholder="用户名" autocomplete="username">
        <input type="password" id="loginPwd" placeholder="密码" autocomplete="current-password">
        <div class="modal-actions">
            <button class="btn secondary" id="loginCancel">取消</button>
            <button class="btn" id="loginSubmit">登录</button>
        </div>
    </div>
</div>

<!-- 修改密码 -->
<div class="modal-mask" id="pwdModal">
    <div class="modal" style="max-width:380px;">
        <h3>修改密码</h3>
        <div class="sub">修改后需要重新登录</div>
        <input type="password" id="pwdOld" placeholder="原密码">
        <input type="password" id="pwdNew" placeholder="新密码（至少 6 位）">
        <input type="password" id="pwdConfirm" placeholder="确认新密码">
        <div class="modal-actions">
            <button class="btn secondary" id="pwdCancel">取消</button>
            <button class="btn" id="pwdSave">保存</button>
        </div>
    </div>
</div>

<!-- 关于 -->
<div class="modal-mask" id="aboutModal">
    <div class="modal" style="max-width:460px;">
        <div class="about-box">
            <div class="about-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 18V5l12-2v13"/>
                    <circle cx="6" cy="18" r="3"/>
                    <circle cx="18" cy="16" r="3"/>
                </svg>
            </div>
            <div class="about-name"><?= h($appName) ?></div>
            <div class="about-tagline">Personal Music Library</div>
            <div class="about-made">Made by <span><?= h($appAuthor) ?></span></div>
            <div class="about-divider"></div>
            <div class="about-row">
                <span class="label">系统通知</span>
                <label class="switch">
                    <input type="checkbox" id="notifyToggle">
                    <span class="slider"></span>
                </label>
            </div>
            <div class="notify-state" id="notifyState">通知未启用</div>
            <div class="about-row" style="padding-top:14px;">
                <span class="label">通知栏控制</span>
                <span class="value" id="msValue">检测中...</span>
            </div>
            <div class="mediasession-state" id="msState">正在检测 Media Session 支持...</div>
            <div class="about-divider"></div>
            <div class="about-row"><span class="label">版本</span><span class="value">v<?= h($appVersion) ?></span></div>
            <div class="about-row"><span class="label">客户端 API</span><span class="value">?api=1</span></div>
            <div class="about-row"><span class="label">作者</span><span class="value"><?= h($appAuthor) ?></span></div>
            <div class="about-divider"></div>
            <div class="license-box">
                <strong>MIT License</strong><br>
                Copyright &copy; 2024 <?= h($appAuthor) ?><br>
                Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files, to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the condition that the copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
            </div>
        </div>
        <div class="modal-actions" style="justify-content:center; margin-top:14px;">
            <button class="btn secondary" id="aboutClose">关闭</button>
        </div>
    </div>
</div>

<!-- 歌词 -->
<div class="modal-mask" id="lyricModal">
    <div class="modal">
        <h3>编辑歌词</h3>
        <div class="sub" id="lyricSub">粘贴 LRC 格式歌词，留空则删除</div>
        <textarea id="lyricText" placeholder="[00:00.00] 歌词示例&#10;[00:12.34] 第二行歌词"></textarea>
        <div class="modal-actions">
            <button class="btn secondary" id="lyricCancel">取消</button>
            <button class="btn" id="lyricSave">保存</button>
        </div>
    </div>
</div>

<!-- 歌曲信息 -->
<div class="modal-mask" id="metaModal">
    <div class="modal" style="max-width:380px;">
        <h3>编辑歌曲信息</h3>
        <div class="sub">修改标题和歌手</div>
        <input type="text" id="metaTitle" placeholder="歌曲标题">
        <input type="text" id="metaArtist" placeholder="歌手">
        <div class="modal-actions">
            <button class="btn secondary" id="metaCancel">取消</button>
            <button class="btn" id="metaSave">保存</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/aplayer@1.10.1/dist/APlayer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsmediatags@3.9.7/dist/jsmediatags.min.js"></script>
<script>
(function() {
    'use strict';

    var APP_NAME    = <?= json_encode($appName) ?>;
    var APP_VERSION = <?= json_encode($appVersion) ?>;
    var MAX_UPLOAD     = <?= (int)$maxUpload ?>;
    var MAX_UPLOAD_MB  = <?= json_encode($maxUploadMb) ?>;
    var COVER_MAX_DIM  = <?= (int)$coverMaxDim ?>;
    var CHUNK_SIZE     = <?= (int)$chunkSize ?>;
    var LS_KEY         = 'wyx_last_state_v1';
    var LS_NOTIFY      = 'wyx_notify_enabled_v1';

    var songs = [];
    var ap = null;
    var currentEditId = null;
    var currentPlayingId = null;
    var isAdmin = false;
    var uploading = false;
    var cancelFlag = false;

    function $(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function formatSize(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
    function firstChar(s) {
        var ch = String(s || '?').charAt(0);
        if (/[a-zA-Z]/.test(ch)) ch = ch.toUpperCase();
        return ch;
    }
    function defaultCover(title) {
        var ch = firstChar(title);
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' +
                  '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
                  '<stop offset="0" stop-color="#4c1d95"/>' +
                  '<stop offset="1" stop-color="#1e3a8a"/></linearGradient></defs>' +
                  '<rect width="200" height="200" fill="url(#g)"/>' +
                  '<text x="100" y="128" font-size="86" fill="#ffffff" text-anchor="middle" font-family="sans-serif">' + ch + '</text></svg>';
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }
    function toast(msg, type) {
        var el = document.createElement('div');
        el.className = 'toast ' + (type || '');
        el.textContent = msg;
        $('toastWrap').appendChild(el);
        requestAnimationFrame(function() { el.classList.add('show'); });
        setTimeout(function() {
            el.classList.remove('show');
            setTimeout(function() { el.remove(); }, 300);
        }, 3200);
    }
    function randomId() { return 'u' + Date.now() + '_' + Math.random().toString(36).slice(2, 10); }

    // ============ 权限 ============
    function applyAuth(admin) {
        isAdmin = !!admin;
        $('userLabel').textContent = admin ? 'wyx（管理员）' : '访客';
        $('userChip').classList.toggle('admin', admin);
        $('uploadBtn').style.display = admin ? '' : 'none';
        $('scanBtn').style.display = admin ? '' : 'none';
        renderList();
    }
    function loadAuth() {
        return fetch('?action=whoami').then(function(r) { return r.json(); })
            .then(function(d) { applyAuth(d && d.admin); });
    }
    function openLogin() {
        $('loginUser').value = ''; $('loginPwd').value = '';
        $('loginModal').classList.add('show');
        setTimeout(function() { $('loginUser').focus(); }, 100);
    }
    function doLogin() {
        var user = $('loginUser').value.trim();
        var pwd  = $('loginPwd').value;
        if (!user || !pwd) { toast('请输入用户名和密码', 'error'); return; }
        var fd = new FormData();
        fd.append('user', user);
        fd.append('pwd', pwd);
        fetch('?action=login', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    $('loginModal').classList.remove('show');
                    applyAuth(true);
                    toast('登录成功', 'success');
                } else toast(d.msg || '登录失败', 'error');
            });
    }
    function doLogout() {
        fetch('?action=logout', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function() { applyAuth(false); toast('已退出登录', 'success'); });
    }

    // ============ Media Session 统一封装 ============
    var MS = {
        supported: false,
        init: function() {
            this.supported = (typeof navigator !== 'undefined' && 'mediaSession' in navigator);
            if (!this.supported) return;
            try {
                navigator.mediaSession.setActionHandler('play', function() {
                    if (ap) { var p = ap.play(); if (p && p.catch) p.catch(function(){}); }
                });
                navigator.mediaSession.setActionHandler('pause', function() { if (ap) ap.pause(); });
                navigator.mediaSession.setActionHandler('previoustrack', function() {
                    if (ap) { ap.skipBack(); var p = ap.play(); if (p && p.catch) p.catch(function(){}); }
                });
                navigator.mediaSession.setActionHandler('nexttrack', function() {
                    if (ap) { ap.skipForward(); var p = ap.play(); if (p && p.catch) p.catch(function(){}); }
                });
                navigator.mediaSession.setActionHandler('seekbackward', function(d) {
                    if (ap && ap.audio) ap.audio.currentTime = Math.max(0, ap.audio.currentTime - ((d && d.seekOffset) || 10));
                });
                navigator.mediaSession.setActionHandler('seekforward', function(d) {
                    if (ap && ap.audio) ap.audio.currentTime = Math.min(ap.audio.duration || 0, ap.audio.currentTime + ((d && d.seekOffset) || 10));
                });
                navigator.mediaSession.setActionHandler('seekto', function(d) {
                    if (ap && ap.audio && d && d.seekTime != null) ap.audio.currentTime = d.seekTime;
                });
            } catch (e) {}
        },
        update: function(song) {
            if (!this.supported) return;
            try {
                if (!song) { navigator.mediaSession.metadata = null; return; }
                var cover = song.cover || defaultCover(song.title);
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: song.title || '',
                    artist: song.artist || '',
                    album: song.album || '',
                    artwork: [
                        { src: cover, sizes: '96x96',   type: 'image/jpeg' },
                        { src: cover, sizes: '128x128', type: 'image/jpeg' },
                        { src: cover, sizes: '192x192', type: 'image/jpeg' },
                        { src: cover, sizes: '256x256', type: 'image/jpeg' },
                        { src: cover, sizes: '384x384', type: 'image/jpeg' },
                        { src: cover, sizes: '512x512', type: 'image/jpeg' }
                    ]
                });
            } catch (e) {}
        },
        setPlayback: function(state) {
            if (!this.supported) return;
            try { navigator.mediaSession.playbackState = state; } catch (e) {}
        },
        exposeToNative: function(song) {
            try {
                window.__WYX_CURRENT__ = song ? {
                    id: song.id, title: song.title, artist: song.artist,
                    album: song.album, cover: song.cover || '', url: song.file,
                    duration: (ap && ap.audio && ap.audio.duration) || 0
                } : null;
                if (window.WYXBridge && typeof window.WYXBridge.onSongChanged === 'function') {
                    window.WYXBridge.onSongChanged(window.__WYX_CURRENT__);
                }
                if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.WYXBridge) {
                    var plug = window.Capacitor.Plugins.WYXBridge;
                    if (plug.onSongChanged) plug.onSongChanged({ song: window.__WYX_CURRENT__ });
                }
            } catch (e) {}
        }
    };

    // ============ 通知（降级）============
    function notifySupported() { return typeof Notification !== 'undefined'; }
    function updateNotifyState() {
        var el = $('notifyState');
        var tog = $('notifyToggle');
        var saved = localStorage.getItem(LS_NOTIFY) === '1';
        el.className = 'notify-state';
        if (!notifySupported()) {
            tog.checked = false; tog.disabled = true;
            el.textContent = '当前环境不支持通知';
            el.classList.add('err'); return;
        }
        var perm = Notification.permission;
        if (perm === 'denied') {
            tog.checked = false;
            el.textContent = '通知权限已被拒绝，请在系统设置里手动开启';
            el.classList.add('err'); return;
        }
        if (perm === 'granted') {
            tog.checked = saved;
            el.textContent = saved ? '通知已开启，播放/切歌会推送到通知栏' : '权限已授权，未启用';
            el.classList.add('ok'); return;
        }
        tog.checked = false;
        el.textContent = '点击开关申请权限';
    }
    function requestNotify() {
        if (!notifySupported()) { toast('当前环境不支持通知', 'error'); return Promise.resolve(false); }
        return Notification.requestPermission().then(function(perm) {
            if (perm === 'granted') {
                localStorage.setItem(LS_NOTIFY, '1');
                toast('通知已开启', 'success');
                return true;
            }
            localStorage.setItem(LS_NOTIFY, '0');
            toast('未授权通知', 'error');
            return false;
        }).catch(function() { toast('申请失败', 'error'); return false; });
    }
    function notify(title, body) {
        if (!notifySupported() || Notification.permission !== 'granted') return;
        if (localStorage.getItem(LS_NOTIFY) !== '1') return;
        try {
            var n = new Notification(title, { body: body, tag: 'wyx_music_play', silent: true });
            setTimeout(function() { try { n.close(); } catch (e) {} }, 5000);
        } catch (e) {}
    }

    function updateMsState() {
        var el = $('msState'), val = $('msValue');
        if (MS.supported) {
            el.className = 'mediasession-state ok';
            el.textContent = '已支持 MediaSession。配合 Capacitor 客户端，通知栏会出现上一首/播放/暂停/下一首按钮。';
            val.textContent = '可用';
        } else {
            el.className = 'mediasession-state';
            el.textContent = '当前环境不支持 MediaSession。请使用 WYX Music 客户端以获得完整通知栏控制。';
            val.textContent = '不可用';
        }
    }

    // ============ 数据 ============
    function loadList() {
        return fetch('?action=list').then(function(r) { return r.json(); })
            .then(function(data) {
                songs = Array.isArray(data) ? data : [];
                renderList();
                syncPlayerList();
            });
    }
    function renderList() {
        var kw = ($('searchInput').value || '').trim().toLowerCase();
        var list = songs.filter(function(s) {
            if (!kw) return true;
            return (s.title || '').toLowerCase().indexOf(kw) >= 0 ||
                   (s.artist || '').toLowerCase().indexOf(kw) >= 0 ||
                   (s.album || '').toLowerCase().indexOf(kw) >= 0;
        });
        $('songCount').textContent = songs.length;
        var ul = $('songList');
        var empty = $('emptyState');
        if (songs.length === 0) {
            ul.innerHTML = ''; empty.style.display = 'block'; return;
        }
        empty.style.display = 'none';
        if (list.length === 0) {
            ul.innerHTML = '<li class="empty"><div class="empty-title">没有匹配的歌曲</div><div class="empty-sub">换个关键词试试</div></li>';
            return;
        }
        var html = '';
        list.forEach(function(s) {
            var cover = s.cover ? '<img src="' + esc(s.cover) + '" alt="" loading="lazy">' : esc(firstChar(s.title));
            var playing = (s.id === currentPlayingId) ? ' playing' : '';
            var adminActions = isAdmin
                ? '<button class="icon-btn" data-act="lyric" title="编辑歌词"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></button>' +
                  '<button class="icon-btn" data-act="meta" title="编辑信息"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></button>' +
                  '<button class="icon-btn danger" data-act="delete" title="删除"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>'
                : '';
            html += '<li class="song-item' + playing + '" data-id="' + esc(s.id) + '">' +
                '<div class="song-cover">' + cover + '</div>' +
                '<div class="song-info">' +
                    '<div class="song-title">' + esc(s.title) + '</div>' +
                    '<div class="song-meta">' + esc(s.artist) +
                        (s.album ? ' · ' + esc(s.album) : '') +
                        ' · ' + formatSize(s.size || 0) + '</div>' +
                '</div>' +
                '<div class="song-actions">' +
                    '<a class="icon-btn" href="?action=download&id=' + encodeURIComponent(s.id) + '" title="下载" data-no-propagate="1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>' +
                    adminActions +
                '</div></li>';
        });
        ul.innerHTML = html;
    }
    function buildPlayer() {
        var audios = songs.map(function(s) {
            return {
                name: s.title, artist: s.artist, url: s.file,
                cover: s.cover || defaultCover(s.title),
                lrc: s.lyric ? s.lyric + '?t=' + Date.now() : '',
                _wyxId: s.id
            };
        });
        if (ap) ap.destroy();
        ap = new APlayer({
            container: $('player'), audio: audios, listFolded: false,
            listMaxHeight: '400px', theme: '#667eea', preload: 'none',
            volume: 0.8, mutex: true, lrcType: 3
        });
        var lastId = currentPlayingId;
        ap.on('listswitch', function() {
            var newId = audios[ap.list.index] ? audios[ap.list.index]._wyxId : null;
            if (newId !== lastId) {
                try { ap.audio.currentTime = 0; } catch (e) {}
                lastId = newId;
            }
            currentPlayingId = newId;
            renderList(); saveState(); onSongChanged();
        });
        ap.on('play', function() {
            currentPlayingId = audios[ap.list.index] ? audios[ap.list.index]._wyxId : null;
            renderList(); saveState(); MS.setPlayback('playing'); onSongChanged();
        });
        ap.on('pause', function() { renderList(); saveState(); MS.setPlayback('paused'); });
        ap.on('volumechange', saveState);
        ap.on('ended', function() {
            currentPlayingId = null; renderList(); clearState();
            MS.update(null); MS.setPlayback('none'); MS.exposeToNative(null);
        });
        MS.init();
        restoreState();
    }
    function onSongChanged() {
        if (!currentPlayingId) { MS.update(null); MS.exposeToNative(null); return; }
        var s = songs.filter(function(x) { return x.id === currentPlayingId; })[0];
        if (!s) return;
        MS.update(s);
        MS.exposeToNative(s);
        var title = (s.artist || '未知歌手') + ' - ' + (s.title || '未知');
        notify(title, APP_NAME);
    }
    function syncPlayerList() {
        if (!ap) { buildPlayer(); return; }
        var existing = {};
        if (ap.list && Array.isArray(ap.list.audios)) {
            ap.list.audios.forEach(function(a) { existing[a._wyxId] = true; });
        }
        var incoming = {};
        songs.forEach(function(s) { incoming[s.id] = true; });
        for (var i = ap.list.audios.length - 1; i >= 0; i--) {
            if (!incoming[ap.list.audios[i]._wyxId]) {
                try { ap.list.remove(i); } catch (e) {}
            }
        }
        songs.forEach(function(s) {
            if (!existing[s.id]) {
                try {
                    ap.list.add({
                        name: s.title, artist: s.artist, url: s.file,
                        cover: s.cover || defaultCover(s.title),
                        lrc: s.lyric ? s.lyric + '?t=' + Date.now() : '',
                        _wyxId: s.id
                    });
                } catch (e) { buildPlayer(); }
            }
        });
        renderList();
    }

    // ============ 持久化 ============
    var saveTimer = null;
    function saveState() {
        if (saveTimer) return;
        saveTimer = setTimeout(function() {
            saveTimer = null;
            if (!ap || !ap.audio) return;
            var state = {
                id: currentPlayingId,
                time: ap.audio.currentTime || 0,
                volume: ap.audio.volume || 0.8,
                playing: !ap.audio.paused,
                ts: Date.now()
            };
            try { localStorage.setItem(LS_KEY, JSON.stringify(state)); } catch (e) {}
        }, 300);
    }
    function clearState() { try { localStorage.removeItem(LS_KEY); } catch (e) {} }
    function readState() {
        try {
            var raw = localStorage.getItem(LS_KEY);
            if (!raw) return null;
            var s = JSON.parse(raw);
            if (!s || !s.id) return null;
            if (s.ts && Date.now() - s.ts > 30 * 24 * 3600 * 1000) return null;
            return s;
        } catch (e) { return null; }
    }
    function restoreState() {
        var s = readState();
        if (!s) return;
        if (ap && s.volume != null) { try { ap.volume(s.volume, true); } catch (e) {} }
        if (!s.id) return;
        var idx = -1;
        for (var i = 0; i < ap.list.audios.length; i++) {
            if (ap.list.audios[i]._wyxId === s.id) { idx = i; break; }
        }
        if (idx < 0) return;
        currentPlayingId = s.id;
        ap.list.switch(idx);
        var restore = function() {
            if (currentPlayingId !== s.id) return;
            try { ap.audio.currentTime = s.time || 0; } catch (e) {}
            if (s.playing) { var p = ap.play(); if (p && p.catch) p.catch(function() {}); }
            ap.off('loadedmetadata', restore);
        };
        ap.on('loadedmetadata', restore);
        renderList();
    }
    setInterval(function() { if (ap && ap.audio && !ap.audio.paused) saveState(); }, 3000);

    // 每秒上报进度给原生
    setInterval(function() {
        if (!ap || !ap.audio) return;
        var payload = {
            position: ap.audio.currentTime || 0,
            duration: ap.audio.duration || 0,
            playing: !ap.audio.paused
        };
        try {
            if (window.WYXBridge && typeof window.WYXBridge.onProgress === 'function') {
                window.WYXBridge.onProgress(payload);
            }
            if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.WYXBridge) {
                var plug = window.Capacitor.Plugins.WYXBridge;
                if (plug.onProgress) plug.onProgress(payload);
            }
        } catch (e) {}
    }, 1000);

    // 接收原生媒体控制回调
    (function() {
        function handleNativeAction(ev) {
            var a = ev && ev.action;
            if (!ap) return;
            if (a === 'play')  { var p = ap.play(); if (p && p.catch) p.catch(function(){}); }
            if (a === 'pause') ap.pause();
            if (a === 'next')  { ap.skipForward(); var p2 = ap.play(); if (p2 && p2.catch) p2.catch(function(){}); }
            if (a === 'prev')  { ap.skipBack();    var p3 = ap.play(); if (p3 && p3.catch) p3.catch(function(){}); }
            if (a === 'seekto' && ev.data && ev.data.position != null && ap.audio) {
                try { ap.audio.currentTime = ev.data.position; } catch (e) {}
            }
            if (a === 'stop') ap.pause();
        }
        window.__WYX_NATIVE_HANDLER__ = handleNativeAction;
        function bindCapacitor() {
            if (!window.Capacitor || !window.Capacitor.Plugins || !window.Capacitor.Plugins.WYXBridge) return false;
            var plug = window.Capacitor.Plugins.WYXBridge;
            if (plug.addListener) {
                plug.addListener('mediaAction', handleNativeAction);
            }
            return true;
        }
        if (!bindCapacitor()) {
            var tries = 0;
            var t = setInterval(function() {
                tries++;
                if (bindCapacitor() || tries > 20) clearInterval(t);
            }, 300);
        }
    })();

    window.addEventListener('beforeunload', saveState);
    document.addEventListener('visibilitychange', function() { if (document.hidden) saveState(); });

    // ============ 封面压缩 ============
    function compressCover(dataUrl) {
        return new Promise(function(resolve) {
            if (!dataUrl) { resolve(null); return; }
            var img = new Image();
            img.onload = function() {
                var w = img.width, h = img.height, scale = 1;
                if (w > COVER_MAX_DIM || h > COVER_MAX_DIM) scale = Math.min(COVER_MAX_DIM / w, COVER_MAX_DIM / h);
                var nw = Math.max(1, Math.round(w * scale)), nh = Math.max(1, Math.round(h * scale));
                try {
                    var canvas = document.createElement('canvas');
                    canvas.width = nw; canvas.height = nh;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, nw, nh);
                    resolve(canvas.toDataURL('image/jpeg', 0.85));
                } catch (e) { resolve(dataUrl); }
            };
            img.onerror = function() { resolve(null); };
            img.src = dataUrl;
        });
    }
    function extractTags(file) {
        return new Promise(function(resolve) {
            var fallback = { title: file.name.replace(/\.[^.]+$/, ''), artist: '未知歌手', album: '', cover: null, lyrics: '' };
            if (typeof jsmediatags === 'undefined') { resolve(fallback); return; }
            var done = false;
            var timer = setTimeout(function() { if (!done) { done = true; resolve(fallback); } }, 8000);
            try {
                jsmediatags.read(file, {
                    onSuccess: function(tag) {
                        if (done) return;
                        done = true; clearTimeout(timer);
                        var t = tag.tags || {}, cover = null;
                        if (t.picture && t.picture.data && t.picture.format) {
                            try {
                                var data = t.picture.data, binary = '', chunk = 4096;
                                for (var i = 0; i < data.length; i += chunk) {
                                    var slice = data.slice ? data.slice(i, i + chunk) : data.subarray(i, i + chunk);
                                    binary += String.fromCharCode.apply(null, slice);
                                }
                                cover = 'data:' + t.picture.format + ';base64,' + btoa(binary);
                            } catch (e) { cover = null; }
                        }
                        resolve({
                            title: t.title || fallback.title, artist: t.artist || fallback.artist,
                            album: t.album || '', cover: cover, lyrics: t.lyrics || ''
                        });
                    },
                    onError: function() { if (!done) { done = true; clearTimeout(timer); resolve(fallback); } }
                });
            } catch (e) { if (!done) { done = true; clearTimeout(timer); resolve(fallback); } }
        });
    }

    // ============ 上传 ============
    function postForm(url, formData) {
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.timeout = 60000;
            xhr.onload = function() {
                var res;
                try { res = JSON.parse(xhr.responseText); }
                catch (e) { reject(new Error('响应解析失败')); return; }
                if (res && res.need_login) { applyAuth(false); reject(new Error(res.msg || '需要登录')); return; }
                resolve(res);
            };
            xhr.onerror = function() { reject(new Error('网络错误')); };
            xhr.ontimeout = function() { reject(new Error('请求超时')); };
            xhr.send(formData);
        });
    }
    function uploadChunk(uploadId, index, blob) {
        var fd = new FormData();
        fd.append('uploadId', uploadId);
        fd.append('index', String(index));
        fd.append('chunk', blob, 'chunk');
        return postForm('?action=upload_chunk', fd);
    }
    function uploadFinish(uploadId, fileName, meta) {
        var fd = new FormData();
        fd.append('uploadId', uploadId);
        fd.append('name', fileName);
        fd.append('meta', JSON.stringify(meta));
        return postForm('?action=upload_finish', fd);
    }
    function uploadAbort(uploadId) {
        var fd = new FormData();
        fd.append('uploadId', uploadId);
        return postForm('?action=upload_abort', fd).catch(function() {});
    }
    function uploadCover(id, coverDataUrl) {
        if (!coverDataUrl) return Promise.resolve();
        var fd = new FormData();
        fd.append('id', id);
        fd.append('cover', coverDataUrl);
        return postForm('?action=cover_upload', fd).catch(function() {});
    }
    function uploadFile(file, onProgress) {
        return extractTags(file).then(function(meta) {
            var rawCover = meta.cover;
            delete meta.cover;
            var coverPromise = rawCover ? compressCover(rawCover) : Promise.resolve(null);
            return coverPromise.then(function(compressedCover) {
                var uploadId = randomId();
                var total = file.size;
                var chunks = Math.ceil(total / CHUNK_SIZE);
                var sent = 0;
                function sendNext(i) {
                    if (i >= chunks) return Promise.resolve({ ok: true });
                    if (cancelFlag) return Promise.reject(new Error('已取消'));
                    var start = i * CHUNK_SIZE;
                    var end = Math.min(start + CHUNK_SIZE, total);
                    return uploadChunk(uploadId, i, file.slice(start, end)).then(function(res) {
                        if (!res.ok) throw new Error(res.msg || '分片上传失败');
                        sent = end;
                        if (onProgress) onProgress(sent / total);
                        return sendNext(i + 1);
                    });
                }
                return sendNext(0).then(function() {
                    return uploadFinish(uploadId, file.name, meta);
                }).then(function(res) {
                    if (!res.ok) throw new Error(res.msg || '完成上传失败');
                    if (compressedCover) return uploadCover(res.id, compressedCover).then(function() { return res; });
                    return res;
                }).catch(function(err) {
                    return uploadAbort(uploadId).then(function() { throw err; });
                });
            });
        });
    }
    function showUploadBar(title, file, pct, stats) {
        $('uploadBar').classList.add('show');
        $('uploadTitle').textContent = title;
        $('uploadFile').textContent = file || '';
        $('progressFill').style.width = Math.round((pct || 0) * 100) + '%';
        $('progressPct').textContent = Math.round((pct || 0) * 100) + '%';
        $('uploadStats').textContent = stats || '';
    }
    function hideUploadBar(delay) {
        setTimeout(function() {
            $('uploadBar').classList.remove('show');
            $('progressFill').style.width = '0%';
            $('progressPct').textContent = '0%';
        }, delay || 1200);
    }
    function handleSingleFile(file) {
        if (!file) return;
        if (file.size > MAX_UPLOAD) { toast('文件超过 ' + MAX_UPLOAD_MB + ' MB', 'error'); return; }
        if (uploading) { toast('已有上传任务进行中', 'error'); return; }
        uploading = true; cancelFlag = false;
        showUploadBar('解析中', file.name, 0, '');
        uploadFile(file, function(ratio) {
            showUploadBar('上传中', file.name, ratio, Math.round(ratio * 100) + '%');
        }).then(function(res) {
            if (res && res.ok) toast('已上传：' + (res.item ? res.item.title : file.name), 'success');
            else toast('上传失败：' + (res && res.msg ? res.msg : '未知'), 'error');
            return loadList();
        }).catch(function(err) {
            toast('上传失败：' + (err && err.message ? err.message : '未知'), 'error');
        }).then(function() {
            showUploadBar('完成', '', 1, '');
            hideUploadBar(600);
            uploading = false;
        });
    }

    // ============ 弹窗 ============
    function openLyric(id) {
        currentEditId = id;
        var s = songs.filter(function(x) { return x.id === id; })[0];
        if (!s) return;
        $('lyricSub').textContent = s.title + ' - ' + s.artist;
        fetch('?action=lyric_get&id=' + encodeURIComponent(id))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                $('lyricText').value = d.text || '';
                $('lyricModal').classList.add('show');
            });
    }
    function saveLyric() {
        var text = $('lyricText').value;
        var fd = new FormData();
        fd.append('id', currentEditId);
        fd.append('text', text);
        fetch('?action=lyric_save', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    $('lyricModal').classList.remove('show');
                    var s = songs.filter(function(x) { return x.id === currentEditId; })[0];
                    if (s) s.lyric = text ? ('wyx_data/lyrics/' + currentEditId + '.lrc') : null;
                    buildPlayer();
                    toast('歌词已保存', 'success');
                } else toast('保存失败：' + (d.msg || '未知'), 'error');
            });
    }
    function openMeta(id) {
        currentEditId = id;
        var s = songs.filter(function(x) { return x.id === id; })[0];
        if (!s) return;
        $('metaTitle').value = s.title || '';
        $('metaArtist').value = s.artist || '';
        $('metaModal').classList.add('show');
    }
    function saveMeta() {
        var fd = new FormData();
        fd.append('id', currentEditId);
        fd.append('title', $('metaTitle').value);
        fd.append('artist', $('metaArtist').value);
        fetch('?action=meta_update', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    $('metaModal').classList.remove('show');
                    loadList();
                    toast('信息已更新', 'success');
                } else toast('保存失败：' + (d.msg || '未知'), 'error');
            });
    }
    function deleteSong(id) {
        var s = songs.filter(function(x) { return x.id === id; })[0];
        if (!s) return;
        if (!confirm('确定删除 "' + s.title + '" 吗？')) return;
        var fd = new FormData();
        fd.append('id', id);
        fetch('?action=delete', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    if (currentPlayingId === id) { currentPlayingId = null; clearState(); }
                    loadList();
                    toast('已删除', 'success');
                } else toast('删除失败', 'error');
            });
    }
    function doScan() {
        toast('正在扫描 music 文件夹...', 'success');
        fetch('?action=scan', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) { toast('扫描完成，新增 ' + d.added + ' 首', 'success'); loadList(); }
                else toast('扫描失败：' + (d.msg || '未知'), 'error');
            });
    }
    function doPwdChange() {
        var o = $('pwdOld').value, n = $('pwdNew').value, c = $('pwdConfirm').value;
        if (!o || !n) { toast('请填写完整', 'error'); return; }
        if (n !== c) { toast('两次新密码不一致', 'error'); return; }
        if (n.length < 6) { toast('新密码至少 6 位', 'error'); return; }
        var fd = new FormData();
        fd.append('old', o); fd.append('new', n);
        fetch('?action=pwd_change', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    $('pwdModal').classList.remove('show');
                    toast('密码已修改，请重新登录', 'success');
                    applyAuth(false);
                } else toast(d.msg || '修改失败', 'error');
            });
    }

    // ============ 事件 ============
    $('aboutBtn').addEventListener('click', function() {
        updateNotifyState(); updateMsState();
        $('aboutModal').classList.add('show');
    });
    $('aboutClose').addEventListener('click', function() { $('aboutModal').classList.remove('show'); });
    $('notifyToggle').addEventListener('change', function(e) {
        if (e.target.checked) {
            requestNotify().then(function(ok) { if (!ok) e.target.checked = false; updateNotifyState(); });
        } else {
            localStorage.setItem(LS_NOTIFY, '0');
            updateNotifyState();
            toast('通知已关闭', 'success');
        }
    });
    $('userChip').addEventListener('click', function() {
        if (isAdmin) {
            if (confirm('已登录为 wyx。点"确定"修改密码，点"取消"退出登录。')) {
                $('pwdOld').value = ''; $('pwdNew').value = ''; $('pwdConfirm').value = '';
                $('pwdModal').classList.add('show');
            } else doLogout();
        } else openLogin();
    });
    $('loginCancel').addEventListener('click', function() { $('loginModal').classList.remove('show'); });
    $('loginSubmit').addEventListener('click', doLogin);
    $('loginPwd').addEventListener('keydown', function(e) { if (e.key === 'Enter') doLogin(); });
    $('pwdCancel').addEventListener('click', function() { $('pwdModal').classList.remove('show'); });
    $('pwdSave').addEventListener('click', doPwdChange);
    $('uploadBtn').addEventListener('click', function() { $('fileInput').click(); });
    $('fileInput').addEventListener('change', function(e) {
        var f = e.target.files && e.target.files[0];
        e.target.value = '';
        if (f) handleSingleFile(f);
    });
    $('scanBtn').addEventListener('click', doScan);
    $('cancelUpload').addEventListener('click', function() { cancelFlag = true; toast('已请求停止', 'error'); });
    $('searchInput').addEventListener('input', renderList);
    $('songList').addEventListener('click', function(e) {
        var li = e.target.closest('.song-item');
        if (!li) return;
        var id = li.getAttribute('data-id');
        if (e.target.closest('[data-no-propagate]')) { e.stopPropagation(); return; }
        var actBtn = e.target.closest('[data-act]');
        if (actBtn) {
            e.stopPropagation();
            var act = actBtn.getAttribute('data-act');
            if (act === 'lyric')  openLyric(id);
            if (act === 'meta')   openMeta(id);
            if (act === 'delete') deleteSong(id);
            return;
        }
        if (ap) {
            var idx = -1;
            for (var i = 0; i < ap.list.audios.length; i++) {
                if (ap.list.audios[i]._wyxId === id) { idx = i; break; }
            }
            if (idx >= 0) {
                if (ap.list.index === idx) {
                    if (ap.audio.paused) ap.play(); else ap.pause();
                } else {
                    ap.list.switch(idx);
                    try { ap.audio.currentTime = 0; } catch (e) {}
                    var p = ap.play(); if (p && p.catch) p.catch(function() {});
                }
            }
        }
    });
    $('lyricCancel').addEventListener('click', function() { $('lyricModal').classList.remove('show'); });
    $('lyricSave').addEventListener('click', saveLyric);
    $('metaCancel').addEventListener('click', function() { $('metaModal').classList.remove('show'); });
    $('metaSave').addEventListener('click', saveMeta);
    document.querySelectorAll('.modal-mask').forEach(function(m) {
        m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('show'); });
    });
    loadAuth().then(loadList).then(buildPlayer);
    updateMsState();
})();
</script>
</body>
</html>
    <?php
}