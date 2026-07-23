<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\UpMasterService;

/**
 * UP主认证管理（管理后台）
 */
class UpMaster extends BaseController
{
    /**
     * 认证列表
     */
    public function list()
    {
        $page        = (int) $this->request->param('page', 1);
        $limit       = (int) $this->request->param('limit', 20);
        $auditStatus = (int) $this->request->param('audit_status', -1);
        $tier        = (int) $this->request->param('tier', 0);

        $service = new UpMasterService();
        $result = $service->getCertificationList($page, $limit, $auditStatus, $tier);

        return $this->page($result['list'], $result['total']);
    }

    /**
     * 审核通过
     */
    public function approve()
    {
        $certId           = (int) $this->request->post('id', 0);
        $verifiedFanCount = (int) $this->request->post('verified_fan_count', 0);
        $remark           = $this->request->post('remark', '');

        try {
            $service = new UpMasterService();
            $service->approveCertification($certId, $this->adminId(), $verifiedFanCount, $remark);
            return $this->success([], '认证已通过');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 审核驳回
     */
    public function reject()
    {
        $certId = (int) $this->request->post('id', 0);
        $remark = $this->request->post('remark', '');

        if (empty($remark)) {
            return $this->error('请填写驳回原因');
        }

        try {
            $service = new UpMasterService();
            $service->rejectCertification($certId, $this->adminId(), $remark);
            return $this->success([], '已驳回');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 吊销认证
     */
    public function revoke()
    {
        $certId = (int) $this->request->post('id', 0);

        try {
            $service = new UpMasterService();
            $service->revokeCertification($certId, $this->adminId());
            return $this->success([], '认证已吊销');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}