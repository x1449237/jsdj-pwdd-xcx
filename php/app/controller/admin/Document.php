<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\DocumentVersion;
use app\model\PlatformDocument;
use think\Request;

/**
 * 平台文档管理（协议/政策/合同）
 * 仅超级管理员（admin/admin2）可操作
 */
class Document extends BaseController
{
    /**
     * 文档列表
     */
    public function list(Request $request)
    {
        $docType = $request->param('doc_type', '');

        $query = PlatformDocument::with(['admin'])->order('create_time', 'desc');

        if (!empty($docType)) {
            $query->where('doc_type', $docType);
        }

        $list = $query->select()->toArray();

        $this->operationLog('admin_document_list', '查看平台文档列表');

        return $this->success($list);
    }

    /**
     * 上传PDF文档
     */
    public function upload(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            return $this->error('请选择文件');
        }

        $title   = $request->param('title', '');
        $docType = $request->param('doc_type', '');

        if (empty($title)) {
            return $this->error('文档标题不能为空');
        }
        if (!in_array($docType, ['agreement', 'policy', 'contract'])) {
            return $this->error('文档类型无效（仅支持: agreement/policy/contract）');
        }

        // 严格限定PDF后缀
        $ext = strtolower($file->getOriginalExtension());
        if ($ext !== 'pdf') {
            return $this->error('仅支持上传PDF格式文件，当前文件后缀: ' . $ext);
        }

        // 验证MIME类型
        $mime = $file->getMime();
        $allowedMime = ['application/pdf', 'application/x-pdf'];
        if (!in_array($mime, $allowedMime)) {
            return $this->error('文件类型校验失败，请上传有效的PDF文件');
        }

        // 文件大小限制 20MB
        $maxSize = 20 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return $this->error('文件大小不能超过20MB');
        }

        try {
            $saveDir = public_path() . 'uploads/documents/';
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }

            $fileName = $docType . '_' . date('YmdHis') . '_' . generate_sn('DOC') . '.pdf';
            $file->move($saveDir, $fileName);

            $fileUrl = '/uploads/documents/' . $fileName;

            $doc = PlatformDocument::create([
                'doc_type'  => $docType,
                'title'     => $title,
                'file_url'  => $fileUrl,
                'file_name' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
                'version'   => 1,
                'admin_id'  => $this->adminId(),
                'is_active' => 1,
            ]);

            // 创建初始版本记录
            DocumentVersion::create([
                'document_id' => $doc->id,
                'version'     => 1,
                'file_url'    => $fileUrl,
                'file_name'   => $file->getOriginalName(),
                'file_size'   => $file->getSize(),
                'admin_id'    => $this->adminId(),
            ]);

            $this->operationLog('admin_document_upload', "上传文档: {$title} (类型: {$docType})");

            return $this->success($doc->toArray(), '上传成功');
        } catch (\Throwable $e) {
            return $this->error('文件上传失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 替换文档（上传新PDF替换旧文件，版本号自动递增）
     */
    public function replace(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('文档ID无效');
        }

        $doc = PlatformDocument::find($id);
        if (!$doc) {
            return $this->error('文档不存在', 404);
        }

        $file = $request->file('file');
        if (!$file) {
            return $this->error('请选择要替换的PDF文件');
        }

        // 严格限定PDF后缀
        $ext = strtolower($file->getOriginalExtension());
        if ($ext !== 'pdf') {
            return $this->error('仅支持上传PDF格式文件，当前文件后缀: ' . $ext);
        }

        // 验证MIME类型
        $mime = $file->getMime();
        $allowedMime = ['application/pdf', 'application/x-pdf'];
        if (!in_array($mime, $allowedMime)) {
            return $this->error('文件类型校验失败，请上传有效的PDF文件');
        }

        $maxSize = 20 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return $this->error('文件大小不能超过20MB');
        }

        try {
            // 保存旧版本到历史记录
            DocumentVersion::create([
                'document_id' => $doc->id,
                'version'     => $doc->version,
                'file_url'    => $doc->file_url,
                'file_name'   => $doc->file_name,
                'file_size'   => $doc->file_size,
                'admin_id'    => $doc->admin_id,
            ]);

            // 旧文件保留不删除，仅逻辑上替换到新文件
            $saveDir = public_path() . 'uploads/documents/';
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }

            $fileName = $doc->doc_type . '_' . date('YmdHis') . '_' . generate_sn('DOC') . '.pdf';
            $file->move($saveDir, $fileName);

            $doc->file_url  = '/uploads/documents/' . $fileName;
            $doc->file_name = $file->getOriginalName();
            $doc->file_size = $file->getSize();
            $doc->version   = $doc->version + 1;
            $doc->admin_id  = $this->adminId();
            $doc->is_active = 1;
            $doc->save();

            $this->operationLog('admin_document_replace', "替换文档: {$doc->title} (版本: {$doc->version})");

            return $this->success($doc->toArray(), '替换成功，版本已更新至 v' . $doc->version);
        } catch (\Throwable $e) {
            return $this->error('文件替换失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 删除文档（逻辑删除，不删除物理文件）
     */
    public function delete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('文档ID无效');
        }

        $doc = PlatformDocument::find($id);
        if (!$doc) {
            return $this->error('文档不存在', 404);
        }

        $title = $doc->title;
        $doc->softDelete($this->adminId());

        $this->operationLog('admin_document_delete', "逻辑删除文档: {$title} (ID: {$id})");

        return $this->success(null, '文档已删除（逻辑删除，文件保留）');
    }

    /**
     * 启用/禁用文档
     */
    public function toggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('文档ID无效');
        }

        $doc = PlatformDocument::find($id);
        if (!$doc) {
            return $this->error('文档不存在', 404);
        }

        $doc->is_active = $doc->is_active ? 0 : 1;
        $doc->save();

        $status = $doc->is_active ? '启用' : '禁用';
        $this->operationLog('admin_document_toggle', "{$status}文档: {$doc->title}");

        return $this->success($doc->toArray(), "文档已{$status}");
    }

    /**
     * 获取文档所有历史版本（按版本号降序）
     */
    public function versions(Request $request)
    {
        $documentId = $request->paramInt('document_id', 0);
        if ($documentId <= 0) {
            return $this->error('文档ID无效');
        }

        $doc = PlatformDocument::find($documentId);
        if (!$doc) {
            return $this->error('文档不存在', 404);
        }

        // 历史版本 + 当前版本合并
        $history = DocumentVersion::where('document_id', $documentId)
            ->order('version', 'desc')
            ->select()
            ->toArray();

        // 当前版本也加入列表
        $current = [
            'id'          => 0,
            'document_id' => $doc->id,
            'version'     => $doc->version,
            'file_url'    => $doc->file_url,
            'file_name'   => $doc->file_name,
            'file_size'   => $doc->file_size,
            'admin_id'    => $doc->admin_id,
            'create_time' => $doc->update_time,
            'is_current'  => true,
        ];

        foreach ($history as &$item) {
            $item['is_current'] = false;
        }

        // 当前版本在最前面
        $all = array_merge([$current], $history);

        return $this->success($all);
    }
}