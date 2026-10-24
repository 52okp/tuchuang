<?php
/**
 * UpgradeService::upgrade() 全流程模拟测试（不依赖 Laravel）
 * 成功路径 + 迁移失败回滚路径。运行：php tests/standalone/UpgradeServiceFlowTest.php
 */

namespace App { class Utils { public static function e($e, $m = '') {} } }
namespace Illuminate\Support { class Collection {
    public function __construct(public array $items = []) {}
    public function first() { return $this->items[0] ?? null; }
} }
namespace { class Parsedown {
    public function text(string $text): string {
        // 测试桩：仅支持一级标题
        $lines = explode("\n", trim($text));
        $out = '';
        foreach ($lines as $line) {
            $out .= str_starts_with($line, '# ') ? '<h1>'.substr($line, 2).'</h1>' : '<p>'.$line.'</p>';
        }
        return $out;
    }
} }
namespace App\Enums { class ConfigKey { const AppVersion = 'app_version'; } }
namespace App\Models {
    class ConfigQuery {
        public function where($col, $val) { return $this; }
        public function update($arr) { $GLOBALS['__config_db']['app_version'] = $arr['value']; return 1; }
    }
    class Config { public static function query() { return new ConfigQuery(); } }
}
namespace Illuminate\Support\Facades {
    class Http extends \Illuminate\Http\Client\Http {}
    class Artisan {
        public static array $calls = [];
        public static $failOn = null;
        public static function call($command, $args = []) {
            self::$calls[] = $command;
            if (self::$failOn !== null && str_starts_with($command, self::$failOn)) {
                throw new \Exception('模拟迁移失败');
            }
            return 0;
        }
    }
    class Cache { public static function put(...$a) {} public static function get(...$a) {} }
}
namespace Illuminate\Http\Client {
    class PendingRequest {
        public $sinkPath = null;
        public function __call($m, $a) {
            if ($m === 'sink') { $this->sinkPath = $a[0]; return $this; }
            if ($m === 'get') { return Http::route($a[0], $a[1] ?? [], $this->sinkPath); }
            return $this;
        }
    }
    class Response {
        public function __construct(public int $code, public string $body, public array $headers = []) {}
        public function status(): int { return $this->code; }
        public function successful(): bool { return $this->code >= 200 && $this->code < 300; }
        public function failed(): bool { return $this->code >= 400; }
        public function redirect(): bool { return $this->code >= 300 && $this->code < 400; }
        public function body(): string { return $this->body; }
        public function json($key = null, $default = null) {
            $d = json_decode($this->body, true) ?: [];
            return $key === null ? $d : ($d[$key] ?? $default);
        }
        public function header($name): string { return $this->headers[strtolower($name)] ?? ''; }
    }
    class Http {
        public static $handler = null;
        public static function __callStatic($m, $a) { return new PendingRequest(); }
        public static function route(string $url, array $query, $sink) {
            $resp = (self::$handler)($url, $query);
            if ($sink !== null && $resp->successful()) {
                @mkdir(dirname($sink), 0777, true);
                file_put_contents($sink, $resp->body);
            }
            return $resp;
        }
    }
}
namespace League\Flysystem {
    class FilesystemException extends \RuntimeException {}
    class Filesystem {
        public function __construct(protected $adapter = null) {}
        public function writeStream(string $path, $resource): bool {
            $target = \base_path($path);
            @mkdir(dirname($target), 0777, true);
            $dst = fopen($target, 'w');
            stream_copy_to_stream($resource, $dst);
            fclose($dst);
            return true;
        }
        public function delete(string $path): bool { @unlink(\base_path($path)); return true; }
        public function deleteDirectory(string $path): bool {
            $dir = \base_path($path);
            if (! is_dir($dir)) return true;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            return @rmdir($dir);
        }
    }
}
namespace League\Flysystem\Local { class LocalFilesystemAdapter { public function __construct($a = null) {} } }

