<?php
/**
 * 金点图床 - PHP上传接口
 * 支持MWeb自定义图床API
 */

// 配置信息
define('GITHUB_TOKEN', getenv('GITHUB_TOKEN') ?: '');
define('REPO_OWNER', 'pipbestcom');
define('REPO_NAME', 'piccom');
// 默认上传目录，可以通过POST参数 'path' 覆盖
define('DEFAULT_TARGET_DIR', 'public/mweb/');
define('BRANCH', 'main');
define('COMMIT_MESSAGE_PREFIX', 'Upload by PicCom');
define('ORIGINAL_FILENAME_PREFIX', 'original=');
// WEBSITE_BASE 已移除，现在直接使用GitHub Raw URL确保图片可访问

// 允许的文件类型
$allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml',
    'image/bmp',
    'application/pdf',
    'text/plain',
    'text/markdown',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

// 允许的文件扩展名
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'pdf', 'txt', 'md', 'doc', 'docx'];

// 最大文件大小 (20MB)
define('MAX_FILE_SIZE', 20 * 1024 * 1024);

/**
 * 生成UUID
 */
function generateUuid() {
    if (function_exists('random_bytes')) {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    } else {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * 构建文件名
 */
function buildUuidFilename($originalName) {
    $uuid = generateUuid();
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // 如果没有扩展名，使用默认的png
    if (empty($extension)) {
        $extension = 'png';
    }

    return $uuid . '.' . $extension;
}

/**
 * 文件转Base64
 */
function fileToBase64($filePath) {
    $fileContent = file_get_contents($filePath);
    return base64_encode($fileContent);
}

/**
 * 发送HTTP请求到GitHub API
 */
function sendGitHubRequest($url, $data, $method = 'PUT', $token = null) {
    $ch = curl_init();

    $tokenToUse = $token;
    if ($tokenToUse === null) {
        $tokenToUse = GITHUB_TOKEN;
    }

    if (empty($tokenToUse)) {
        throw new Exception('GitHub Token未配置（请在服务器环境变量设置 GITHUB_TOKEN）');
    }

    $headers = [
        'Authorization: Bearer ' . $tokenToUse,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'Content-Type: application/json',
        'User-Agent: Pipbest-Pic-Uploader/1.0'
    ];

    $sslVerifyEnv = getenv('GITHUB_SSL_VERIFY');
    $sslVerify = true;
    if ($sslVerifyEnv !== false && $sslVerifyEnv !== '') {
        $sslVerify = !in_array(strtolower(trim($sslVerifyEnv)), ['0', 'false', 'no', 'off'], true);
    }

    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_TIMEOUT => 30
    ];

    if (!$sslVerify) {
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    $caBundle = getenv('GITHUB_CA_BUNDLE') ?: getenv('CURL_CA_BUNDLE');
    if (!empty($caBundle)) {
        $curlOptions[CURLOPT_CAINFO] = $caBundle;
    }

    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        throw new Exception('网络请求失败: ' . $error);
    }

    return [$httpCode, $response];
}

/**
 * 检查文件是否存在
 */
function checkFileExists($filePath) {
    try {
        $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . $filePath;
        list($httpCode, $response) = sendGitHubRequest($url, [], 'GET');

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return isset($data['sha']) ? $data['sha'] : false;
        }

        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 上传文件到GitHub
 */
function uploadToGitHub($filePath, $fileName, $base64Content, $uploadPath = null, $originalName = '') {
    $targetPath = ($uploadPath ?: DEFAULT_TARGET_DIR) . $fileName;

    // 检查文件是否已存在
    $existingSha = checkFileExists($targetPath);

    // 在 commit message 中保存原始文件名
    $msgSuffix = '';
    if ($originalName && $originalName !== $fileName) {
        $msgSuffix = ' ' . ORIGINAL_FILENAME_PREFIX . $originalName;
    }

    $payload = [
        'message' => COMMIT_MESSAGE_PREFIX . ' ' . basename($originalName ?: $fileName) . ($existingSha ? ' (覆盖)' : '') . $msgSuffix,
        'content' => $base64Content,
        'branch' => BRANCH
    ];

    // 如果文件存在，添加SHA值进行覆盖
    if ($existingSha) {
        $payload['sha'] = $existingSha;
    }

    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . $targetPath;

    list($httpCode, $response) = sendGitHubRequest($url, $payload, $existingSha ? 'PUT' : 'PUT');

    if ($httpCode !== 200 && $httpCode !== 201) {
        $errorData = json_decode($response, true);
        throw new Exception('上传失败: ' . ($errorData['message'] ?? '未知错误'));
    }

    return $targetPath;
}

/**
 * 处理文件上传
 */
function handleUpload() {
    error_log("handleUpload called");
    try {
        error_log("Starting file upload process");

        // 获取上传路径参数，支持MWeb自定义路径
        $uploadPath = DEFAULT_TARGET_DIR;
        if (isset($_POST['path']) && !empty($_POST['path'])) {
            // 清理和验证路径参数
            $customPath = trim($_POST['path']);
            // 确保路径以/开头，不以/结尾
            $customPath = trim($customPath, '/');
            if (!empty($customPath)) {
                $uploadPath = $customPath . '/';
            }
        }
        error_log("Upload path: " . $uploadPath);

        // 检查是否有文件上传
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('没有接收到有效的文件');
        }

        $file = $_FILES['file'];

        // 检查文件大小
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception('文件大小超过限制 (最大20MB)');
        }

        // 检查文件类型
        if (!in_array($file['type'], $GLOBALS['allowedTypes'])) {
            throw new Exception('不支持的文件类型');
        }

        // 检查文件扩展名
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $GLOBALS['allowedExtensions'])) {
            throw new Exception('不支持的文件扩展名');
        }

        // 生成新的文件名
        $newFileName = buildUuidFilename($file['name']);

        // 读取文件内容并转换为Base64
        $base64Content = fileToBase64($file['tmp_name']);

        // 上传到GitHub（传入原始文件名）
        $uploadedPath = uploadToGitHub($file['tmp_name'], $newFileName, $base64Content, $uploadPath, $file['name']);

        // 调试信息
        error_log("Upload path: " . $uploadPath);
        error_log("New filename: " . $newFileName);
        error_log("Uploaded path: " . $uploadedPath);

        // 构建返回的URL - 直接使用GitHub Raw URL确保图片可访问
        // $imageUrl = "https://raw.githubusercontent.com/" . REPO_OWNER . "/" . REPO_NAME . "/" . BRANCH . "/" . $uploadedPath;
        $imageUrl = "https://pic.pipbest.com/" . $uploadedPath;

        error_log("Final URL: " . $imageUrl);

        // 返回MWeb兼容的JSON格式
        return [
            'success' => true,
            'url' => $imageUrl,
            'path' => $uploadedPath,
            'name' => $newFileName,
            'size' => $file['size']
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 配置检查和诊断
 */
function checkConfiguration() {
    $issues = [];

    // 检查GitHub Token
    if (empty(GITHUB_TOKEN) || GITHUB_TOKEN === 'your_github_token_here') {
        $issues[] = 'GitHub Token未配置';
    }

    // 检查仓库信息
    if (empty(REPO_OWNER) || REPO_OWNER === 'your_github_username') {
        $issues[] = 'GitHub用户名未配置';
    }

    if (empty(REPO_NAME) || REPO_NAME === 'your_repository_name') {
        $issues[] = '仓库名称未配置';
    }

    // 现在直接使用GitHub Raw URL，无需域名配置

    return $issues;
}

function isAllowedFilename($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'pdf', 'txt', 'md', 'doc', 'docx'];
    return in_array($ext, $allowed, true);
}

