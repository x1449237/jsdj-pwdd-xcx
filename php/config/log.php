<?php
// +----------------------------------------------------------------------
// | 日志配置 - 支持敏感数据脱敏
// +----------------------------------------------------------------------

return [
    // 默认日志记录通道
    'default'      => env('LOG.CHANNEL', 'file'),

    // 日志记录级别
    'level'        => [
        'error',
        'warning',
        'info',
        'sql',
        'notice',
        'alert',
        'debug',
    ],

    // 日志通道
    'channels'     => [
        // 文件日志
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => app()->getRuntimePath() . 'log',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => ['error', 'sql'],
            // 最大日志文件数量
            'max_files'      => env('LOG.MAX_FILES', 30),
            // 日志格式
            'format'         => '[%s][%s] %s',
            // 是否JSON格式记录
            'json'           => true,
            // 日志时间格式
            'time_format'    => 'Y-m-d H:i:s',
            // 是否实时写入
            'realtime_write' => true,
            // 敏感字段脱敏
            'sensitive_filters' => true,
        ],

        // 错误日志单独通道
        'error' => [
            'type'           => 'File',
            'path'           => app()->getRuntimePath() . 'log/error',
            'single'         => true,
            'max_files'      => 30,
            'json'           => true,
            'time_format'    => 'Y-m-d H:i:s',
            'realtime_write' => true,
            'sensitive_filters' => true,
        ],

        // SQL 日志通道
        'sql' => [
            'type'           => 'File',
            'path'           => app()->getRuntimePath() . 'log/sql',
            'single'         => true,
            'max_files'      => 7,
            'json'           => true,
            'time_format'    => 'Y-m-d H:i:s',
            'realtime_write' => false,
            'sensitive_filters' => true,
        ],

        // 操作日志（审计日志）
        'action' => [
            'type'           => 'File',
            'path'           => app()->getRuntimePath() . 'log/action',
            'single'         => false,
            'max_files'      => 90,
            'json'           => true,
            'time_format'    => 'Y-m-d H:i:s',
            'realtime_write' => true,
            'sensitive_filters' => true,
        ],
    ],

    // 敏感数据脱敏配置
    'sensitive' => [
        // 是否启用敏感数据脱敏
        'enable' => true,
        // 需要脱敏的字段名（不区分大小写）
        'fields' => [
            'password',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'api_secret',
            'id_card',
            'id_card_number',
            'bank_card',
            'bank_card_number',
            'phone',
            'mobile',
            'real_name',
            'openid',
            'unionid',
            'session_key',
            'pay_password',
            'sign',
            'signature',
            'credit_card',
            'cvv',
            'cert',
            'private_key',
            'public_key',
        ],
        // 脱敏方式：replace(替换为***), mask(部分掩码), remove(完全移除)
        'method' => 'mask',
        // 掩码配置
        'mask' => [
            'phone'       => ['start' => 3, 'length' => 4, 'char' => '*'],
            'id_card'     => ['start' => 6, 'length' => 8, 'char' => '*'],
            'bank_card'   => ['start' => 4, 'length' => 10, 'char' => '*'],
            'real_name'   => ['start' => 0, 'length' => 1, 'char' => '*'],
            'default'     => ['start' => 0, 'length' => 0, 'char' => '***'],
        ],
    ],

    // trace_id 配置
    'trace_id' => [
        // 是否在日志中记录 trace_id
        'enable' => true,
        // trace_id 字段名
        'field'  => 'trace_id',
    ],
];