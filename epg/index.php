<?php
/**
 * @file index.php
 * @brief 主页处理脚本
 * 
 * 该脚本处理来自客户端的请求，根据查询参数获取指定日期和频道的节目信息，
 * 并从 SQLite 数据库中提取或返回默认数据。
 * 
 * 作者: Tak
 * GitHub: https://github.com/TakcC/PHP-EPG-Docker-Server
 */

// 禁止输出错误提示
// error_reporting(0);

// 设置时区为亚洲/上海
date_default_timezone_set("Asia/Shanghai");

// 引入配置文件
include 'config.php';

// 创建或打开数据库
$db_file = __DIR__ . '/adata.db';

// 使用 PDO 连接 SQLite 数据库
try {
    $db = new PDO('sqlite:' . $db_file);
    // 设置 PDO 错误模式为异常
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 初始化响应头信息
$init = [
    'status' => 200,
    'headers' => [
        'content-type' => 'application/json'
    ]
];

// 添加频道处理函数
function cleanChannelName($channel) {
    global $Config;

    // 频道映射，优先级最高，匹配后直接返回，支持正则表达式映射，以 regex: 开头
    foreach ($Config['channel_mappings'] as $search => $replace) {
        // 检查是否为正则表达式映射
        if (strpos($search, 'regex:') === 0) {
            $pattern = substr($search, 6);
            if (preg_match($pattern, $channel)) {
                $channel = preg_replace($pattern, $replace, $channel);
                return strtoupper($channel);
            }
        } else {
            // 非正则表达式映射
            if (strtoupper($channel) === strtoupper($search)) {
                $channel = $replace;                
                return strtoupper($channel);
            }
        }
    }

    // 清理特定字符串
    $channel = strtoupper(str_replace(' ', '', str_ireplace($Config['channel_replacements'], '', $channel)));

    return $channel;
}

// 生成响应
function makeRes($body, $status = 200, $headers = []) {
    $headers['Access-Control-Allow-Origin'] = '*';
    http_response_code($status);
    foreach ($headers as $key => $value) {
        header("$key: $value");
    }
    echo $body;
}

// 获取当前日期
function getNowDate() {
    return date('Y-m-d');
}

// 格式化时间
function getFormatTime($time) {
    if (strlen($time) < 8) {
        return ['date' => getNowDate(), 'time' => ''];
    }

    $date = substr($time, 0, 4) . '-' . substr($time, 4, 2) . '-' . substr($time, 6, 2);
    $time = strlen($time) >= 12 ? substr($time, 8, 2) . ':' . substr($time, 10, 2) : '';

    return ['date' => $date, 'time' => $time];
}

// 从数据库中获取 XML 内容
function getXmlContent($date, $db) {
    $stmt = $db->prepare("SELECT content FROM epg_xml WHERE date = :date");
    $stmt->bindParam(':date', $date);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['content'] : null;
}

// 从数据库读取 diyp、lovetv 数据
function readEPGData($date, $channel, $db, $type) {
    $field = $type === 'diyp' ? 'epg_diyp' : 'epg_lovetv';
    $stmt = $db->prepare("SELECT $field FROM epg_data WHERE date = :date AND channel = :channel");
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':channel', $channel);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row[$field] : false;
}

// 获取当前时间戳
function getNowTimestamp() {
    return time();
}

// 查找当前节目
function findCurrentProgramme($programmes) {
    $now = getNowTimestamp();
    foreach ($programmes as $programme) {
        if ($programme['st'] <= $now && $programme['et'] >= $now) {
            return $programme;
        }
    }
    return null;
}

// 处理请求
function fetchHandler() {
    global $init, $db;

    $uri = parse_url($_SERVER['REQUEST_URI']);
    $query_params = [];
    if (isset($uri['query'])) {
        parse_str($uri['query'], $query_params);
    }

    // 获取并清理频道名称
    $channel = cleanChannelName($query_params['ch'] ?? $query_params['channel'] ?? '');

    $date = isset($query_params['date']) ? getFormatTime(preg_replace('/\D+/', '', $query_params['date']))['date'] : getNowDate();

    // 频道参数为空时，直接返回xml文件
    if (empty($channel)) {
        $xml_content = getXmlContent($date, $db);
        if ($xml_content) {
            $init['headers']['content-type'] = 'text/xml';
            makeRes($xml_content, $init['status'], $init['headers']);
        } else {
            makeRes('', $init['status'], $init['headers']);
        }
        return;
    }

    // 返回 diyp、lovetv 数据
    if (isset($query_params['ch']) || isset($query_params['channel'])) {
        $type = isset($query_params['ch']) ? 'diyp' : 'lovetv';
        $response = readEPGData($date, $channel, $db, $type);
    
        if ($response) {
            if ($type === 'lovetv') {
                $lovetv_data = json_decode($response, true);
                $programmes = $lovetv_data[$channel]['program'];
                $current_programme = findCurrentProgramme($programmes);
                if ($current_programme) {
                    $lovetv_data[$channel]['isLive'] = $current_programme['t'];
                    $lovetv_data[$channel]['liveSt'] = $current_programme['st'];
                }
                $response = json_encode($lovetv_data, JSON_UNESCAPED_UNICODE);
            }
            makeRes($response, $init['status'], $init['headers']);
        } else {
            if ($type === 'diyp') {
                // 无法获取到数据时返回默认 diyp 数据
                $default_diyp_program_info = [
                    'date' => $date,
                    'channel_name' => $channel,
                    'url' => "https://github.com/TakcC/PHP-EPG-Server",
                    'epg_data' => array_map(function($hour) {
                        return [
                            'start' => sprintf('%02d:00', $hour),
                            'end' => sprintf('%02d:00', ($hour + 2) % 24),
                            'title' => '精彩节目',
                            'desc' => ''
                        ];
                    }, range(0, 22, 2))
                ];
                $response = json_encode($default_diyp_program_info, JSON_UNESCAPED_UNICODE);
            } else {
                // 无法获取到数据时返回默认 lovetv 数据
                $default_lovetv_program_info = [
                    $channel => [
                        'isLive' => '',
                        'liveSt' => 0,
                        'channelName' => $channel,
                        'lvUrl' => 'https://github.com/TakcC/PHP-EPG-Docker-Server',
                        'program' => array_map(function($hour) {
                            return [
                                'st' => strtotime(sprintf('%02d:00', $hour)),
                                'et' => strtotime(sprintf('%02d:00', ($hour + 2) % 24)),
                                't' => '精彩节目',
                                'd' => ''
                            ];
                        }, range(0, 22, 2))
                    ]
                ];
                $response = json_encode($default_lovetv_program_info, JSON_UNESCAPED_UNICODE);
            }
            makeRes($response, $init['status'], $init['headers']);
        }
    }

    // 默认响应
    makeRes('', $init['status'], $init['headers']);
}

// 执行请求处理
fetchHandler();

?>
