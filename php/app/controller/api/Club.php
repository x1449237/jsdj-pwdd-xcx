<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ClubAbbreviation;
use app\model\SystemConfig;
use app\model\UserVBadge;
use think\facade\Validate;

/**
 * 俱乐部入驻（用户端）
 * 完整7步入驻流程：须知→资料→活体→合同→预览→提交→保证金
 */
class Club extends BaseController
{
    /**
     * 检查俱乐部入驻开关状态
     */
    public function checkSwitch()
    {
        $switch = SystemConfig::getValue('club_join_switch', '1');
        $isOpen = $switch === '1' || $switch === 'true' || $switch === true;

        return $this->success([
            'club_join_open'    => (bool) $isOpen,
            'personal_deposit'  => (int) SystemConfig::getValue('club_personal_deposit', '0'),
            'enterprise_deposit'=> (int) SystemConfig::getValue('club_enterprise_deposit', '0'),
        ]);
    }

    /**
     * 生成拼音首字母大写缩写
     */
    public function generateAbbreviation()
    {
        $clubName = $this->request->param('club_name', '');
        if (empty($clubName)) {
            return $this->error('俱乐部名称不能为空');
        }

        $abbr = $this->pinyinAbbr($clubName);
        $occupied = ClubAbbreviation::isOccupied($abbr);

        $alternatives = [];
        if ($occupied) {
            $operationService = new \app\service\ClubOperationService();
            $alternatives = $operationService->generateAbbrAlternatives($clubName);
        }

        return $this->success([
            'abbreviation' => $abbr,
            'occupied'     => $occupied,
            'alternatives' => $alternatives,
        ]);
    }

    /**
     * 提交入驻申请（全量数据一步提交）
     * 前端多步向导收集完后统一提交
     */
    public function submit()
    {
        $switch = SystemConfig::getValue('club_join_switch', '1');
        $isOpen = $switch === '1' || $switch === 'true' || $switch === true;
        if (!$isOpen) {
            return $this->error('俱乐部入驻功能暂未开放');
        }

        $userId = request()->userId();
        $data   = $this->request->post();

        // 基础校验
        $clubName = $data['club_name'] ?? '';
        $clubType = $data['club_type'] ?? '';
        $isEnterprise = ($clubType === 'blue_v');

        $validate = Validate::rule([
            'club_name' => 'require|length:2,50',
            'club_type' => 'require|in:blue_v,green_v',
            'real_name' => 'require',
            'id_card'   => 'require|length:18,18',
            'phone'     => 'require|length:11,11',
        ]);

        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }

        // 年龄校验：必须满16周岁
        $age = $this->calcAge($data['id_card']);
        if ($age < 16) {
            return $this->error('您未满16周岁，不符合入驻年龄要求');
        }

        // 检查是否已有申请
        $existClub = UserVBadge::where('user_id', $userId)
            ->where('audit_status', 'in', [UserVBadge::AUDIT_PENDING, UserVBadge::AUDIT_PASSED, UserVBadge::AUDIT_NEED_MORE])
            ->where('club_status', 'in', [UserVBadge::STATUS_PENDING, UserVBadge::STATUS_ACTIVE])
            ->find();
        if ($existClub) {
            return $this->error('您已有俱乐部入驻记录，请勿重复申请');
        }

        // 生成缩写并查重
        $abbr = $this->pinyinAbbr($clubName);
        if (ClubAbbreviation::isOccupied($abbr)) {
            return $this->error('检测到俱乐部缩写被占用，请更换俱乐部名称');
        }

        // 企业专属校验
        if ($isEnterprise) {
            if (empty($data['business_license'] ?? '')) {
                return $this->error('请上传营业执照');
            }
            if (empty($data['corporate_bank'] ?? '') || empty($data['corporate_account'] ?? '')) {
                return $this->error('请填写对公账户信息');
            }
        }

        // 合同必须上传
        if (empty($data['contract_file'] ?? '')) {
            return $this->error('请上传已签署的入驻合同');
        }

        // 保证金金额
        $depositKey = $isEnterprise ? 'club_enterprise_deposit' : 'club_personal_deposit';
        $depositAmount = (int) SystemConfig::getValue($depositKey, '0');

