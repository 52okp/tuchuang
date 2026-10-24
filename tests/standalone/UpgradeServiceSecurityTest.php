<?php
/**
 * UpgradeService 独立验证脚本（不依赖 Laravel）
 * 用桩函数 + 反射直接测试真实类的方法。运行：php tests/standalone/UpgradeServiceSecurityTest.php
 */

namespace App { class Utils { public static function e($e, $m = '') {} } }
namespace App\Models { class Config { public static function query() { throw new \Exception('not needed'); } } }
namespace App\Enums { class ConfigKey { const AppVersion = 'app_version'; } }
namespace Illuminate\Http\Client { class PendingRequest { public function __call($m, $a) { return $this; } } }
namespace Illuminate\Support { class Collection {} }
namespace Illuminate\Support\Facades {
    class Artisan { public static function call(...$a) { return 0; } }
    class Cache { public static function put(...$a) {} public static function get(...$a) {} }
    class Http { public static function __callStatic($m, $a) { return new \Illuminate\Http\Client\PendingRequest(); } }
}
namespace League\Flysystem {
    class FilesystemException extends \RuntimeException {}
    class Filesystem { public function __construct($a = null) {} }
}
namespace League\Flysystem\Local { class LocalFilesystemAdapter { public function __construct($a = null) {} } }

