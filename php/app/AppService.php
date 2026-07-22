<?php
declare(strict_types=1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    /**
     * 服务注册
     */
    public function register()
    {
        // 注册应用服务
        $this->app->bind('app\service\AppService', \app\service\AppService::class);
    }

    /**
     * 服务启动引导
     */
    public function boot()
    {
        // 注册自定义验证规则
        $this->registerValidator();

        // 注册自定义指令
        $this->registerCommands();
    }

    /**
     * 注册自定义验证规则
     */
    protected function registerValidator()
    {
        // 手机号验证
        \think\facade\Validate::maker(function ($validate) {
            $validate->extend('mobile', function ($value) {
                return (bool) preg_match('/^1[3-9]\d{9}$/', $value);
            }, '手机号格式不正确');

            // 身份证号验证
            $validate->extend('id_card', function ($value) {
                return (bool) preg_match('/^[1-9]\d{5}(18|19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/', $value);
            }, '身份证号格式不正确');

            // 银行卡号验证
            $validate->extend('bank_card', function ($value) {
                return (bool) preg_match('/^\d{16,19}$/', $value);
            }, '银行卡号格式不正确');

            // 密码强度验证
            $validate->extend('password', function ($value) {
                return (bool) preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d!@#$%^&*()_+]{8,20}$/', $value);
            }, '密码必须包含大小写字母和数字，长度8-20位');

            // 金额验证
            $validate->extend('money', function ($value) {
                return (bool) preg_match('/^\d+(\.\d{1,2})?$/', $value);
            }, '金额格式不正确');
        });
    }

    /**
     * 注册自定义指令
     */
    protected function registerCommands()
    {
        // 命令行指令注册
        $this->commands([
            // \app\command\InitData::class,
            // \app\command\ClearCache::class,
        ]);
    }
}