function extractOriginalNameFromCommitMessage($commitMessage) {
    if (!is_string($commitMessage)) return '';
    $pos = strpos($commitMessage, ORIGINAL_FILENAME_PREFIX);
    if ($pos === false) return '';
    $start = $pos + strlen(ORIGINAL_FILENAME_PREFIX);
    $original = trim(substr($commitMessage, $start));
    // 只取到下一个空格或行尾
    $spacePos = strpos($original, ' ');
    if ($spacePos !== false) {
        $original = substr($original, 0, $spacePos);
    }
    return $original;
}

function getCommitMessageForFile($owner, $repo, $path, $ref = null, $token = null) {
    $owner = trim((string)$owner);
    $repo = trim((string)$repo);
    $path = trim((string)$path);
    $path = trim($path, '/');

    $url = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/commits?path=" . rawurlencode($path);
    if (!empty($ref)) {
        $url .= '&sha=' . rawurlencode($ref);
    }

    list($httpCode, $response) = sendGitHubRequest($url, [], 'GET', $token);

    if ($httpCode !== 200) {
        return ['message' => '', 'date' => ''];
    }

    $commits = json_decode($response, true);
    if (!is_array($commits) || empty($commits)) {
        return ['message' => '', 'date' => ''];
    }

    // 取最新 commit 的 message 和 date
    $latestCommit = $commits[0];
    return [
        'message' => $latestCommit['commit']['message'] ?? '',
        'date' => $latestCommit['commit']['committer']['date'] ?? ''
    ];
}

