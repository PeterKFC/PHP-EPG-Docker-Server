<?php

// 禁止输出错误提示
error_reporting(0);

// 设置时区为亚洲/上海
date_default_timezone_set("Asia/Shanghai");

// 引入配置文件
include 'config.php';

// 创建或打开数据库
$db_file = __DIR__ . '/adata.db';
$db = new SQLite3($db_file);

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

    // 频道映射优先级最高，映射成功后不做其他处理
    foreach ($Config['channel_mappings'] as $search => $replace) {
        if (strripos($channel, $search) !== false) {
            $channel = str_ireplace($search, $replace, $channel);
            return strtoupper($channel); // 映射成功后直接返回
        }
    }

    // 清理特定字符串
    $channel = strtoupper(str_replace(' ', '', str_ireplace($Config['channel_replacements'], '', $channel)));

    // 处理CCTV相关频道
    // CCTV1综合、CCTV2财经、CCTV4K 4K……替换成CCTV1、CCTV2、CCTV4K……（排除CCTV4美洲、CCTV4欧洲）
    if (preg_match('/^CCTV[0-9]+(?:K|\+)?(?!(?:美洲|欧洲))/i', $channel, $matches)) {
        $channel = $matches[0];
    }
    // CCTV风云足球、CCTV风云音乐……替换成风云足球、风云音乐……
    $channel = preg_replace('/CCTV(?=\p{Han})/u', '', $channel);

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

// 从数据库中获取XML内容
function getXmlContent($date, $db) {
    $stmt = $db->prepare("SELECT content FROM epg_xml WHERE date = ?");
    $stmt->bindValue(1, $date);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['content'] : null;
}

// 从数据库读取
function readDB($date, $channel, $db) {
    $stmt = $db->prepare("SELECT epg FROM epg_diyp WHERE date = ? AND channel = ?");
    $stmt->bindValue(1, $date);
    $stmt->bindValue(2, $channel);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['epg'] : false;
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
    $channel = cleanChannelName($query_params['ch'] ?? '');

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

    // 从数据库获取数据
    if ($response = readDB($date, $channel, $db)) {
        makeRes($response, $init['status'], $init['headers']);
        return;
    }

    // 无法获取到数据时返回默认数据
    $default_program_info = [
        'date' => $date,
        'channel_name' => $channel,
        'url' => "https://github.com/TakcC/PHP-EPG-Server",
        'epg_data' => [
            'start' => '00:00',
            'end' => '23:59',
            'title' => '未知节目',
            'desc' => ''
        ]
    ];

    // 返回响应
    $response = json_encode($default_program_info);
    makeRes($response, $init['status'], $init['headers']);
}

// 执行请求处理
fetchHandler();

?>