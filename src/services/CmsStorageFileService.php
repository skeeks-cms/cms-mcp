<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\StorageFile;
use yii\base\Exception;
use yii\web\UploadedFile;

class CmsStorageFileService extends AbstractCmsService
{
    /** Largest file taken by base64 or source_url. */
    public const MAX_SIZE = 20 * 1024 * 1024;

    /**
     * Bytes of a public http(s) address. Every hop (up to 3 redirects) is resolved and must lead to public IPs only;
     * curl is pinned to the checked IP, so a second DNS answer cannot point it inside.
     *
     * @throws Exception
     */
    public function download(string $url): string
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = trim((string)($parts['host'] ?? ''), '[]');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
                throw new Exception('source_url: only a public http(s) address is allowed.');
            }
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
            if (!$ips) {
                throw new Exception('source_url: the host is not found.');
            }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new Exception('source_url: addresses of local and private networks are not allowed.');
                }
            }

            $body = '';
            $tooLarge = false;
            $location = null;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RESOLVE => [$host.':'.$port.':'.(strpos($ips[0], ':') !== false ? '['.$ips[0].']' : $ips[0])],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$location) {
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, &$tooLarge) {
                    if (strlen($body) + strlen($chunk) > self::MAX_SIZE) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($curl);
            $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($tooLarge) {
                throw new Exception('source_url: the file exceeds 20 MB.');
            }
            if (in_array($code, [301, 302, 303, 307, 308], true) && $location) {
                $url = preg_match('~^https?://~i', $location) ? $location : $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$port : '').'/'.ltrim($location, '/');
                continue;
            }
            if ($code !== 200) {
                throw new Exception('source_url: the address answered '.($code ?: $error).'.');
            }

            return $body;
        }

        throw new Exception('source_url: too many redirects.');
    }

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

        $decoded = null;
        if (!$source && !empty($arguments['source_url'])) {
            // Only a public http(s) address: a path would read (and move) a file of the server.
            $url = (string)$arguments['source_url'];
            $decoded = $this->download($url);
            $filename = basename((string)($arguments['filename'] ?? '')) ?: (basename((string)parse_url($url, PHP_URL_PATH)) ?: 'upload.bin');
        } elseif (!$source && !empty($arguments['base64'])) {
            $raw = preg_replace('#^data:[^;]+;base64,#', '', (string)$arguments['base64']);
            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) > self::MAX_SIZE) {
                throw new Exception('Invalid base64 file or file exceeds 20 MB.');
            }
            $filename = basename((string)($arguments['filename'] ?? 'upload.bin'));
        }
        // Never a script in the public storage, whatever its content.
        $extension = strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION));
        if (preg_match('~^(php\d?|phtml|pht|phar|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml|htaccess|htpasswd)$~', $extension)) {
            throw new Exception('Files of type .'.$extension.' are not stored.');
        }
        if ($decoded !== null) {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'cms-mcp-');
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