namespace {

error_reporting(E_ALL);

$GLOBALS['__test_base'] = sys_get_temp_dir().'/tuchuang-upgrade-test';
$GLOBALS['__test_config'] = [
    'upgrades.base_url' => 'https://update.example.com',
    'upgrades.project' => 'demoapp',
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

@mkdir($GLOBALS['__test_base'], 0777, true);

require __DIR__.'/../../app/Services/UpgradeService.php';

// 覆盖 setProgress/gc 的测试子类（跳过 Cache facade 依赖）
eval(<<<'PHP'
namespace App\Services;
class TestUpgradeService extends UpgradeService {
    protected function setProgress(string $message, string $status = 'installing', int $ttl = 1800) {}
    protected function gc() {}
}
PHP);

$service = (new \ReflectionClass(\App\Services\TestUpgradeService::class))->newInstanceWithoutConstructor();

$call = function (string $method, ...$args) use ($service) {
    $m = new \ReflectionMethod(\App\Services\TestUpgradeService::class, $method);
    $m->setAccessible(true);
    return $m->invoke($service, ...$args);
};

$pass = 0; $fail = 0;
function ok(bool $cond, string $name, string $detail = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}  {$detail}\n"; }
}
function expectException(callable $fn, string $needle, string $name)
{
    try {
        $fn();
        ok(false, $name, '（未抛出异常）');
    } catch (\Throwable $e) {
        ok(str_contains($e->getMessage(), $needle), $name, '（消息: '.$e->getMessage().'）');
    }
}

echo "== normalizeVersion ==\n";
ok($call('normalizeVersion', 'V 2.1') === '2.1.0', 'V 2.1 -> 2.1.0');
ok($call('normalizeVersion', 'v1.2.3') === '1.2.3', 'v1.2.3 -> 1.2.3');
ok($call('normalizeVersion', ' 2.1 ') === '2.1.0', '" 2.1 " -> 2.1.0');
ok($call('normalizeVersion', '2.01') === '2.1.0', '2.01 去前导零 -> 2.1.0');
ok($call('normalizeVersion', '2.1.0') === '2.1.0', '2.1.0 原样通过');
expectException(fn () => $call('normalizeVersion', 'abc'), '格式不正确', 'abc 拒绝');
expectException(fn () => $call('normalizeVersion', '1.2.x'), '格式不正确', '1.2.x 拒绝');

echo "== project() ==\n";
ok($call('project') === 'demoapp', '默认项目名 demoapp');
$GLOBALS['__test_config']['upgrades.project'] = 'Bad_Name';
expectException(fn () => $call('project'), '格式不正确', '非法项目名拒绝');
$GLOBALS['__test_config']['upgrades.project'] = 'tuchuang';

echo "== token 校验（http()）==\n";
$GLOBALS['__test_config']['upgrades.token'] = '';
expectException(fn () => $call('http'), 'UPDATES_TOKEN', '缺令牌给出明确提示');
$GLOBALS['__test_config']['upgrades.token'] = 'short';
expectException(fn () => $call('http'), '64 位小写十六进制', '令牌格式错误拒绝');
$GLOBALS['__test_config']['upgrades.token'] = str_repeat('a', 64);
ok($call('http') instanceof \Illuminate\Http\Client\PendingRequest, '合法令牌返回客户端');

echo "== resolveUrl ==\n";
$releaseId = str_repeat('b', 64);
$release = [
    'id' => $releaseId, 'version' => '2.2.0', 'from' => '2.1.0',
    'sha256' => str_repeat('c', 64), 'size' => 100,
    'download_url' => 'https://update.example.com/api/v1/projects/demoapp/releases/'.$releaseId.'/package',
    'manifest_url' => '/api/v1/projects/demoapp/releases/'.$releaseId.'/manifest',
];
ok($call('resolveUrl', '', $release, 'zip') === $release['download_url'], 'download_url 绝对地址通过');
ok($call('resolveUrl', '/api/x', $release, 'zip') === 'https://update.example.com/api/x', '相对路径拼接 base_url');
expectException(fn () => $call('resolveUrl', 'http://update.example.com/x', $release, 'zip'), 'https', 'http 地址拒绝');
expectException(fn () => $call('resolveUrl', 'https://evil.com/x', $release, 'zip'), '自身', '外域地址拒绝');
expectException(fn () => $call('resolveUrl', '', [], 'zip'), '下载地址', '无地址时报错');

echo "== findAsset ==\n";
$releaseWithAssets = array_merge($release, ['assets' => [
    ['type' => 'zip', 'size' => 100, 'sha256' => 'aa', 'url' => 'https://update.example.com/z'],
    ['type' => 'manifest', 'size' => 10, 'sha256' => 'bb', 'url' => 'https://update.example.com/m'],
]]);
ok(($call('findAsset', $releaseWithAssets, 'manifest'))['sha256'] === 'bb', '按 type 定位 manifest 资产');
ok(is_null($call('findAsset', $release, 'manifest')), '无 assets 返回 null');

echo "== extractPackage ==\n";
$tempDir = sys_get_temp_dir().'/tuchuang-zips';
@mkdir($tempDir, 0777, true);

$mkZip = function (string $name, callable $builder) use ($tempDir): string {
    $path = $tempDir.'/'.$name.'.zip';
    @unlink($path);
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $builder($zip);
    $zip->close();
    return $path;
};
$manifestOk = ['product' => 'demoapp', 'version' => '2.2.0', 'from' => '2.1.0', 'sha256' => str_repeat('c', 64), 'size' => 1];
$releaseOk = array_merge($release, ['sha256' => str_repeat('c', 64), 'size' => 1]);

$extractBase = base_path('storage/temp/extract');
if (is_dir($extractBase)) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractBase, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($extractBase);
}

// 1. 正常包
$zip1 = $mkZip('normal', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('app/Test.php', '<?php // new');
    $z->addFromString('public/nested/dir/a.css', 'body{}');
    $z->addEmptyDir('storage');
});
$files = $call('extractPackage', $zip1, $releaseOk, $manifestOk);
sort($files);
ok($files === ['app/Test.php', 'public/nested/dir/a.css'], '正常包解压且排除 update-version.json', json_encode($files));
ok(is_file($extractBase.'/app/Test.php'), '文件已解压到隔离目录');

// 2. 路径穿越
$zip2 = $mkZip('traversal', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('../evil.txt', 'x');
});
expectException(fn () => $call('extractPackage', $zip2, $releaseOk, $manifestOk), '路径穿越', '穿越条目拒绝');
ok(! file_exists(dirname($GLOBALS['__test_base']).'/evil.txt'), '穿越文件未写到上级目录');

