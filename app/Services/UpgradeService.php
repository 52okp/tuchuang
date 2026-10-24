<?php

namespace App\Services;

use App\Enums\ConfigKey;
use App\Models\Config;
use App\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;

class UpgradeService
{
    /** @var string 临时目录 */
    protected string $temp = 'storage/temp';

    /** @var string 升级锁文件 */
    protected string $lock = 'upgrading.lock';

    /** @var string 解压隔离目录 */
    protected string $extractDir = 'storage/temp/extract';

    /** @var string 备份目录 */
    protected string $backupDir = 'storage/temp/backup';

    protected Filesystem $filesystem;

    /** @var array|null 检查更新接口返回的最新 release */
    protected ?array $release = null;

    public function __construct(protected string $version)
    {
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter(base_path()));
    }

    /**
     * 是否需要更新
     *
     * @return bool
     * @throws \Exception
     */
    public function check(): bool
    {
        return ! is_null($this->getRelease());
    }

    /**
     * 获取可升级的最新版本（无可用更新返回 null）
     *
     * @return array|null
     * @throws \Exception
     */
    public function getRelease(): ?array
    {
        if (! is_null($this->release)) {
            return $this->release;
        }

        $current = $this->normalizeVersion($this->version);
        $response = $this->http()->get('/api/v1/projects/'.$this->project().'/updates', [
            'current_version' => $current,
            'channel' => config('upgrades.channel', 'stable'),
        ]);

        if ($response->status() === 404) {
            // 服务端无已发布版本
            return $this->release = null;
        }

        if ($response->status() === 401) {
            throw new \Exception('更新服务认证失败：请检查 .env 中的 UPDATES_TOKEN 项目令牌是否正确。');
        }

        if ($response->status() === 400) {
            $message = $response->json('error.message');
            throw new \Exception('更新服务拒绝请求：'.($message ?: '参数无效（current_version/channel）'));
        }

        if (! $response->successful()) {
            throw new \Exception('无法请求升级服务器（HTTP '.$response->status().'）');
        }

        $result = $response->json() ?: [];

        if (empty($result['update_available']) || empty($result['release'])) {
            return $this->release = null;
        }

        $release = $result['release'];

        foreach (['id', 'version', 'from', 'sha256', 'size'] as $field) {
            if (empty($release[$field])) {
                throw new \Exception('更新服务返回的数据缺少必要字段：'.$field);
            }
        }

        // update_available 不代表兼容性，客户端须自行校验 from
        $from = $this->normalizeVersion($release['from']);
        if (version_compare($current, $from) === -1) {
            throw new \Exception("当前版本 {$current} 过低，此更新包要求从 {$from} 及以上版本升级。");
        }

        return $this->release = $release;
    }

    /**
     * 供后台界面展示的版本信息（保持旧版 UI 字段兼容）
     *
     * @return array
     * @throws \Exception
     */
    public function getVersions(): Collection
    {
        $release = $this->getRelease();
        if (is_null($release)) {
            return collect();
        }

        return collect([[
            'id' => $release['id'],
            'name' => $release['version'],
            'size' => $this->humanSize((int) $release['size']),
            'pushed_at' => $release['published_at'] ?? '',
            'changelog' => (new \Parsedown())->text($release['notes'] ?? ''),
            'icon' => asset('favicon.ico'),
            'from' => $release['from'],
            'sha256' => $release['sha256'],
        ]]);
    }

    public function upgrade(): bool
    {
        try {
            if (file_exists(base_path($this->lock))) {
                $this->setProgress('已有升级任务在执行，请稍候...', 'fail');
                return false;
            }

            $release = $this->getRelease();
            if (is_null($release)) {
                $this->setProgress('当前已是最新版本，无需升级', 'fail');
                return false;
            }

            file_put_contents(base_path($this->lock), '');
            $this->setProgress('准备升级...');

            @ini_set('memory_limit', '1G');
            @ini_set('max_execution_time', '86400');

            $this->filesystem->deleteDirectory($this->extractDir);
            $this->filesystem->deleteDirectory($this->backupDir);

            $manifest = $this->fetchManifest($release);
            $packagePath = $this->downloadPackage($release, $manifest);
            $files = $this->extractPackage($packagePath, $release, $manifest);

            $this->setProgress('备份文件...');
            [$overwritten, $added] = $this->backup($files);

            try {
                $this->setProgress('执行升级...');
                foreach ($files as $file) {
                    $this->filesystem->writeStream(
                        $file,
                        fopen(base_path($this->extractDir).'/'.$file, 'r')
                    );
                }

                $this->setProgress('执行数据库迁移...');
                // 生产环境必须带 --force，否则迁移命令会发起交互确认，网页上下文无 STDIN 会抛异常
                Artisan::call('migrate', ['--force' => true, '--seed' => true]);

                $this->setProgress('清除缓存...');
                Artisan::call('optimize:clear');
                Artisan::call('package:discover');

                // 全部成功后才更新版本号，失败回滚时保留旧版本号
                Config::query()->where('name', ConfigKey::AppVersion)
                    ->update(['value' => 'V '.$this->normalizeVersion($release['version'])]);
            } catch (\Throwable $e) {
                // 失败回滚：还原被覆盖的文件、删除新增的文件，保留旧版本
                $this->rollback($overwritten, $added);
                throw $e;
            }
        } catch (\Throwable $e) {
            Utils::e($e, '升级失败');
            $this->setProgress($e->getMessage(), 'fail');
            @unlink(base_path($this->lock));
            return false;
        }
        $this->setProgress('升级成功，请刷新页面', 'success', 6);
        $this->gc();
        return true;
    }

    /**
     * 拉取并校验外部清单（format=2）
     *
     * @param  array  $release
     * @return array
     * @throws \Exception
     */
    protected function fetchManifest(array $release): array
    {
        $this->setProgress('下载更新清单...');

        $url = $this->resolveUrl($release['manifest_url'] ?? '', $release, 'manifest');
        $response = $this->http()->get($url);

        if (! $response->successful()) {
            throw new \Exception('更新清单下载失败（HTTP '.$response->status().'）');
        }

        $body = $response->body();
        if (strlen($body) > (int) config('upgrades.max_manifest_size')) {
            throw new \Exception('更新清单体积超出限制。');
        }

        // 与 release 中的 manifest 资产信息交叉校验
        $asset = $this->findAsset($release, 'manifest');
        if ($asset) {
            if (isset($asset['size']) && strlen($body) !== (int) $asset['size']) {
                throw new \Exception('更新清单大小校验失败。');
            }
            if (isset($asset['sha256']) && ! hash_equals($asset['sha256'], hash('sha256', $body))) {
                throw new \Exception('更新清单 SHA-256 校验失败。');
            }
        }

        $manifest = json_decode($body, true);
        if (! is_array($manifest)) {
            throw new \Exception('更新清单不是有效的 JSON。');
        }

        foreach (['format', 'product', 'version', 'from', 'package', 'size', 'sha256', 'notes'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new \Exception('更新清单缺少必填字段：'.$field);
            }
        }

        if ((int) $manifest['format'] !== 2) {
            throw new \Exception('更新清单 format 版本不受支持：'.$manifest['format']);
        }
        if ($manifest['product'] !== $this->project()) {
            throw new \Exception("更新清单产品名 {$manifest['product']} 与当前项目不符。");
        }
        if ($this->normalizeVersion($manifest['version']) !== $this->normalizeVersion($release['version'])) {
            throw new \Exception('更新清单版本与检查更新接口返回的版本不一致。');
        }
        if (version_compare($this->normalizeVersion($manifest['version']), $this->normalizeVersion($manifest['from'])) !== 1) {
            throw new \Exception('更新清单 version 必须大于 from。');
        }
        if ($manifest['sha256'] !== $release['sha256'] || (int) $manifest['size'] !== (int) $release['size']) {
            throw new \Exception('更新清单与检查更新接口的更新包哈希/大小不一致。');
        }

        return $manifest;
    }

    /**
     * 下载更新包并校验大小与 SHA-256
     *
     * @param  array  $release
     * @param  array  $manifest
     * @return string 包文件的绝对路径
     * @throws \Exception
     */
    protected function downloadPackage(array $release, array $manifest): string
    {
        $this->setProgress('下载更新包...');

        $url = $this->resolveUrl($release['download_url'] ?? '', $release, 'zip');
        $packagePath = base_path($this->temp).'/'.$this->project().'-update.zip';
        if (! is_dir(dirname($packagePath))) {
            @mkdir(dirname($packagePath), 0755, true);
        }

        $maxSize = (int) config('upgrades.max_package_size');
        $expectedSize = (int) $manifest['size'];
        if ($expectedSize > $maxSize) {
            throw new \Exception('更新包体积超出限制。');
        }

        try {
            $response = $this->http()->sink($packagePath)->get($url);
        } catch (ConnectionException $e) {
            throw new \Exception('更新包下载失败：'.$e->getMessage());
        }

        if ($response->redirect()) {
            @unlink($packagePath);
            throw new \Exception('更新服务器返回重定向，已按规范拒绝跟随。');
        }
        if (! $response->successful()) {
            @unlink($packagePath);
            throw new \Exception('更新包下载失败（HTTP '.$response->status().'）');
        }

        $contentLength = (int) ($response->header('Content-Length') ?: 0);
        if ($contentLength && ($contentLength > $maxSize || ($expectedSize && $contentLength !== $expectedSize))) {
            @unlink($packagePath);
            throw new \Exception('更新包大小与清单不符。');
        }

        $actualSize = filesize($packagePath);
        if ($expectedSize && $actualSize !== $expectedSize) {
            @unlink($packagePath);
            throw new \Exception("更新包大小校验失败（期望 {$expectedSize} 字节，实际 {$actualSize} 字节）。");
        }

        $this->setProgress('校验更新包...');
        $actualHash = hash_file('sha256', $packagePath);
        if (! hash_equals($manifest['sha256'], $actualHash)) {
            @unlink($packagePath);
            throw new \Exception('更新包 SHA-256 校验失败。');
        }

        return $packagePath;
    }

    /**
     * 解压更新包到隔离目录，做安全与限制检查，返回相对路径列表
     *
     * @param  string  $packagePath
     * @param  array  $release
     * @param  array  $manifest
     * @return array
     * @throws \Exception
     */
    protected function extractPackage(string $packagePath, array $release, array $manifest): array
    {
        $this->setProgress('解压更新包...');

        $extractBase = base_path($this->extractDir);
        // 确保解压到干净的隔离目录，避免上次残留干扰校验
        if (is_dir($extractBase)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        $zip = new \ZipArchive();
        if (($code = $zip->open($packagePath)) !== true) {
            throw new \Exception('更新包无法打开（ZipArchive 错误码 '.$code.'）');
        }

        try {
            $count = $zip->numFiles;
            if ($count > (int) config('upgrades.max_zip_entries')) {
                throw new \Exception('更新包条目数量超出限制。');
            }

            $totalSize = 0;
            $seen = [];
            $files = [];

            for ($i = 0; $i < $count; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    throw new \Exception('更新包条目读取失败。');
                }

                // 拒绝反斜杠、绝对路径、路径穿越与重复路径
                if (str_contains($name, '\\')) {
                    throw new \Exception("更新包条目 {$name} 含反斜杠，已拒绝。");
                }
                if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new \Exception("更新包条目 {$name} 是绝对路径，已拒绝。");
                }
                $segments = explode('/', rtrim($name, '/'));
                if (in_array('..', $segments, true)) {
                    throw new \Exception("更新包条目 {$name} 含路径穿越，已拒绝。");
                }
                if (isset($seen[$name])) {
                    throw new \Exception("更新包存在重复条目 {$name}，已拒绝。");
                }
                $seen[$name] = true;

                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new \Exception("更新包条目 {$name} 信息读取失败。");
                }

                // 拒绝加密条目
                if (array_key_exists('encryption_method', $stat)
                    && defined('\ZipArchive::EM_NONE')
                    && $stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                    throw new \Exception("更新包条目 {$name} 已加密，已拒绝。");
                }

                if (! str_ends_with($name, '/')) {
                    if ($stat['size'] > (int) config('upgrades.max_entry_size')) {
                        throw new \Exception("更新包条目 {$name} 体积超出限制。");
                    }
                    $totalSize += $stat['size'];
                    if ($totalSize > (int) config('upgrades.max_extract_size')) {
                        throw new \Exception('更新包解压后总体积超出限制。');
                    }
                    $files[] = $name;
                }
            }

            if (! $zip->extractTo($extractBase)) {
                throw new \Exception('更新包解压失败。');
            }

            // 拒绝符号链接与解压后不存在的文件
            foreach ($files as $file) {
                $path = $extractBase.'/'.$file;
                if (is_link($path)) {
                    throw new \Exception("更新包条目 {$file} 是符号链接，已拒绝。");
                }
                if (! is_file($path)) {
                    throw new \Exception("更新包条目 {$file} 解压后缺失。");
                }
            }

            // ZIP 根目录 update-version.json 必须与清单一致
            $versionFile = $extractBase.'/update-version.json';
            if (! is_file($versionFile)) {
                throw new \Exception('更新包缺少 update-version.json。');
            }
            $updateVersion = json_decode(file_get_contents($versionFile), true);
            if (($updateVersion['product'] ?? '') !== $manifest['product']
                || $this->normalizeVersion($updateVersion['version'] ?? '') !== $this->normalizeVersion($manifest['version'])) {
                throw new \Exception('update-version.json 与更新清单不一致。');
            }

            // 运行时/环境文件不参与覆盖
            return array_values(array_diff($files, ['update-version.json', 'installed.lock', '.env']));
        } finally {
            $zip->close();
        }
    }

    /**
     * 备份即将被覆盖的文件
     *
     * @param  array  $files
     * @return array{0: string[] 被覆盖的文件, 1: string[] 新增的文件}
     */
    protected function backup(array $files): array
    {
        $overwritten = [];
        $added = [];
        $backupBase = base_path($this->backupDir);

        foreach ($files as $file) {
            if (file_exists(base_path($file))) {
                $target = $backupBase.'/'.$file;
                if (! is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0755, true);
                }
                copy(base_path($file), $target);
                $overwritten[] = $file;
            } else {
                $added[] = $file;
            }
        }

        return [$overwritten, $added];
    }

    /**
     * 回滚：还原被覆盖的文件并删除本次新增的文件
     *
     * @param  array  $overwritten
     * @param  array  $added
     * @return void
     */
    protected function rollback(array $overwritten, array $added): void
    {
        $backupBase = base_path($this->backupDir);

        foreach ($overwritten as $file) {
            if (file_exists($backupBase.'/'.$file)) {
                if (! is_dir(dirname(base_path($file)))) {
                    @mkdir(dirname(base_path($file)), 0755, true);
                }
                copy($backupBase.'/'.$file, base_path($file));
            }
        }
        foreach ($added as $file) {
            @unlink(base_path($file));
        }
    }

    /**
     * 设置升级进度
     *
     * @param  string  $message
     * @param  string  $status in installing、success、fail
     * @param  int  $ttl
     * @return void
     */
    protected function setProgress(string $message, string $status = 'installing', int $ttl = 1800)
    {
        Cache::put('upgrade_progress', compact('status', 'message'), $ttl);
    }

    /**
     * 获取升级进度
     *
     * @return void
     */
    public function getProgress()
    {
        Cache::get('upgrade_progress');
    }

    protected function gc()
    {
        try {
            $this->filesystem->delete($this->lock);
            $this->filesystem->deleteDirectory($this->temp);
        } catch (FilesystemException $e) {
        }
    }

    /**
     * 统一更新服务的 HTTP 客户端：Bearer 认证、禁止跟随重定向、全程校验证书
     *
     * @return \Illuminate\Http\Client\PendingRequest
     * @throws \Exception
     */
    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $token = (string) config('upgrades.token');
        if ($token === '') {
            throw new \Exception('未配置更新服务令牌，请在 .env 中设置 UPDATES_TOKEN。');
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new \Exception('UPDATES_TOKEN 格式不正确，应为 64 位小写十六进制字符串。');
        }

        $baseUrl = rtrim((string) config('upgrades.base_url'), '/');
        if ($baseUrl === '') {
            throw new \Exception('未配置更新服务地址，请在 .env 中设置 UPDATES_BASE_URL。');
        }

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'connect_timeout' => 10,
            ])
            ->timeout(1800);
    }

    /**
     * @return string
     * @throws \Exception
     */
    protected function project(): string
    {
        $project = trim((string) config('upgrades.project'));
        if ($project === '') {
            throw new \Exception('未配置更新服务项目名，请在 .env 中设置 UPDATES_PROJECT。');
        }
        if (! preg_match('/^[a-z][a-z0-9-]{1,47}$/', $project)) {
            throw new \Exception('UPDATES_PROJECT 项目名格式不正确（2-48 位小写字母/数字/连字符，字母开头）。');
        }
        return $project;
    }

    /**
     * 规范化版本号为 x.y.z：去掉 V 前缀与空白，缺省段补 0
     *
     * @param  string  $version
     * @return string
     * @throws \Exception
     */
    protected function normalizeVersion(string $version): string
    {
        $version = trim(preg_replace('/^[Vv]\s*/', '', trim($version)));
        $parts = explode('.', $version);
        $parts = array_slice($parts, 0, 3);
        $numbers = [];
        foreach ($parts as $part) {
            if (! preg_match('/^\d{1,9}$/', (string) $part)) {
                throw new \Exception("版本号 {$version} 格式不正确，应为 x.y.z。");
            }
            $numbers[] = (int) $part;
        }
        if (! $numbers) {
            throw new \Exception("版本号 {$version} 格式不正确，应为 x.y.z。");
        }
        while (count($numbers) < 3) {
            $numbers[] = 0;
        }
        // 去前导零
        return implode('.', array_map(fn ($n) => (int) $n, $numbers));
    }

    /**
     * 解析下载地址：仅接受更新服务自身域名下的 https 地址或相对路径
     *
     * @param  string  $url
     * @param  array  $release
     * @param  string  $type zip|manifest
     * @return string
     * @throws \Exception
     */
    protected function resolveUrl(string $url, array $release, string $type): string
    {
        if ($url === '') {
            $asset = $this->findAsset($release, $type);
            $url = $asset['url'] ?? '';
        }
        if ($url === '') {
            $url = $release[$type === 'manifest' ? 'manifest_url' : 'download_url'] ?? '';
        }
        if ($url === '') {
            throw new \Exception('更新服务未返回 '.$type.' 的下载地址。');
        }

        if (str_starts_with($url, '/')) {
            return rtrim((string) config('upgrades.base_url'), '/').$url;
        }

        $base = parse_url((string) config('upgrades.base_url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || strcasecmp($host, $base) !== 0) {
            throw new \Exception('下载地址不是更新服务自身的 https 地址，已拒绝。');
        }

        return $url;
    }

    /**
     * @param  array  $release
     * @param  string  $type zip|manifest
     * @return array|null
     */
    protected function findAsset(array $release, string $type): ?array
    {
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['type'] ?? '') === $type || ($asset['name'] ?? '') === $type) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * @param  int  $bytes
     * @return string
     */
    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2).' '.$units[$i];
    }
}