function listFilesRecursive($owner, $repo, $path, $ref = null, $token = null) {
    $owner = trim((string)$owner);
    $repo = trim((string)$repo);
    $path = trim((string)$path);
    $path = trim($path, '/');

    $url = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/contents/" . $path;
    $params = [];
    if (!empty($ref)) {
        $params['ref'] = $ref;
    }
    // GitHub API 默认分页大小为 30，我们设置为 100 以获取更多文件
    $params['per_page'] = 100;
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    list($httpCode, $response) = sendGitHubRequest($url, [], 'GET', $token);

    if ($httpCode !== 200) {
        return [];
    }

    $items = json_decode($response, true);
    if (!is_array($items)) {
        return [];
    }

    $files = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['type'])) {
            continue;
        }

        if ($item['type'] === 'file') {
            if (!isset($item['name']) || !isAllowedFilename($item['name'])) {
                continue;
            }
            // 尝试从 commit message 提取原始文件名和上传时间
            $commitData = getCommitMessageForFile($owner, $repo, $item['path'], $ref, $token);
            $originalName = extractOriginalNameFromCommitMessage($commitData['message']);
            $files[] = [
                'type' => 'file',
                'name' => $item['name'] ?? '',
                'original_name' => $originalName ?: '',
                'upload_time' => $commitData['date'] ?: '',
                'path' => $item['path'] ?? '',
                'sha' => $item['sha'] ?? '',
                'size' => $item['size'] ?? 0,
            ];
            continue;
        }

        if ($item['type'] === 'dir' && isset($item['path'])) {
            $subFiles = listFilesRecursive($owner, $repo, $item['path'], $ref, $token);
            foreach ($subFiles as $sf) {
                $files[] = $sf;
            }
        }
    }

    return $files;
}

/**
 * 处理MWeb上传请求
 * MWeb通常发送POST请求，文件字段名为'file'
 */
/**
 * 删除GitHub上的文件
 */
function deleteFile($path, $sha, $message) {
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/contents/" . ltrim($path, '/');
    
    $payload = [
        'message' => $message,
        'sha' => $sha,
        'branch' => BRANCH
    ];

    list($httpCode, $response) = sendGitHubRequest($url, $payload, 'DELETE');
    
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        throw new Exception('删除失败: ' . ($error['message'] ?? '未知错误'));
    }
    
    return true;
}

