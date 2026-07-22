<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用地址
    'app_host'         => env('APP.HOST', ''),
    // 应用的命名空间
    'app_namespace'    => 'app',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => env('APP.DEFAULT_TIMEZONE', 'Asia/Shanghai'),

    // 应用映射（自动多应用模式有效）
    'app_map'          => [
        // 'admin' => 'admin',
        // 'api'   => 'api',
    ],

    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [
        // 'admin.example.com'  => 'admin',
        // 'api.example.com'    => 'api',
    ],

    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => ['common'],

    // 异常处理句柄 - 留空使用 \think\exception\Handle
    'exception_handle' => \app\ExceptionHandle::class,

    // 是否启用应用调试模式
    'debug'            => env('APP.DEBUG', false),

    // 默认输出类型
    'default_return_type' => 'json',

    // 默认AJAX数据返回格式
    'default_ajax_return' => 'json',

    // 默认JSONP格式返回的处理方法
    'default_jsonp_handler' => 'jsonpReturn',

    // 默认JSONP处理方法
    'var_jsonp_handler' => 'callback',

    // 默认时区
    'default_timezone' => env('APP.DEFAULT_TIMEZONE', 'Asia/Shanghai'),

    // 是否开启多语言
    'lang_switch_on'   => false,

    // 默认全局过滤方法 用逗号分隔多个
    'default_filter'   => 'htmlspecialchars',

    // 默认语言
    'default_lang'     => 'zh-cn',

    // 应用类库后缀
    'class_suffix'     => false,

    // 控制器类后缀
    'controller_suffix' => false,

    // 是否开启请求缓存
    'request_cache'    => false,
    // 请求缓存有效期
    'request_cache_expire' => null,
    // 全局请求缓存排除规则
    'request_cache_except' => [],

    // 是否开启应用Trace
    'app_trace'        => false,

    // 默认控制器名
    'default_controller' => 'Index',

    // 默认操作名
    'default_action'   => 'index',

    // 操作方法后缀
    'action_suffix'    => '',

    // 默认的空控制器名
    'empty_controller' => 'Error',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',

    // 显示错误信息
    'show_error_msg'   => false,
];