<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\StorageFile;
use yii\base\Exception;
use yii\web\UploadedFile;

class CmsStorageFileService extends AbstractCmsService
{
    public function fileList(array $arguments): array
    {
        $query = StorageFile::find();
        foreach (['cms_site_id', 'mime_type'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $query->andWhere([$key => $arguments[$key]]);
            }
        }
        return $this->page($query->orderBy(['id' => SORT_DESC]), $arguments, [$this, 'fileData']);
    }

    public function fileGet(array $arguments): array
    {
        return $this->fileData($this->find(StorageFile::class, $arguments));
    }

    public function fileUpload(array $arguments): array
    {
        $source = UploadedFile::getInstanceByName('file');
        $temporaryFile = null;
        $filename = $source ? $source->name : null;

        if (!$source && !empty($arguments['source_url'])) {
            $source = $arguments['source_url'];
            $filename = basename((string)parse_url($source, PHP_URL_PATH));
        }
        if (!$source && !empty($arguments['base64'])) {
            $raw = preg_replace('#^data:[^;]+;base64,#', '', (string)$arguments['base64']);
            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) > 20 * 1024 * 1024) {
                throw new Exception('Invalid base64 file or file exceeds 20 MB.');
            }
            $filename = basename((string)($arguments['filename'] ?? 'upload.bin'));
            $temporaryFile = tempnam(sys_get_temp_dir(), 'cms-mcp-');
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            if ($extension) {
                $temporaryFileWithExtension = $temporaryFile.'.'.$extension;
                rename($temporaryFile, $temporaryFileWithExtension);
                $temporaryFile = $temporaryFileWithExtension;
            }
            if (file_put_contents($temporaryFile, $decoded) === false) {
                throw new Exception('Unable to create temporary file.');
            }
            $source = $temporaryFile;
        }
        if (!$source) {
            throw new Exception('Provide multipart file, source_url or base64.');
        }

        try {
            $data = [];
            if (!empty($arguments['cms_site_id'])) {
                $data['cms_site_id'] = (int)$arguments['cms_site_id'];
            }
            if (!empty($filename)) {
                $data['original_name'] = $filename;
            }
            $file = \Yii::$app->storage->upload($source, $data, $arguments['cluster_id'] ?? null);
        } finally {
            if ($temporaryFile && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
        return $this->fileData($file);
    }

    public function fileData(StorageFile $file): array
    {
        return array_merge($file->toArray(), [
            'url' => $file->absoluteSrc,
            'is_image' => $file->isImage(),
        ]);
    }
}
