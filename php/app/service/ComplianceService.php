<?php
declare(strict_types=1);

namespace app\service;

use app\model\AntiBoostingRule;
use app\model\AntiBoostingLog;
use app\model\AgreementRoleVersion;
use app\model\AgreementSignLog;
use app\model\SensitiveWord;
use think\facade\Db;
use think\facade\Log;

class ComplianceService
{
    protected $rules = [];
    protected $rulesLoaded = false;

    protected function loadRules(): void
    {
        if ($this->rulesLoaded) {
            return;
        }
        $this->rules = AntiBoostingRule::enabled()->column('level', 'keyword');
        $this->rulesLoaded = true;
    }

    public function checkContent(string $content, string $source, int $sourceId, int $userId): array
    {
        $this->loadRules();
        $matched = [];
        $highestLevel = '';

        foreach ($this->rules as $keyword => $level) {
            if (mb_strpos($content, $keyword) !== false) {
                $matched[] = [
                    'keyword' => $keyword,
                    'level'   => $level,
                ];
                if (!$highestLevel || $this->compareLevel($level, $highestLevel) > 0) {
                    $highestLevel = $level;
                }
            }
        }

        if (!empty($matched)) {
            foreach ($matched as $item) {
                try {
                    AntiBoostingLog::create([
                        'source'          => $source,
                        'source_id'       => $sourceId,
                        'user_id'         => $userId,
                        'matched_keyword' => $item['keyword'],
                        'level'           => $item['level'],
                        'handled'         => 0,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('记录代练拦截日志失败: ' . $e->getMessage());
                }
            }
        }

        return [
            'matched'       => $matched,
            'highest_level' => $highestLevel,
            'blocked'       => in_array($highestLevel, [AntiBoostingRule::LEVEL_INTERCEPT, AntiBoostingRule::LEVEL_BAN]),
            'ban'           => $highestLevel === AntiBoostingRule::LEVEL_BAN,
        ];
    }

    protected function compareLevel(string $level1, string $level2): int
    {
        $priority = [
            AntiBoostingRule::LEVEL_WARN      => 1,
            AntiBoostingRule::LEVEL_INTERCEPT => 2,
            AntiBoostingRule::LEVEL_BAN       => 3,
        ];
        return ($priority[$level1] ?? 0) - ($priority[$level2] ?? 0);
    }

    public function getAntiBoostingRuleList(string $level = '', int $page = 1, int $limit = 15): array
    {
        $query = AntiBoostingRule::order('id', 'desc');
        if (!empty($level)) {
            $query->byLevel($level);
        }
        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'total' => $total];
    }

    public function createAntiBoostingRule(string $keyword, string $level): array
    {
        $exists = AntiBoostingRule::where('keyword', $keyword)->find();
        if ($exists) {
            throw new \RuntimeException('关键词已存在');
        }
        $rule = AntiBoostingRule::create([
            'keyword' => $keyword,
            'level'   => $level,
            'status'  => AntiBoostingRule::STATUS_ENABLED,
        ]);
        $this->rulesLoaded = false;
        write_action_log('compliance_create_anti_boosting_rule', "创建代练违禁规则: {$keyword}");
        return $rule->toArray();
    }

    public function updateAntiBoostingRule(int $id, array $data): void
    {
        $rule = AntiBoostingRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }
        $rule->save($data);
        $this->rulesLoaded = false;
        write_action_log('compliance_update_anti_boosting_rule', "更新代练违禁规则: ID: {$id}");
    }