namespace {

error_reporting(E_ALL);

$GLOBALS['__test_base'] = sys_get_temp_dir().'/tuchuang-upgrade-flow';
$GLOBALS['__test_config'] = [
    'upgrades.base_url' => 'https://app.52okp.com',
    'upgrades.project' => 'tuchuang',
    'upgrades.channel' => 'stable',
    'upgrades.token' => str_repeat('a', 64),
    'upgrades.max_package_size' => 200 * 1024 * 1024,
    'upgrades.max_manifest_size' => 4 * 1024 * 1024,
    'upgrades.max_zip_entries' => 10000,
    'upgrades.max_entry_size' => 50 * 1024 * 1024,
    'upgrades.max_extract_size' => 1024 * 1024 * 1024,
];

function base_path($path = ''): string
{
    return $GLOBALS['__test_base'].($path !== '' ? '/'.$path : '');
}
function config($key, $default = null)
{
    return $GLOBALS['__test_config'][$key] ?? $default;
}
function collect(array $items = []): \Illuminate\Support\Collection
{
    return new \Illuminate\Support\Collection($items);
}
function asset(string $path): string
{
    return '/'.$path;
}

require __DIR__.'/../../app/Services/UpgradeService.php';

eval(<<<'PHP'
namespace App\Services;
class TestUpgradeService extends UpgradeService {
    protected function setProgress(string $message, string $status = 'installing', int $ttl = 1800) {
        $GLOBALS['__progress'][] = [$status, $message];
    }
}
PHP);

$pass = 0; $fail = 0;
function ok(bool $cond, string $name, string $detail = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}  {$detail}\n"; }
}

// ---- 构造固定投放物 ----
$releaseId = str_repeat('b', 64);
$zipBytes = (function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
    $z = new \ZipArchive();
    $z->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('update-version.json', json_encode(['product' => 'tuchuang', 'version' => '2.2.0']));
    $z->addFromString('app/Old.php', '<?php // new version of Old');
    $z->addFromString('app/New.php', '<?php // brand new file');
    $z->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
})();
$manifest = json_encode([
    'format' => 2, 'product' => 'tuchuang', 'version' => '2.2.0', 'from' => '2.1.0',
    'package' => 'tuchuang-update.zip', 'size' => strlen($zipBytes),
    'sha256' => hash('sha256', $zipBytes), 'notes' => '# 更新说明',
]);
$release = [
    'id' => $releaseId, 'version' => '2.2.0', 'from' => '2.1.0', 'status' => 'published',
    'notes' => '# 更新说明', 'published_at' => '2026-10-01T00:00:00Z', 'verification' => 'github-sha256',
    'size' => strlen($zipBytes), 'sha256' => hash('sha256', $zipBytes),
    'download_url' => 'https://app.52okp.com/api/v1/projects/tuchuang/releases/'.$releaseId.'/package',
    'manifest_url' => 'https://app.52okp.com/api/v1/projects/tuchuang/releases/'.$releaseId.'/manifest',
    'assets' => [
        ['type' => 'zip', 'size' => strlen($zipBytes), 'sha256' => hash('sha256', $zipBytes), 'url' => 'https://app.52okp.com/api/v1/projects/tuchuang/releases/'.$releaseId.'/package'],
        ['type' => 'manifest', 'size' => strlen($manifest), 'sha256' => hash('sha256', $manifest), 'url' => 'https://app.52okp.com/api/v1/projects/tuchuang/releases/'.$releaseId.'/manifest'],
    ],
];

\Illuminate\Support\Facades\Http::$handler = function (string $url, array $query) use ($releaseId, $release, $manifest, $zipBytes) {
    if (str_contains($url, '/updates')) {
        $body = json_encode(['api_version' => '1', 'project' => 'tuchuang', 'channel' => 'stable', 'update_available' => true, 'release' => $release]);
        return new \Illuminate\Http\Client\Response(200, $body, ['content-type' => 'application/json']);
    }
    if (str_contains($url, '/manifest')) {
        return new \Illuminate\Http\Client\Response(200, $manifest, ['content-type' => 'application/json', 'content-length' => strlen($manifest)]);
    }
    if (str_contains($url, '/package')) {
        return new \Illuminate\Http\Client\Response(200, $zipBytes, ['content-type' => 'application/octet-stream', 'content-length' => strlen($zipBytes)]);
    }
    return new \Illuminate\Http\Client\Response(404, json_encode(['error' => ['code' => 'not_found', 'message' => 'x']]));
};