        $club = UserVBadge::create([
            'user_id'           => $userId,
            'badge_type'        => $clubType,
            'badge_display'     => $clubType,
            'club_name'         => $clubName,
            'abbreviation'      => $abbr,
            'is_enterprise'     => $isEnterprise ? 1 : 0,
            'audit_status'      => UserVBadge::AUDIT_PENDING,
            'club_status'       => UserVBadge::STATUS_PENDING,
            'deposit_amount'    => $depositAmount,
            'deposit_status'    => UserVBadge::DEPOSIT_UNPAID,
            'real_name'         => $data['real_name'] ?? '',
            'id_card'           => $data['id_card'] ?? '',
            'phone'             => $data['phone'] ?? '',
            'address_province'  => $data['address_province'] ?? '',
            'address_city'      => $data['address_city'] ?? '',
            'address_district'  => $data['address_district'] ?? '',
            'address_detail'    => $data['address_detail'] ?? '',
            'id_card_front'     => $data['id_card_front'] ?? '',
            'id_card_back'      => $data['id_card_back'] ?? '',
            'liveness_status'   => $data['liveness_status'] ?? UserVBadge::LIVENESS_PENDING,
            'contract_file'     => $data['contract_file'] ?? '',
            'business_license'  => $data['business_license'] ?? '',
            'corporate_bank'    => $data['corporate_bank'] ?? '',
            'corporate_account' => $data['corporate_account'] ?? '',
            'handle_type'       => $data['handle_type'] ?? 'self',
            'agent_name'        => $data['agent_name'] ?? '',
            'agent_id_card'     => $data['agent_id_card'] ?? '',
            'agent_id_card_front' => $data['agent_id_card_front'] ?? '',
            'agent_id_card_back'  => $data['agent_id_card_back'] ?? '',
            'agent_authorization' => $data['agent_authorization'] ?? '',
        ]);

        // 封存缩写
        ClubAbbreviation::seal($abbr, $clubName, $club->id, UserVBadge::STATUS_PENDING);

        write_action_log('club_join_submit', "入驻申请: user_id={$userId}, club={$clubName}, abbr={$abbr}, type={$clubType}");