    public function deleteAntiBoostingRule(int $id): void
    {
        $rule = AntiBoostingRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }
        $rule->delete();
        $this->rulesLoaded = false;
        write_action_log('compliance_delete_anti_boosting_rule', "删除代练违禁规则: ID: {$id}");
    }

    public function getAntiBoostingLogList(array $params, int $page = 1, int $limit = 15): array
    {
        $query = AntiBoostingLog::order('create_time', 'desc');

        if (!empty($params['source'])) {
            $query->bySource($params['source']);
        }
        if (!empty($params['level'])) {
            $query->byLevel($params['level']);
        }
        if (!empty($params['user_id'])) {
            $query->byUser((int)$params['user_id']);
        }
        if (isset($params['handled']) && $params['handled'] !== '') {
            $query->where('handled', (int)$params['handled']);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function handleAntiBoostingLog(int $logId): void
    {
        $log = AntiBoostingLog::find($logId);
        if (!$log) {
            throw new \RuntimeException('日志不存在');
        }
        $log->handled = 1;
        $log->save();
        write_action_log('compliance_handle_anti_boosting_log', "处理代练拦截日志: ID: {$logId}");
    }

    public function expandSensitiveWords(): void
    {
        $words = [
            ['word' => '游戏代练', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '外挂', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '上分', 'level' => SensitiveWord::LEVEL_SENSITIVE, 'replacement' => ''],
            ['word' => '破解', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '线下交易', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '赌博', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '代练上分', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
            ['word' => '刷段位', 'level' => SensitiveWord::LEVEL_SENSITIVE, 'replacement' => ''],
            ['word' => '刷战力', 'level' => SensitiveWord::LEVEL_SENSITIVE, 'replacement' => ''],
            ['word' => '私下交易', 'level' => SensitiveWord::LEVEL_FORBIDDEN, 'replacement' => ''],
        ];

        foreach ($words as $item) {
            $exists = SensitiveWord::where('word', $item['word'])->find();
            if (!$exists) {
                SensitiveWord::create(array_merge($item, [
                    'status' => SensitiveWord::STATUS_ENABLED,
                ]));
            }
        }
    }

    public function getAgreementVersion(string $role, string $agreementType): ?array
    {
        $version = AgreementRoleVersion::byRole($role)
            ->byType($agreementType)
            ->active()
            ->order('version', 'desc')
            ->find();
        return $version ? $version->toArray() : null;
    }

    public function getAllAgreementVersions(string $role = '', string $agreementType = ''): array
    {
        $query = AgreementRoleVersion::order('role', 'asc')->order('agreement_type', 'asc')->order('version', 'desc');
        if (!empty($role)) {
            $query->byRole($role);
        }
        if (!empty($agreementType)) {
            $query->byType($agreementType);
        }
        return $query->select()->toArray();
    }

    public function createAgreementVersion(string $role, string $agreementType, string $content): array
    {
        Db::startTrans();
        try {
            $latest = AgreementRoleVersion::byRole($role)
                ->byType($agreementType)
                ->order('version', 'desc')
                ->find();
            $newVersion = $latest ? $latest->version + 1 : 1;

            AgreementRoleVersion::where('role', $role)
                ->where('agreement_type', $agreementType)
                ->where('is_active', 1)
                ->update(['is_active' => 0]);

            $version = AgreementRoleVersion::create([
                'role'           => $role,
                'agreement_type' => $agreementType,
                'version'        => $newVersion,
                'content'        => $content,
                'is_active'      => 1,
                'publish_time'   => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
            write_action_log('compliance_create_agreement_version', "创建协议版本: {$role}/{$agreementType}/v{$newVersion}");
            return $version->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建协议版本失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateAgreementVersion(int $id, string $content): void
    {
        $version = AgreementRoleVersion::find($id);
        if (!$version) {
            throw new \RuntimeException('协议版本不存在');
        }
        if ($version->is_active) {
            throw new \RuntimeException('已发布的版本不能修改，请创建新版本');
        }
        $version->content = $content;
        $version->save();
        write_action_log('compliance_update_agreement_version', "更新协议版本: ID: {$id}");
    }

    public function publishAgreementVersion(int $id): void
    {
        Db::startTrans();
        try {
            $version = AgreementRoleVersion::find($id);
            if (!$version) {
                throw new \RuntimeException('协议版本不存在');
            }
            if ($version->is_active) {
                throw new \RuntimeException('该版本已发布');
            }

            AgreementRoleVersion::where('role', $version->role)
                ->where('agreement_type', $version->agreement_type)
                ->where('is_active', 1)
                ->update(['is_active' => 0]);

            $version->is_active    = 1;
            $version->publish_time = date('Y-m-d H:i:s');
            $version->save();

            Db::commit();
            write_action_log('compliance_publish_agreement_version', "发布协议版本: ID: {$id}");
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('发布协议版本失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function checkNeedSign(int $userId, string $role, string $agreementType): bool
    {
        $currentVersion = $this->getAgreementVersion($role, $agreementType);
        if (!$currentVersion) {
            return false;
        }

        $lastSign = AgreementSignLog::byUser($userId)
            ->byRole($role)
            ->byType($agreementType)
            ->order('sign_time', 'desc')
            ->find();

        if (!$lastSign) {
            return true;
        }

        return $lastSign->version < $currentVersion['version'];
    }

    public function signAgreement(int $userId, string $role, string $agreementType, string $ip = '', string $device = ''): array
    {
        $currentVersion = $this->getAgreementVersion($role, $agreementType);
        if (!$currentVersion) {
            throw new \RuntimeException('协议不存在');
        }

        $signLog = AgreementSignLog::create([
            'user_id'        => $userId,
            'role'           => $role,
            'agreement_type' => $agreementType,
            'version'        => $currentVersion['version'],
            'ip'             => $ip,
            'device'         => $device,
        ]);

        write_action_log('compliance_sign_agreement', "签署协议: {$role}/{$agreementType}/v{$currentVersion['version']}, 用户ID: {$userId}");
        return $signLog->toArray();
    }

    public function getSignLogList(int $userId, string $role = '', string $agreementType = ''): array
    {
        $query = AgreementSignLog::byUser($userId)->order('sign_time', 'desc');
        if (!empty($role)) {
            $query->byRole($role);
        }
        if (!empty($agreementType)) {
            $query->byType($agreementType);
        }
        return $query->select()->toArray();
    }
}
