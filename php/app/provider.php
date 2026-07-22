<?php
// +----------------------------------------------------------------------
// | 容器提供者配置
// | 绑定自定义类到容器中
// +----------------------------------------------------------------------

use app\Request;
use app\ExceptionHandle;

return [
    // 绑定自定义 Request 类
    'think\Request'          => Request::class,

    // 绑定自定义异常处理类
    'think\exception\Handle' => ExceptionHandle::class,
];