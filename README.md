<div align="center">

# WYX Music Player

**一个部署在免费主机上的单文件在线音乐播放器**

*Made with love by [WYX](https://github.com/yourname)*

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://www.php.net/)
[![Platform](https://img.shields.io/badge/Platform-InfinityFree%20%7C%20Shared%20Hosting-blue.svg)](#)

[中文](#中文) · [English](#english)

</div>

---

## 中文

### 简介

WYX Music Player 是一个**单文件 PHP 在线音乐播放器**，专为免费主机（如 InfinityFree）设计。无需数据库、无需 Composer、无需 Node.js，上传一个 `index.php` 即可运行。

**演示**：[https://wyx-music.kesug.com](https://wyx-music.kesug.com)

### 功能特性

- **零依赖部署**：一个 `index.php` 搞定网页版，一个 `app.php` 搞定手机客户端
- **无需数据库**：使用 JSON 文件存储元数据，带文件锁和原子写入
- **自动扫描**：扫描 `music/` 目录，自动识别新上传的音频
- **全格式支持**：mp3 / flac / wav / aac / ogg / m4a / wma / ape / opus / aiff / alac
- **自动元数据**：前端用 jsmediatags 提取 ID3 标签（标题、歌手、专辑、封面、内嵌歌词）
- **分片上传**：1MB 分片，突破 WebView 和服务器单次上传限制，兼容性极好
- **精美界面**：深色渐变、毛玻璃、响应式、封面旋转、歌词滚动高亮
- **管理员登录**：单用户密码登录，支持修改密码，访客可听可下载
- **记住播放位置**：localStorage 保存播放进度、音量、当前歌，重启自动恢复
- **通知栏支持**：MediaSession API + Notification API 双重适配
- **MIT 许可证**：完全开源，可自由修改和分发

### 效果预览

| 桌面端 | 移动端 | 播放页 |
|--------|--------|--------|
| 左右分栏，播放器固定左侧 | 单列列表，底部播放条 | 全屏封面 + 歌词滚动 |

### 项目结构

```
htdocs/
├── index.php              网页版主入口（含所有 PHP 逻辑）
├── app.php                手机客户端页面（轻量版）
├── rebuild.php            索引重建工具（用完即删）
└── wyx_data/              数据目录（首次访问自动创建）
    ├── music/             音频文件
    ├── covers/            封面图片
    ├── lyrics/            歌词文件（LRC 格式）
    ├── tmp/               分片上传临时目录
    ├── music.json         歌曲元数据
    ├── auth.json          管理员账号密码
    └── .htaccess          禁止目录列表
```

### 实现原理

#### 1. 路由分发

`index.php` 通过 URL 参数分发请求：

```
网页访问：
  GET  /                 → 渲染播放器页面
  GET  /?action=list     → 返回歌曲列表 JSON
  GET  /?action=download → 下载歌曲
  POST /?action=login    → 登录
  POST /?action=upload   → 上传（分片）

客户端 API：
  GET  /?api=1&action=list              → 歌曲列表
  GET  /?api=1&action=stream&id=xxx     → 音频流
  POST /?api=1&action=upload_chunk      → 上传分片（需要 token）
```

#### 2. 数据存储

用 **JSON 文件 + 文件锁 + 原子写入** 替代数据库：

```php
// 读取：共享锁
$fp = fopen('music.json', 'r');
flock($fp, LOCK_SH);
$data = json_decode(fread($fp, filesize), true);
flock($fp, LOCK_UN);

// 写入：先写临时文件，再 rename
file_put_contents('music.json.tmp', $json, LOCK_EX);
rename('music.json.tmp', 'music.json');  // 原子操作
```

**为什么不用数据库**：免费主机 MySQL 有连接数和大小限制，且申请麻烦。JSON 文件对于个人音乐库（几百首到几千首）完全够用。

#### 3. 分片上传

```
前端：把文件切成 1MB 的片 → POST 到 ?action=upload_chunk
     每片上传完成 → 更新进度条
     全部完成 → POST ?action=upload_finish
     失败或取消 → POST ?action=upload_abort 清理临时文件

后端：每片保存到 wyx_data/tmp/{uploadId}/{index}
     finish 时按 index 排序、合并成完整文件
     清理临时目录
```

**为什么要分片**：WebView 和免费主机对单次请求大小有限制，分片能突破这些限制，同时提供精确的进度反馈。

#### 4. 封面和歌词提取

**封面**：前端用 [jsmediatags](https://github.com/aadsm/jsmediatags) 读取 ID3 的 `APIC` 帧，转成 base64 data URL，压缩到最长边 500px（用 Canvas），再单独上传到 `covers/`。

**歌词**：优先提取内嵌的 `USLT` 帧；没有则读取同名的 `.lrc` 文件；都没有可在管理界面手动编辑。

#### 5. 播放位置持久化

```js
// 每 3 秒保存
localStorage.setItem('wyx_last_state_v1', JSON.stringify({
    id: currentSongId,      // 当前歌的 id
    time: audio.currentTime, // 播放位置（秒）
    volume: audio.volume,    // 音量
    playing: !audio.paused,  // 是否在播放
    ts: Date.now()           // 时间戳（30 天后过期）
}));

// 页面加载时恢复
// 关键：只在 loadedmetadata 事件中恢复，且只恢复同一首歌
// 避免 A 歌的进度传给 B 歌
```

#### 6. 通知栏适配

```
Layer 1: Notification API
  → 显示"歌手 - 歌名"文本通知
  → WebView 是否支持取决于打包工具

Layer 2: MediaSession API
  → 注册 play/pause/next/prev/seek 回调
  → 设置封面、标题、歌手、专辑
  → 系统支持时会映射到通知栏和锁屏

Layer 3: 原生桥接（可选）
  → Capacitor / Cordova 环境下暴露 window.WYXBridge
  → 原生层用 MediaSessionCompat（Android）实现完整控制
```

**注意**：普通 WebView（如 WebToApp）默认不桥接 MediaSession，通知栏只能显示文本，不能加控制按钮。想要完整控制，需要用 Capacitor 或自写 Android WebView。

### 自定义

#### 修改站点名称和作者

在 `index.php` 顶部修改：

```php
define('APP_NAME', 'WYX Music Player');   // 站点名
define('APP_VERSION', '7.0');              // 版本号
define('APP_AUTHOR', 'WYX');               // 作者名
```

#### 修改默认账号密码

在 `index.php` 顶部：

```php
define('AUTH_FILE', DATA_DIR . '/auth.json');

// 首次访问时自动创建 auth.json，初始密码在此定义：
password_hash('52JD1314', PASSWORD_DEFAULT)
```

改初始密码后，**删除 `wyx_data/auth.json`**，下次访问会重新生成。或者直接登录后在"修改密码"里改。

#### 修改上传限制

```php
define('CHUNK_SIZE', 1048576);   // 1MB，改大改小都行
```

#### 修改封面压缩参数

```php
define('COVER_MAX_DIM', 500);   // 封面最长边像素
```

前端 JPEG 质量在 `compressCover()` 里：

```js
canvas.toDataURL('image/jpeg', 0.85);  // 0.85 是质量
```

#### 修改允许的音频格式

```php
define('ALLOWED_EXT', ['mp3','flac','wav',...]);
```

#### 修改颜色主题

CSS 变量在 `<style>` 块开头：

```css
:root {
    --bg1:#0f0c29;         /* 渐变起始色 */
    --bg2:#302b63;         /* 渐变中间色 */
    --bg3:#24243e;         /* 渐变结束色 */
    --accent:#667eea;      /* 主题色 */
    --accent2:#a78bfa;     /* 主题色 2（用于渐变） */
}
```

改这几个变量，全站配色跟着变。

#### 修改客户端 API Token

```php
// index.php
define('API_TOKEN', 'wyx-app-2024-52JD1314');

// app.php 里也要改成一样的
define('API_TOKEN', 'wyx-app-2024-52JD1314');
```

#### 更换"关于"面板里的花体字

`app.php` 和 `index.php` 的关于弹窗里，"Made by WYX" 用了 Google Fonts 的 Pacifico：

```html
<link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
```

改成别的花体字只需替换字体名（如 `Dancing Script`、`Great Vibes`、`Satisfy`）。

### 部署

#### 环境要求

- PHP 7.4 或更高（推荐 PHP 8.0+）
- 支持文件读写和 `flock`
- 无需 MySQL、无需 Composer、无需 Node.js

#### InfinityFree 部署（推荐）

1. **注册** [InfinityFree](https://infinityfree.net/) 账号，创建一个网站
2. 得到域名 `yourname.infinityfreeapp.com` 或绑定自己的域名
3. 通过 **File Manager** 或 FTP 进入 `htdocs/` 目录
4. 上传 `index.php` 和 `app.php`
5. 访问域名，首次访问会自动创建 `wyx_data/` 及子目录
6. **（可选）调整上传限制**：
   - 控制面板 → PHP Settings
   - `upload_max_filesize` 改为 `64M`
   - `post_max_size` 改为 `64M`
   - `max_execution_time` 改为 `300`
7. **登录管理员**：点右上角用户图标，输入 `wyx` / `52JD1314`
8. **上传音乐**：点上传按钮逐个上传，或者通过文件管理器批量上传到 `wyx_data/music/`，然后点"扫描"

#### 其他主机部署

任何支持 PHP 的虚拟主机都可以，步骤相同。注意：

- 目录权限：`755`（文件 `644`）
- 确保 `wyx_data/` 可写
- 若用 Nginx，确保 `.mp3`、`.flac` 等文件类型能被直接访问

#### Docker 部署（本地测试）

```dockerfile
FROM php:8.2-apache
COPY index.php /var/www/html/
COPY app.php /var/www/html/
RUN chmod -R 755 /var/www/html
```

```bash
docker build -t wyx-music .
docker run -d -p 8080:80 -v $(pwd)/wyx_data:/var/www/html/wyx_data wyx-music
```

访问 `http://localhost:8080`

#### 打包成 Android App

**方案 A：WebToApp（简单，但通知栏不支持控制按钮）**

1. 下载任意 WebToApp 打包工具
2. 目标 URL 填 `https://你的域名/app.php`
3. 开启以下权限：JavaScript、DOM Storage、File Access、Content Access、File Chooser
4. 打包成 APK

**方案 B：Capacitor（复杂，但通知栏有完整控制）**

1. 创建 Capacitor 项目
2. `capacitor.config.json` 里 `server.url` 指向你的域名
3. 写一个原生 `WyxMediaPlugin.java`，用 `MediaSessionCompat` 实现通知栏
4. 通过 `notifyListeners` 把原生的上一首/下一首回调传给网页
5. `./gradlew assembleDebug` 出 APK

详细步骤见 Wiki（待补充）。

### 常见问题

#### Q: 502 Bad Gateway

A: 通常是 PHP 语法错误或免费主机限流。

1. 建 `test.php` 写 `<?php phpinfo(); ?>`，访问看是否正常
2. 正常 → 是 `index.php` 代码问题，用 `php -l index.php` 检查语法
3. 502 → 服务器限流，等 15 分钟

#### Q: 上传失败说"响应解析失败"

A: 服务器返回的不是 JSON。F12 打开 Network 看 Response：

- HTML 错误页 → 服务器出错
- `Warning:` 开头 → PHP 有警告输出
- 空 → 超时

#### Q: 扫描后列表清空

A: `music.json` 被写坏了。用 `rebuild.php` 重建（见项目根目录）。

#### Q: 播放进度从 A 歌传到 B 歌

A: 检查 `restoreState()` 里的守卫条件：

```js
var restore = function() {
    if (currentPlayingId !== s.id) return;  // 关键
    audio.currentTime = s.time;
    ...
};
```

#### Q: 通知栏没有控制按钮

A: 普通 WebView 不桥接 MediaSession。用 Capacitor 或自写 Android WebView。

#### Q: 封面和歌词怎么批量导入

A: 文件名主名一致即可自动匹配：

- `music/歌手 - 歌名.mp3`
- `covers/歌手 - 歌名.jpg`
- `lyrics/歌手 - 歌名.lrc`

然后运行 `rebuild.php`。

### 开源协议

MIT License。详见 [LICENSE](LICENSE) 文件。

### 致谢

- [APlayer](https://github.com/DIYgod/APlayer) - 网页版播放器
- [jsmediatags](https://github.com/aadsm/jsmediatags) - ID3 标签解析
- [Pacifico](https://fonts.google.com/specimen/Pacifico) - 花体字字体

---

## English

### Introduction

WYX Music Player is a **single-file PHP online music player** designed for free hosting services like InfinityFree. No database, no Composer, no Node.js required — just upload one `index.php` and it works.

**Demo**: [https://wyx-music.kesug.com](https://wyx-music.kesug.com)

### Features

- **Zero-dependency deployment**: One `index.php` for web, one `app.php` for mobile
- **No database**: JSON file storage with file locking and atomic writes
- **Auto-scan**: Scans `music/` directory for newly uploaded audio files
- **All formats**: mp3 / flac / wav / aac / ogg / m4a / wma / ape / opus / aiff / alac
- **Auto metadata**: jsmediatags extracts ID3 tags (title, artist, album, cover, embedded lyrics)
- **Chunked upload**: 1MB chunks, bypasses WebView and server upload limits
- **Beautiful UI**: Dark gradient, glassmorphism, responsive, spinning cover, scrolling lyrics
- **Admin login**: Single user with password, changeable; guests can listen and download
- **Remember position**: localStorage saves playback position, volume, current song
- **Notification support**: MediaSession API + Notification API dual adaptation
- **MIT License**: Fully open source

### Preview

| Desktop | Mobile | Player |
|---------|--------|--------|
| Two-column, player sticky left | Single column, bottom bar | Fullscreen cover + lyrics |

### Project Structure

```
htdocs/
├── index.php              Web entry point (contains all PHP logic)
├── app.php                Mobile client page (lightweight)
├── rebuild.php            Index rebuild tool (delete after use)
└── wyx_data/              Data directory (auto-created on first visit)
    ├── music/             Audio files
    ├── covers/            Cover images
    ├── lyrics/            Lyrics files (LRC format)
    ├── tmp/               Chunk upload temp directory
    ├── music.json         Song metadata
    ├── auth.json          Admin credentials
    └── .htaccess          Disable directory listing
```

### How It Works

#### 1. Routing

`index.php` dispatches via URL parameters:

```
Web:
  GET  /                 → Render player page
  GET  /?action=list     → Return song list JSON
  GET  /?action=download → Download song
  POST /?action=login    → Login
  POST /?action=upload   → Upload (chunked)

Client API:
  GET  /?api=1&action=list              → Song list
  GET  /?api=1&action=stream&id=xxx     → Audio stream
  POST /?api=1&action=upload_chunk      → Upload chunk (token required)
```

#### 2. Storage

**JSON file + file lock + atomic write** instead of a database:

```php
// Read: shared lock
$fp = fopen('music.json', 'r');
flock($fp, LOCK_SH);
$data = json_decode(fread($fp, filesize), true);
flock($fp, LOCK_UN);

// Write: temp file + rename
file_put_contents('music.json.tmp', $json, LOCK_EX);
rename('music.json.tmp', 'music.json');  // atomic
```

**Why not a database**: Free hosts limit MySQL connections and size. JSON is sufficient for personal music libraries (hundreds to thousands of songs).

#### 3. Chunked Upload

```
Frontend: Split file into 1MB chunks → POST to ?action=upload_chunk
     Update progress after each chunk
     All done → POST ?action=upload_finish
     Failed or cancelled → POST ?action=upload_abort

Backend: Save each chunk to wyx_data/tmp/{uploadId}/{index}
     On finish, sort by index, merge into full file
     Clean up temp directory
```

**Why chunked**: WebView and free hosts limit request size. Chunking bypasses limits and enables precise progress.

#### 4. Cover & Lyrics Extraction

**Cover**: Frontend reads the `APIC` frame from ID3 using [jsmediatags](https://github.com/aadsm/jsmediatags), converts to base64 data URL, compresses to max 500px with Canvas, uploads separately to `covers/`.

**Lyrics**: Prefer embedded `USLT` frame; fall back to same-name `.lrc` file; or edit manually in the admin UI.

#### 5. Playback Position Persistence

```js
// Save every 3 seconds
localStorage.setItem('wyx_last_state_v1', JSON.stringify({
    id: currentSongId,
    time: audio.currentTime,
    volume: audio.volume,
    playing: !audio.paused,
    ts: Date.now()  // Expires after 30 days
}));

// Restore on page load
// Key: only restore in loadedmetadata, and only for the same song
// Prevents song A's progress from carrying over to song B
```

#### 6. Notification Adaptation

```
Layer 1: Notification API
  → Shows "Artist - Title" text notification
  → Whether WebView supports it depends on the packager

Layer 2: MediaSession API
  → Registers play/pause/next/prev/seek handlers
  → Sets cover, title, artist, album
  → System maps to notification and lock screen when supported

Layer 3: Native Bridge (optional)
  → Expose window.WYXBridge in Capacitor / Cordova
  → Native layer uses MediaSessionCompat (Android) for full control
```

**Note**: Plain WebViews (like WebToApp) don't bridge MediaSession by default; the notification shows text only. For full control, use Capacitor or write a custom Android WebView.

### Customization

#### Change Site Name and Author

At the top of `index.php`:

```php
define('APP_NAME', 'WYX Music Player');
define('APP_VERSION', '7.0');
define('APP_AUTHOR', 'WYX');
```

#### Change Default Credentials

```php
// Initial password is defined here (auto-created on first visit):
password_hash('52JD1314', PASSWORD_DEFAULT)
```

After changing, **delete `wyx_data/auth.json`** — it will regenerate on next visit.

#### Change Upload Limit

```php
define('CHUNK_SIZE', 1048576);  // 1MB
```

#### Change Cover Compression

```php
define('COVER_MAX_DIM', 500);  // max dimension in pixels
```

Frontend JPEG quality in `compressCover()`:

```js
canvas.toDataURL('image/jpeg', 0.85);  // 0.85 is quality
```

#### Change Allowed Formats

```php
define('ALLOWED_EXT', ['mp3','flac','wav',...]);
```

#### Change Color Theme

CSS variables at the top of the `<style>` block:

```css
:root {
    --bg1:#0f0c29;
    --bg2:#302b63;
    --bg3:#24243e;
    --accent:#667eea;
    --accent2:#a78bfa;
}
```

#### Change API Token

```php
// index.php
define('API_TOKEN', 'wyx-app-2024-52JD1314');

// Also change in app.php to match
```

#### Change Calligraphy Font

The "Made by WYX" text uses Google Fonts' Pacifico:

```html
<link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
```

Swap with `Dancing Script`, `Great Vibes`, `Satisfy`, etc.

### Deployment

#### Requirements

- PHP 7.4+ (8.0+ recommended)
- File read/write and `flock` support
- No MySQL, no Composer, no Node.js

#### InfinityFree Deployment (Recommended)

1. Register at [InfinityFree](https://infinityfree.net/) and create a site
2. Get your domain `yourname.infinityfreeapp.com` or bind a custom domain
3. Enter `htdocs/` via **File Manager** or FTP
4. Upload `index.php` and `app.php`
5. Visit your domain — `wyx_data/` is auto-created on first visit
6. **(Optional) Adjust upload limits**:
   - Control Panel → PHP Settings
   - `upload_max_filesize` = `64M`
   - `post_max_size` = `64M`
   - `max_execution_time` = `300`
7. **Login**: Click the user icon in the top-right, enter `wyx` / `52JD1314`
8. **Upload music**: Use the upload button, or batch-upload to `wyx_data/music/` via File Manager, then click "Scan"

#### Other Hosts

Any PHP-enabled shared hosting works. Notes:

- Directory permissions: `755` (files `644`)
- Ensure `wyx_data/` is writable
- On Nginx, ensure `.mp3`, `.flac`, etc. are directly accessible

#### Docker (Local Testing)

```dockerfile
FROM php:8.2-apache
COPY index.php /var/www/html/
COPY app.php /var/www/html/
RUN chmod -R 755 /var/www/html
```

```bash
docker build -t wyx-music .
docker run -d -p 8080:80 -v $(pwd)/wyx_data:/var/www/html/wyx_data wyx-music
```

Visit `http://localhost:8080`

#### Android Packaging

**Option A: WebToApp (easy, but no notification control)**

1. Download any WebToApp packager
2. Target URL: `https://your-domain/app.php`
3. Enable: JavaScript, DOM Storage, File Access, Content Access, File Chooser
4. Build APK

**Option B: Capacitor (complex, but full notification control)**

1. Create a Capacitor project
2. Set `server.url` in `capacitor.config.json` to your domain
3. Write a native `WyxMediaPlugin.java` using `MediaSessionCompat`
4. Use `notifyListeners` to pass native prev/next callbacks to the web page
5. `./gradlew assembleDebug` to build APK

Detailed steps in the Wiki (TBD).

### FAQ

#### Q: 502 Bad Gateway

A: Usually a PHP syntax error or free-host rate limiting.

1. Create `test.php` with `<?php phpinfo(); ?>` and visit
2. Works → problem is in `index.php`, check with `php -l index.php`
3. Still 502 → server rate limited, wait 15 minutes

#### Q: Upload fails with "Response parsing failed"

A: Server returned non-JSON. Open F12 → Network → check Response:

- HTML error page → server error
- `Warning:` prefix → PHP warning output
- Empty → timeout

#### Q: List empties after scan

A: `music.json` was corrupted. Use `rebuild.php` to rebuild.

#### Q: Song A's progress carries over to song B

A: Check the guard in `restoreState()`:

```js
var restore = function() {
    if (currentPlayingId !== s.id) return;  // Key
    audio.currentTime = s.time;
    ...
};
```

#### Q: No control buttons in notification

A: Plain WebViews don't bridge MediaSession. Use Capacitor or a custom Android WebView.

#### Q: How to batch-import covers and lyrics

A: Match by base filename:

- `music/Artist - Title.mp3`
- `covers/Artist - Title.jpg`
- `lyrics/Artist - Title.lrc`

Then run `rebuild.php`.

### License

MIT License. See [LICENSE](LICENSE).

### Credits

- [APlayer](https://github.com/DIYgod/APlayer) - Web player
- [jsmediatags](https://github.com/aadsm/jsmediatags) - ID3 tag parser
- [Pacifico](https://fonts.google.com/specimen/Pacifico) - Calligraphy font