function handleMWebUpload() {
    // 添加调试信息
    error_log("=== Upload Request Start ===");
    error_log("Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Files: " . json_encode($_FILES));
    error_log("POST: " . json_encode($_POST));
    error_log("GET: " . json_encode($_GET));
// 处理获取文件信息请求
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'file_info' && isset($_GET['path'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    try {
        $owner = $_GET['owner'] ?? REPO_OWNER;
        $repo = $_GET['repo'] ?? REPO_NAME;
        $ref = $_GET['ref'] ?? BRANCH;
        $token = $_GET['token'] ?? null;

        $filePath = $_GET['path'];
        $url = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/contents/" . ltrim($filePath, '/');
        $url .= '?ref=' . rawurlencode($ref);

        list($httpCode, $response) = sendGitHubRequest($url, [], 'GET', $token);
        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            throw new Exception($data['message'] ?? '获取文件信息失败');
        }

        echo json_encode([
            'success' => true,
            'path' => $filePath,
            'sha' => $data['sha'] ?? null
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
    // 处理删除请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['path']) || !isset($input['sha'])) {
                throw new Exception('缺少必要参数');
            }
            
            deleteFile($input['path'], $input['sha'], $input['message'] ?? ('Delete file: ' . $input['path']));
            
            echo json_encode([
                'success' => true,
                'message' => '文件删除成功'
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        $action = (string)$_GET['action'];

        if ($action === 'list' || $action === 'test_token') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');

            try {
                if ($action === 'list') {
                    $owner = $_GET['owner'] ?? REPO_OWNER;
                    $repo = $_GET['repo'] ?? REPO_NAME;
                    $ref = $_GET['ref'] ?? BRANCH;
                    $token = $_GET['token'] ?? null;

                    $path = $_GET['path'] ?? DEFAULT_TARGET_DIR;
                    $files = listFilesRecursive($owner, $repo, $path, $ref, $token);
                    echo json_encode([
                        'success' => true,
                        'files' => $files,
                    ]);
                    exit;
                }

                if ($action === 'test_token') {
                    $url = "https://api.github.com/user";
                    $token = $_GET['token'] ?? null;
                    list($httpCode, $response) = sendGitHubRequest($url, [], 'GET', $token);
                    $data = json_decode($response, true);

                    if ($httpCode !== 200) {
                        $msg = is_array($data) && isset($data['message']) ? $data['message'] : 'Token 验证失败';
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => $msg,
                        ]);
                        exit;
                    }

                    echo json_encode([
                        'success' => true,
                        'login' => is_array($data) && isset($data['login']) ? $data['login'] : '',
                    ]);
                    exit;
                }
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }
        }
    }

    // 设置响应头
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    // 处理预检请求
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // 只接受POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => '只支持POST请求'
        ]);
        exit;
    }

    // 配置检查（仅在开发/测试时显示）
    if (isset($_GET['check']) || isset($_GET['config'])) {
        $config = [
            'github_token_configured' => !empty(GITHUB_TOKEN) && GITHUB_TOKEN !== 'your_github_token_here',
            'repo_owner' => REPO_OWNER,
            'repo_name' => REPO_NAME,
            'target_dir' => DEFAULT_TARGET_DIR,
            'branch' => BRANCH,
            'url_strategy' => 'GitHub Raw URL',
            'issues' => checkConfiguration()
        ];
        header('Content-Type: application/json');
        echo json_encode($config);
        exit;
    }

    try {
        $result = handleUpload();

        if ($result['success']) {
            http_response_code(200);

            // 记录上传信息（用于调试）
            error_log("Upload success: " . json_encode($result));

            // 确保URL不为空
            if (empty($result['url'])) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => '生成的URL为空，请检查配置'
                ]);
                exit;
            }

            // 为所有客户端返回完整的GitHub Raw URL
            // MWeb会根据配置的"图片URL路径"来提取URL
            // $imageUrl = "https://raw.githubusercontent.com/" . REPO_OWNER . "/" . REPO_NAME . "/" . BRANCH . "/" . $result['path'];
            $imageUrl = "https://pic.pipbest.com/" . $result['path'];
            $response = [
                'success' => true,
                'url' => $imageUrl,        // MWeb主要使用的字段
                'name' => $result['name'], // 文件名
                'path' => $result['path'], // GitHub路径
                'size' => $result['size']  // 文件大小
            ];

            // 如果请求包含debug参数，添加调试信息
            if (isset($_GET['debug']) || isset($_POST['debug'])) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $isMWeb = strpos($userAgent, 'MWeb') !== false;

                $response['debug'] = [
                    'user_agent' => $userAgent,
                    'upload_time' => date('Y-m-d H:i:s'),
                    'target_path' => $result['path'],
                    'is_mweb' => $isMWeb,
                    'repo_info' => [
                        'owner' => REPO_OWNER,
                        'name' => REPO_NAME,
                        'branch' => BRANCH
                    ]
                ];
            }

            echo json_encode($response);
        } else {
            http_response_code(400);
            error_log("Upload failed: " . $result['error']);
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Upload exception: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => '服务器内部错误: ' . $e->getMessage()
        ]);
    }
}

// 执行上传处理
handleMWebUpload();
?>