// ---- 测试脚本 ----
$resetBase = function () {
    $base = $GLOBALS['__test_base'];
    if (is_dir($base)) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($base);
    }
    @mkdir($base.'/app', 0777, true);
    file_put_contents($base.'/app/Old.php', '<?php // OLD content');
    $GLOBALS['__config_db'] = ['app_version' => 'V 2.1'];
    $GLOBALS['__progress'] = [];
    \Illuminate\Support\Facades\Artisan::$calls = [];
    \Illuminate\Support\Facades\Artisan::$failOn = null;
};

$newService = function () {
    $svc = (new \ReflectionClass(\App\Services\TestUpgradeService::class))->newInstanceWithoutConstructor();
    $prop = new \ReflectionProperty(\App\Services\UpgradeService::class, 'filesystem');
    $prop->setAccessible(true);
    $prop->setValue($svc, new \League\Flysystem\Filesystem());
    return $svc;
};

echo "== 场景 A：升级成功 ==\n";
$resetBase();
$svc = $newService();
// 用反射设置构造器本应设置的 $version
$vp = new \ReflectionProperty(\App\Services\UpgradeService::class, 'version');
$vp->setAccessible(true);
$vp->setValue($svc, 'V 2.1');

$result = $svc->upgrade();
ok($result === true, 'upgrade() 返回 true');
ok(file_get_contents(base_path('app/Old.php')) === '<?php // new version of Old', 'app/Old.php 已被覆盖为新内容');
ok(file_get_contents(base_path('app/New.php')) === '<?php // brand new file', 'app/New.php 新增成功');
ok(($GLOBALS['__config_db']['app_version'] ?? '') === 'V 2.2.0', '版本号更新为 V 2.2.0', $GLOBALS['__config_db']['app_version'] ?? '');
ok(in_array('migrate', \Illuminate\Support\Facades\Artisan::$calls), '执行了数据库迁移');
ok(in_array('optimize:clear', \Illuminate\Support\Facades\Artisan::$calls) && in_array('package:discover', \Illuminate\Support\Facades\Artisan::$calls), '执行了缓存清理');
ok(! file_exists(base_path('upgrading.lock')), '锁文件已释放');
ok(! is_dir(base_path('storage/temp')), '临时目录已清理');
$lastProgress = end($GLOBALS['__progress']);
ok($lastProgress[0] === 'success' && str_contains($lastProgress[1], '升级成功'), '进度状态为 success', json_encode($lastProgress));

echo "== 场景 B：迁移失败回滚 ==\n";
$resetBase();
\Illuminate\Support\Facades\Artisan::$failOn = 'migrate';
$svc2 = $newService();
$vp->setValue($svc2, 'V 2.1');

$result2 = $svc2->upgrade();
ok($result2 === false, 'upgrade() 返回 false');
ok(file_get_contents(base_path('app/Old.php')) === '<?php // OLD content', 'app/Old.php 回滚为旧内容');
ok(! file_exists(base_path('app/New.php')), '新增的 app/New.php 已删除');
ok(($GLOBALS['__config_db']['app_version'] ?? '') === 'V 2.1', '版本号保留旧值 V 2.1', $GLOBALS['__config_db']['app_version'] ?? '');
ok(! file_exists(base_path('upgrading.lock')), '失败后锁文件已释放');
$lastProgress2 = end($GLOBALS['__progress']);
ok($lastProgress2[0] === 'fail' && str_contains($lastProgress2[1], '模拟迁移失败'), '进度状态为 fail', json_encode($lastProgress2));

echo "== 场景 C：升级中重复进入被锁拒绝 ==\n";
$resetBase();
file_put_contents(base_path('upgrading.lock'), '');
$svc3 = $newService();
$vp->setValue($svc3, 'V 2.1');
ok($svc3->upgrade() === false, '存在锁时直接返回 false');

echo "== 场景 D：checkUpdate 数据映射（getVersions） ==\n";
$resetBase();
$svc4 = $newService();
$vp->setValue($svc4, 'V 2.1');
$v = $svc4->check();
ok($v === true, 'check() 检测到更新');
$mapped = $svc4->getVersions()->first();
ok(is_array($mapped) && $mapped['name'] === '2.2.0', 'UI name 字段为 2.2.0');
ok(isset($mapped['size'], $mapped['pushed_at'], $mapped['changelog'], $mapped['icon']), 'UI 所需字段齐全', json_encode($mapped));
ok(str_contains($mapped['changelog'], '<h1>'), 'notes 渲染为 Markdown HTML');

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
}
