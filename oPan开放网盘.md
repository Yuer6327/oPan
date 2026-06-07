---
title: oPan 开放网盘：从零搭建带病毒扫描的云存储
published: 2026-06-07
description: 使用 PHP on Vercel + Koofr + VirusTotal 构建一个支持自动病毒扫描的开放网盘
image: ""
tags:
  - 项目
  - PHP
  - Vercel
  - 全栈
  - 教程
  - 开源项目
category: 项目
draft: false
lang: zh_CN
aiRating: 4
---

搭建一个开放网盘：用户通过网页上传文件到 Koofr 云存储，上传完成后自动触发 VirusTotal 扫描，扫描结果持久化并在文件管理器中展示。后端 PHP 运行在 Vercel Serverless，前端纯 HTML/CSS/JS 单文件，深色主题。

demo：[oPan](https://pan.yuer6327.top/)

源码：[GitHub](https://github.com/Yuer6327/oPan)

---

# 一、技术选型

## 后端：PHP on Vercel

选择 `vercel-php@0.9.0`（PHP 8.5）作为运行时。原因很简单——PHP 的 `file_get_contents` 和 cURL 天然适合做 API 网关，写几个文件就能跑，不需要框架。

Vercel Serverless 的限制：

| 计划 | 请求体大小 | 函数超时 |
|------|-----------|---------|
| Hobby（免费） | 4.5 MB | 最大 60 秒 |
| Pro | 4.5 GB | 最大 900 秒 |

由于 Koofr 没有提供 presigned URL（预签名上传链接），文件必须经过后端代理上传，因此受 Vercel 请求体大小限制。免费计划下最大 4.5 MB，升级 Pro 可达 4.5 GB。

## 存储：Koofr

Koofr 是欧洲的云存储服务，提供 REST API 和 WebDAV 支持。选择它的原因：

- 免费 10 GB 存储空间
- API 支持 Basic Auth（App Password）
- 有 `/content/api/v2/` 端点做文件上传下载
- 支持文件夹管理和元数据查询

## 扫描：VirusTotal

VirusTotal 免费版提供每天 500 次 API 调用，每分钟 4 次。使用 `POST /files` 端点直接上传文件内容进行扫描（不走 URL 扫描），扫描完成后通过 `GET /analyses/{id}` 轮询结果。

---

# 二、架构设计

## 整体流程

```
浏览器                    Vercel (PHP)                Koofr           VirusTotal
  │                          │                        │                  │
  ├── POST /api/upload ──────►│                        │                  │
  │   (multipart/form-data)  ├── POST /content/... ──►│                  │
  │                          │◄─ 200 OK ──────────────┤                  │
  │                          ├── 写入 scan-status.json ►│                 │
  │◄─ { file_path } ─────────┤                        │                  │
  │                          │                        │                  │
  ├── POST /api/scan ────────►│                        │                  │
  │                          ├── GET /content/... ────►│                  │
  │                          │◄─ file content ────────┤                  │
  │                          ├── POST /files ────────────────────────────►│
  │                          │◄─ { analysis_id } ───────────────────────┤
  │◄─ { analysis_id } ───────┤                        │                  │
  │                          │                        │                  │
  ├── GET /api/scan?aid=... ►│                        │                  │
  │                          ├── GET /analyses/{id} ────────────────────►│
  │                          │◄─ { status, stats } ────────────────────┤
  │                          ├── 更新 scan-status.json ►│                │
  │◄─ { is_clean, stats } ───┤                        │                  │
```

## 扫描状态持久化

Vercel Serverless 是无状态的，每次冷启动内存都会重置。为了在文件管理器中展示每个文件的扫描状态，使用 Koofr 存储一个 `.scan-status.json` 文件：

```json
{
  "files": {
    "/oPan/1780757830_0fc86dfb_report.pdf": {
      "status": "clean",
      "malicious": 0,
      "total": 75,
      "report_url": "https://www.virustotal.com/gui/file/46842e64...",
      "scan_time": "2026-06-06T14:56:30+00:00"
    }
  }
}
```

三个写入时机：
1. **上传完成** → `status: "scanning"`
2. **扫描完成** → `status: "clean"` 或 `status: "danger"`（附检测结果）
3. **扫描失败/超时/跳过** → `status: "error"`

读取时机：每次请求 `/api/files` 时读取并合并。

## 文件命名

上传到 Koofr 的文件名格式：`{timestamp}_{random8hex}_{原始文件名}`

```
1780757830_0fc86dfb_我的报告.pdf
```

前缀保证全局唯一（同一秒内用 8 位 hex 区分），前端显示时通过正则剥离前缀还原原始文件名。

---

# 三、后端实现

## 文件结构

```
api/
  upload.php      POST    multipart/form-data → Koofr
  scan.php        POST    触发 VT 扫描 / GET 轮询结果
  files.php       GET     列出文件 + 合并扫描状态
  download.php    GET     流式下载文件
  preview.php     GET     内联预览（图片/文本）
  health.php      GET     健康检查
lib/
  Helpers.php            CORS / JSON / cURL / 错误处理
  KoofrClient.php        Koofr API 封装
  VirusTotalClient.php   VT API + 速率限制
```

## 全局错误处理

PHP 在 Serverless 环境下最大的坑是错误输出格式。如果 PHP 抛出未捕获的错误，默认输出 HTML（`<br /><b>Fatal error</b>...`），而前端期望 JSON。

解决方案是在 `Helpers.php` 最顶部注册三层错误拦截：

```php
// 1. 禁止 PHP 输出 HTML 错误
ini_set('display_errors', '0');

// 2. 捕获 warning / notice
set_error_handler(function (int $errno, string $errstr, ...) {
    if ($errno === E_DEPRECATED) return true; // 跳过弃用警告
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $errstr]);
    exit;
});

// 3. 捕获未处理异常
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
});

// 4. 捕获 fatal error（parse error / out of memory）
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE])) {
        echo json_encode(['ok' => false, 'error' => $error['message']]);
    }
});
```

每个 API 端点的入口用 `ob_start()` + `try/catch(Throwable)` 包裹，确保无论发生什么，响应永远是 JSON。

## Koofr 文件操作

Koofr API 有两个 URL 前缀：

| 前缀 | 用途 |
|------|------|
| `/api/v2/...` | 元数据操作（列表、创建文件夹、删除） |
| `/content/api/v2/...` | 二进制传输（上传、下载） |

上传使用 `POST /content/api/v2/mounts/{id}/files/put` + `multipart/form-data`：

```php
$url = "https://app.koofr.net/content/api/v2/mounts/{$mountId}/files/put"
     . "?path=/oPan&filename={$storedName}&info=true";

$res = httpRequest('POST', $url, [
    'form_fields' => [
        'file' => new CURLFile($tmpPath, $mimeType, $storedName),
    ],
]);
```

`CURLFile` 是 PHP cURL 的原生文件流，避免将整个文件内容读入内存再编码为 multipart。

## VirusTotal 速率限制

VT 免费版限制 4 次/分钟、500 次/天。实现一个内存令牌桶：

```php
private static array $minuteTimestamps = [];

private function enforceRateLimit(): void
{
    $now = time();
    self::$minuteTimestamps = array_filter(
        self::$minuteTimestamps, fn($t) => ($now - $t) < 60
    );

    if (count(self::$minuteTimestamps) >= 4) {
        throw new RuntimeException('Rate limit', 429);
    }
    self::$minuteTimestamps[] = $now;
}
```

注意：这是 per-cold-start 的内存限流，Vercel 每次冷启动后计数器重置。对于个人使用足够；生产环境应用 Redis 做持久化限流。

## cURL 自动解压缩

Vercel 环境中，API 响应可能被 gzip 压缩。如果不处理，`json_decode` 会失败，`$res['body']` 返回 null。一行修复：

```php
curl_setopt($ch, CURLOPT_ENCODING, '');  // 自动解压 gzip / deflate
```

这是 PHP 8.5 的一个发现——之前版本的 API 可能不返回压缩内容，但 Vercel 的 CDN 层会主动压缩。

---

# 四、前端实现

## 设计系统

采用"三层深灰"配色，oklch 色彩空间：

| 层级 | oklch | 用途 |
|------|-------|------|
| 底层 | `0.135 0 0` | 页面背景 |
| 中层 | `0.18 0 0` | 卡片、表格行 |
| 悬浮 | `0.22 0 0` | hover 状态、下拉、toast |

功能色保留语义含义：绿色=安全、红色=风险、灰色=扫描中。所有装饰性元素（图标、进度条、按钮）统一使用灰度。

## 文件管理器

表格布局，六列：

```
☑ | 📄 文件名 | 大小 | 上传时间 | 状态 | [预览] [下载] [安全报告]
```

状态标签三种颜色：
- 绿色 `badge-clean`："已检测安全"
- 橙色（灰度化后为中灰）`badge-scanning`："扫描中..."
- 红色 `badge-error`："扫描出错"

"预览"按钮仅在文件类型支持时显示（图片、txt、md、json 等），其余类型隐藏。

## 下载安全策略

已检测安全的文件：直接下载，无弹窗。

扫描中或扫描出错的文件：弹出警告模态框：

> ⚠ 安全警告
>
> 文件 **report.pdf** 正在扫描中，尚未确认安全性。
>
> 下载并执行未知文件可能导致设备感染恶意软件。确定要继续吗？
>
> `[取消]` `[无视风险，继续下载]`

模态框的"无视风险"按钮使用红色背景，视觉上强调这是一个有风险的操作。

## 预览模态框

图片预览：先显示骨架脉冲（`skeleton-pulse` 动画）+ 旋转加载环 + "加载中..."，图片加载完成后 `opacity` 从 0 淡入到 1。

```javascript
const img = new Image();
img.onload = () => {
    $previewBody.innerHTML = '';
    $previewBody.appendChild(img);
    requestAnimationFrame(() => img.classList.add('loaded'));
};
img.src = `/api/preview?path=${encodeURIComponent(path)}`;
```

文本预览：同样的加载动画，fetch 到内容后渲染为 `<pre>` 块，带 `fadeIn` 动画。

## 扫描轮询

上传完成后前端独立轮询 VT 扫描结果，不阻塞其他操作：

```javascript
let delay = 5000;  // 首次 5 秒
while (attempts < 40) {
    await sleep(delay);
    delay = 15000;  // 后续 15 秒

    const resp = await fetch(
        `/api/scan?analysisId=${id}&file_path=${path}`
    );
    // ...
}
```

`file_path` 参数一并传给后端，后端在扫描完成时更新 `.scan-status.json`，这样文件管理器的下一次刷新就能看到最新状态。

---

# 五、踩坑记录

## Koofr 没有 Presigned URL

最初设计是前端直传 Koofr（绕过 Vercel 4.5 MB 限制），后端只生成 presigned URL。实测发现 Koofr 的 `/api/v2/mounts/{id}/files/link/upload` 端点对所有路径返回 404——这个端点可能已被移除或仅限企业版。

最终方案：后端代理上传。文件从浏览器 → Vercel PHP → Koofr，受限于 Vercel 的请求体大小限制。

## `curl_close()` 在 PHP 8.5 已弃用

PHP 8.0 起 cURL 句柄在变量离开作用域时自动关闭，`curl_close()` 不再有实际效果。PHP 8.5 将其标记为 `E_DEPRECATED`。

虽然 `set_error_handler` 默认不捕获弃用警告，但 Vercel 的 PHP 内置服务器可能会输出 HTML 格式的弃用信息，污染 JSON 响应。直接删除 `curl_close()` 调用即可。

## `.scan-status.json` 并发写入

理论上多个用户同时上传可能导致 `.scan-status.json` 的读-改-写冲突（后写入的覆盖先写入的）。对于个人网盘（通常只有一个人使用），这个竞态条件发生的概率极低。如果需要更可靠的方案，可以用 Koofr 的文件标签（tags）功能为每个文件单独存储状态。

---

# 六、部署

## 环境变量

在 Vercel Dashboard → Settings → Environment Variables 中设置：

| 变量 | 说明 |
|------|------|
| `KOOFR_EMAIL` | Koofr 账号邮箱 |
| `KOOFR_APP_PASSWORD` | Koofr 应用密码（Settings → Security → API） |
| `KOOFR_MOUNT_ID` | 可选，默认自动使用第一个存储挂载 |
| `VT_API_KEY` | VirusTotal API Key |

## 部署命令

```bash
git clone https://github.com/Yuer6327/oPan.git
cd oPan
vercel deploy
```

`vercel.json` 中 `api/*.php` 的 glob 模式会自动匹配所有 API 端点，`maxDuration: 60` 设置函数超时为 60 秒（Hobby 计划最大值）。

## 健康检查

访问 `/api/health` 验证配置：

```json
{
  "ok": true,
  "status": "healthy",
  "configured": {
    "koofr_email": true,
    "koofr_app_password": true,
    "vt_api_key": true
  }
}
```

如果某个配置项为 `false`，检查对应的环境变量是否已正确设置。