// 3. 绝对路径
$zip3 = $mkZip('absolute', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('/abs/evil.txt', 'x');
});
expectException(fn () => $call('extractPackage', $zip3, $releaseOk, $manifestOk), '绝对路径', '绝对路径条目拒绝');

// 4. 反斜杠
$zip4 = $mkZip('backslash', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('dir\\evil.txt', 'x');
});
expectException(fn () => $call('extractPackage', $zip4, $releaseOk, $manifestOk), '反斜杠', '反斜杠条目拒绝');

// 5. 单条目体积超限
$GLOBALS['__test_config']['upgrades.max_entry_size'] = 4;
$zip5 = $mkZip('toolarge', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('big.bin', str_repeat('A', 32));
});
expectException(fn () => $call('extractPackage', $zip5, $releaseOk, $manifestOk), '体积超出限制', '单条目体积超限拒绝');
$GLOBALS['__test_config']['upgrades.max_entry_size'] = 50 * 1024 * 1024;

// 6. 总体积超限
$GLOBALS['__test_config']['upgrades.max_extract_size'] = 16;
$zip6 = $mkZip('totaltoolarge', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('a.bin', str_repeat('A', 8));
    $z->addFromString('b.bin', str_repeat('A', 8));
    $z->addFromString('c.bin', str_repeat('A', 8));
});
expectException(fn () => $call('extractPackage', $zip6, $releaseOk, $manifestOk), '总体积超出限制', '解压总体积超限拒绝');
$GLOBALS['__test_config']['upgrades.max_extract_size'] = 1024 * 1024 * 1024;

// 7. 条目数超限
$GLOBALS['__test_config']['upgrades.max_zip_entries'] = 2;
$zip7 = $mkZip('toomany', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('a.php', 'x');
    $z->addFromString('b.php', 'x');
});
expectException(fn () => $call('extractPackage', $zip7, $releaseOk, $manifestOk), '条目数量超出限制', '条目数超限拒绝');
$GLOBALS['__test_config']['upgrades.max_zip_entries'] = 10000;

// 8. update-version.json 产品不符
$zip8 = $mkZip('wrongproduct', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'other', 'version' => '2.2.0']));
    $z->addFromString('a.php', 'x');
});
expectException(fn () => $call('extractPackage', $zip8, $releaseOk, $manifestOk), '不一致', '产品名不符拒绝');

// 9. 缺少 update-version.json
$zip9 = $mkZip('noversionfile', function ($z) {
    $z->addFromString('a.php', 'x');
});
expectException(fn () => $call('extractPackage', $zip9, $releaseOk, $manifestOk), '缺少 update-version.json', '缺少 update-version.json 拒绝');

// 10. 损坏的 zip
$badPath = $tempDir.'/corrupt.zip';
file_put_contents($badPath, 'not a zip');
expectException(fn () => $call('extractPackage', $badPath, $releaseOk, $manifestOk), '无法打开', '损坏 zip 拒绝');

// 11. .env / installed.lock 被排除
$zip11 = $mkZip('withenv', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '2.2.0']));
    $z->addFromString('.env', 'APP_KEY=xxx');
    $z->addFromString('installed.lock', '');
    $z->addFromString('app/Real.php', '<?php');
});
$files11 = $call('extractPackage', $zip11, $releaseOk, $manifestOk);
ok($files11 === ['app/Real.php'], '.env/installed.lock 被排除不覆盖', json_encode($files11));

// 12. update-version.json 版本不符
$zip12 = $mkZip('wrongversion', function ($z) {
    $z->addFromString('update-version.json', json_encode(['product' => 'demoapp', 'version' => '9.9.9']));
    $z->addFromString('a.php', 'x');
});
expectException(fn () => $call('extractPackage', $zip12, $releaseOk, $manifestOk), '不一致', 'update-version.json 版本不符拒绝');

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
}