        return $this->success([
            'id'           => $club->id,
            'club_name'    => $club->club_name,
            'abbreviation' => $club->abbreviation,
            'club_type'    => $club->badge_type,
            'audit_status' => $club->audit_status,
            'deposit_amount' => $club->deposit_amount,
        ], '入驻申请已提交，请等待管理员审核');
    }

    /**
     * 补充资料后重新提交
     */
    public function resubmit()
    {
        $userId = request()->userId();
        $clubId = $this->request->paramInt('club_id', 0);

        $club = UserVBadge::where('id', $clubId)
            ->where('user_id', $userId)
            ->where('audit_status', UserVBadge::AUDIT_NEED_MORE)
            ->find();

        if (!$club) {
            return $this->error('未找到需要补充资料的申请', 404);
        }

        $data = $this->request->post();
        $allowedFields = [
            'real_name', 'id_card', 'phone', 'address_province', 'address_city',
            'address_district', 'address_detail', 'id_card_front', 'id_card_back',
            'contract_file', 'business_license', 'corporate_bank', 'corporate_account',
            'agent_name', 'agent_id_card', 'agent_id_card_front', 'agent_id_card_back',
            'agent_authorization',
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $club->setAttr($field, $data[$field]);
            }
        }

        $club->audit_status = UserVBadge::AUDIT_PENDING;
        $club->save();

        write_action_log('club_join_resubmit', "补充资料重提: club_id={$clubId}");

        return $this->success(null, '资料已重新提交');
    }

    /**
     * 缴纳保证金（微信支付后回调确认）
     */
    public function payDeposit()
    {
        $userId = request()->userId();
        $clubId = $this->request->paramInt('club_id', 0);

        $club = UserVBadge::where('id', $clubId)
            ->where('user_id', $userId)
            ->where('audit_status', UserVBadge::AUDIT_PASSED)
            ->where('deposit_status', UserVBadge::DEPOSIT_UNPAID)
            ->find();

        if (!$club) {
            return $this->error('未找到待缴费的入驻申请', 404);
        }

        if ($club->deposit_amount <= 0) {
            // 免保证金，直接激活
            $club->deposit_status   = UserVBadge::DEPOSIT_PAID;
            $club->deposit_pay_time = date('Y-m-d H:i:s');
            $club->is_active        = 1;
            $club->club_status      = UserVBadge::STATUS_ACTIVE;
            $club->save();

            ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => 'active']);

            return $this->success(['is_active' => true], '俱乐部已激活，V标已点亮');
        }

        $transactionId = $this->request->param('transaction_id', '');
        if (empty($transactionId)) {
            return $this->error('支付交易号不能为空');
        }

        $club->deposit_status        = UserVBadge::DEPOSIT_PAID;
        $club->deposit_pay_time      = date('Y-m-d H:i:s');
        $club->deposit_transaction_id = $transactionId;
        $club->is_active             = 1;
        $club->club_status           = UserVBadge::STATUS_ACTIVE;
        $club->save();

        ClubAbbreviation::where('club_id', $clubId)->update(['club_status' => 'active']);

        write_action_log('club_deposit_paid', "保证金缴纳: club_id={$clubId}, amount={$club->deposit_amount}");

        return $this->success(['is_active' => true], '保证金缴纳成功，俱乐部已激活，V标已点亮');
    }

    /**
     * 企业对公打款验证：录入金额和凭证
     */
    public function verifyCorporateTransfer()
    {
        $userId = request()->userId();
        $clubId = $this->request->paramInt('club_id', 0);

        $club = UserVBadge::where('id', $clubId)
            ->where('user_id', $userId)
            ->where('is_enterprise', 1)
            ->where('audit_status', UserVBadge::AUDIT_PASSED)
            ->find();

        if (!$club) {
            return $this->error('未找到有效的企业入驻申请', 404);
        }

        $amount  = $this->request->param('amount', 0);
        $receipt = $this->request->param('receipt', '');

        if ($amount <= 0) {
            return $this->error('请填写转账金额');
        }

        $club->verification_amount = $amount;
        $club->verification_receipt = $receipt;
        $club->verification_status  = UserVBadge::VERIFY_WAITING;
        $club->save();

        return $this->success(null, '打款信息已提交，等待管理员审核');
    }

    /**
     * 获取我的俱乐部入驻状态
     */
    public function myStatus()
    {
        $userId = request()->userId();

        $clubs = UserVBadge::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        return $this->success($clubs ?: []);
    }

    /**
     * 俱乐部列表（公开）
     */
    public function list()
    {
        [$page, $limit] = $this->pageParams();

        $query = UserVBadge::where('audit_status', UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->where('club_status', UserVBadge::STATUS_ACTIVE)
            ->order('create_time', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $user = \app\model\User::find($item['user_id']);
            $item['founder'] = $user ? [
                'id'       => $user->id,
                'nickname' => $user->getData('nickname'),
                'avatar'   => $user->getData('avatar'),
            ] : null;
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 俱乐部详情
     */
    public function detail()
    {
        $clubId = $this->request->param('id', 0);

        $club = UserVBadge::where('id', $clubId)
            ->where('is_active', 1)
            ->where('club_status', UserVBadge::STATUS_ACTIVE)
            ->find();

        if (!$club) {
            return $this->error('俱乐部不存在', 404);
        }

        $clubData = $club->toArray();

        $user = \app\model\User::find($club->user_id);
        $clubData['founder'] = $user ? [
            'id'       => $user->id,
            'nickname' => $user->getData('nickname'),
            'avatar'   => $user->getData('avatar'),
        ] : null;

        $clubData['group_count'] = \app\model\GroupChat::where('creator_id', $club->user_id)
            ->where('status', 1)->count();
        $clubData['up_master_count'] = \app\model\UpMasterCertification::where('club_id', $club->id)
            ->where('audit_status', \app\model\UpMasterCertification::AUDIT_PASSED)
            ->where('is_active', 1)->count();

        $memberService = new \app\service\ClubMemberService();
        $clubData['member_count'] = $memberService->getMemberCount($club->id);

        $operationService = new \app\service\ClubOperationService();

        $announcementResult = $operationService->getAnnouncementList($club->id, 1, 5);
        $clubData['announcements'] = $announcementResult['list'];

        $couponResult = $operationService->getCouponList($club->id, ['page' => 1, 'limit' => 10]);
        $clubData['coupons'] = $couponResult['list'];

        $dynamicResult = $operationService->getDynamicList($club->id, ['page' => 1, 'limit' => 10, 'status' => 1]);
        $clubData['dynamics'] = $dynamicResult['list'];

        $clubData['branches'] = $operationService->getBranchList($club->id);

        $userId = request()->userId();
        if ($userId > 0) {
            $clubData['my_role'] = \app\model\ClubMember::getUserRole($club->id, $userId);
        } else {
            $clubData['my_role'] = null;
        }

        return $this->success($clubData);
    }

    // ==================== 私有方法 ====================

    /**
     * 中文转拼音首字母大写缩写
     */
    private function pinyinAbbr(string $chinese): string
    {
        if (empty($chinese)) return '';
        $abbr = '';
        $len = mb_strlen($chinese, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($chinese, $i, 1, 'UTF-8');
            $abbr .= $this->getFirstLetter($char);
        }
        return strtoupper($abbr);
    }

    /**
     * 获取单个汉字的拼音首字母
     */
    private function getFirstLetter(string $char): string
    {
        if (preg_match('/^[a-zA-Z0-9]$/', $char)) {
            return strtoupper($char);
        }

        $fchar = ord($char[0]);
        if ($fchar >= ord('A') && $fchar <= ord('Z')) return $char;
        if ($fchar >= ord('a') && $fchar <= ord('z')) return strtoupper($char);

        $s1 = iconv('UTF-8', 'GBK//IGNORE', $char);
        if (!$s1 || strlen($s1) < 2) return '';

        $s2 = ord($s1[0]) * 256 + ord($s1[1]);
        if ($s2 < 0xB0A1) return '';

        if ($s2 >= 0xB0C5 && $s2 <= 0xB2C0) return 'A';
        if ($s2 >= 0xB2C1 && $s2 <= 0xB4ED) return 'B';
        if ($s2 >= 0xB4EE && $s2 <= 0xB6E9) return 'C';
        if ($s2 >= 0xB6EA && $s2 <= 0xB7A1) return 'D';
        if ($s2 >= 0xB7A2 && $s2 <= 0xB8C0) return 'E';
        if ($s2 >= 0xB8C1 && $s2 <= 0xB9FD) return 'F';
        if ($s2 >= 0xB9FE && $s2 <= 0xBBF6) return 'G';
        if ($s2 >= 0xBBF7 && $s2 <= 0xBFA5) return 'H';
        if ($s2 >= 0xBFA6 && $s2 <= 0xC0AA) return 'J';
        if ($s2 >= 0xC0AB && $s2 <= 0xC2E7) return 'K';
        if ($s2 >= 0xC2E8 && $s2 <= 0xC4C2) return 'L';
        if ($s2 >= 0xC4C3 && $s2 <= 0xC5B5) return 'M';
        if ($s2 >= 0xC5B6 && $s2 <= 0xC5BD) return 'N';
        if ($s2 >= 0xC5BE && $s2 <= 0xC6D9) return 'O';
        if ($s2 >= 0xC6DA && $s2 <= 0xC8BA) return 'P';
        if ($s2 >= 0xC8BB && $s2 <= 0xC8F5) return 'Q';
        if ($s2 >= 0xC8F6 && $s2 <= 0xCBF9) return 'R';
        if ($s2 >= 0xCBFA && $s2 <= 0xCDD9) return 'S';
        if ($s2 >= 0xCDDA && $s2 <= 0xCEF3) return 'T';
        if ($s2 >= 0xCEF4 && $s2 <= 0xD188) return 'W';
        if ($s2 >= 0xD1B9 && $s2 <= 0xD4D0) return 'X';
        if ($s2 >= 0xD4D1 && $s2 <= 0xD7F9) return 'Y';
        if ($s2 >= 0xD7FA && $s2 <= 0xD7FA) return 'Z';

        return '';
    }

    /**
     * 根据身份证号计算年龄
     */
    private function calcAge(string $idCard): int
    {
        $birth = substr($idCard, 6, 8);
        $year  = (int) substr($birth, 0, 4);
        $month = (int) substr($birth, 4, 2);
        $day   = (int) substr($birth, 6, 2);
        $now   = getdate();
        $age   = $now['year'] - $year;
        if ($now['mon'] < $month || ($now['mon'] == $month && $now['mday'] < $day)) {
            $age--;
        }
        return $age;
    }
}