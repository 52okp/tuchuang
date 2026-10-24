# 蓝空图床 · 宝塔面板安装指南

本指南基于一次真实的生产环境部署整理，涵盖全部已知的坑，照做即可一次装好。

## 一、环境要求

| 组件 | 要求 | 说明 |
|---|---|---|
| 宝塔面板 | 7.x / 9.x | |
| Nginx | 任意近期版本 | |
| MySQL | ≥ 5.7 | 字符集 `utf8mb4` |
| PHP | **8.2（推荐）**，≥ 8.0 | 不要选 8.4/8.5，老依赖兼容性风险 |
| PHP 扩展 | `fileinfo`、`imagick`、`zip`、`bcmath`、`gd`、`mbstring`、`pdo_mysql` | 前三个宝塔默认不带，需手动装 |
| Composer | 任意 2.x | |

> `imagick` 是硬依赖（图片处理驱动），缺它上传必报错。宝塔软件商店 → PHP 8.2 → 安装扩展里逐个安装，imagemagick 编译需要几分钟。

## 二、安装步骤

### 1. 装软件

宝塔软件商店安装：Nginx、MySQL、PHP 8.2，然后给 PHP 8.2 装上 `fileinfo`、`imagick`、`zip` 扩展。

### 2. 建数据库

宝塔 → 数据库 → 添加：

- 字符集选 `utf8mb4`
- 记下库名、用户名、密码

### 3. 建站点 + 放代码

宝塔 → 网站 → 添加站点（域名或 IP 都行，PHP 版本随便选后面会改）。

进入站点目录（如 `/www/wwwroot/你的站点`），**清空默认文件**后上传本项目代码（Release 的 ZIP 或 git clone），或者本地上传后解压。

### 4. 安装 Composer 依赖

```bash
cd /www/wwwroot/你的站点
composer config repos.packagist composer https://mirrors.aliyun.com/composer/
composer install --no-dev --optimize-autoloader --prefer-dist
```

### 5. 配置 .env

```bash
cp .env.example .env
```

编辑 `.env`，重点改这些：

```ini
APP_NAME=蓝空图床
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的域名          # 决定图片直链的域名，务必填对

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=库名
DB_USERNAME=数据库用户
DB_PASSWORD=数据库密码

# 在线升级（可选）：更新源自备，不填不影响正常使用
UPDATES_BASE_URL=
UPDATES_PROJECT=
UPDATES_CHANNEL=stable
UPDATES_TOKEN=
```

### 6. 初始化

```bash
/www/server/php/82/bin/php artisan key:generate
/www/server/php/82/bin/php artisan storage:link

# 权限
chown -R www:www /www/wwwroot/你的站点
chmod -R 775 storage bootstrap/cache
```

### 7. 走网页安装向导

浏览器访问 `https://你的域名/install`，填数据库和管理员账号即可。

命令行党也可以（**注意：安装前确保没有 config 缓存**，详见下方坑 2）：

```bash
php artisan config:clear
php artisan lsky:install --connection=mysql --host=127.0.0.1 --port=3306 \
  --database=库名 --username=用户 --password=密码
# 然后自行创建管理员账号（参考 web 安装器的逻辑），网页向导更省事
```

安装成功后根目录会生成 `installed.lock`，`/install` 自动变 404。

### 8. 站点设置（宝塔面板里操作）

1. **网站设置 → 网站目录**：运行目录改为 `/public`
2. **伪静态**：选 Laravel 规则，或手动填：
   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```
3. **PHP 版本**：确认是 8.2
4. **上传体积**：软件商店 → PHP 8.2 → 配置文件，把 `upload_max_filesize` 和 `post_max_size` 调到需要的值（如 `100m`）；Nginx 配置里加 `client_max_body_size 100m;`

## 三、常见坑（全部实战踩过）

### 坑 1：不要用宝塔模板自带的"静态图片缓存"规则

宝塔默认站点模板里有这样一段：

```nginx
location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$ { expires 30d; ... }
```

它会把所有图片后缀的请求都按静态文件处理，**拦截掉本程序的动态图片路由**（原图保护功能），导致那些图片 404。本程序自带静态直链目录（`public/i`），不需要这段缓存规则，删掉即可。

### 坑 2：`lsky:install` 在 config 缓存存在时会写坏 .env

如果之前执行过 `php artisan config:cache`，命令行安装会把 `.env` 里的数据库配置写成 `DB_HOST=127.0.0.1127.0.0.1` 这样的双倍拼接值。**安装前先 `php artisan config:clear`**。（上游 bug，待修）

### 坑 3：Debian 系统 ImageMagick 安全策略禁掉图片格式

部分做过安全加固的 Debian，`/etc/ImageMagick-6/policy.xml` 里会有 `<policy domain="coder" rights="none" pattern="*"/>`（全禁）。表现为：`identify` 正常但上传/处理报错。在 `</policymap>` 前加一行放行常用图片格式：

```xml
<policy domain="coder" rights="read|write" pattern="{GIF,JPEG,JPG,PNG,WEBP,ICO,BMP,TIF,TIFF}" />
```

### 坑 4：网页端一键升级被 php-fpm 掐断

宝塔 PHP 池默认 `request_terminate_timeout = 100`（秒），大版本更新包下载安装超过 100 秒会被强杀。需要网页端在线升级的，改 `/www/server/php/82/etc/php-fpm.conf`：

```ini
request_terminate_timeout = 0
```

改完 `/etc/init.d/php-fpm-82 restart`。（V2.1.2 起迁移命令已带 `--force`，生产环境不会再触发交互确认）

### 坑 5：安全基线

- MySQL 3306 端口**不要**对公网开放（宝塔防火墙/云安全组关掉）
- 后台建议关闭游客上传或从允许后缀里去掉 `svg`（同源内联 XSS 风险，见安全审计报告）
- `UPDATES_TOKEN` 只放 `.env`，不要进代码仓库或前端

## 四、升级

两种方式任选：

**在线升级（可选）**：`.env` 里配置自备更新服务的 `UPDATES_BASE_URL` / `UPDATES_PROJECT` / `UPDATES_TOKEN` 后，后台 → 系统设置 → 系统升级 检查更新并一键安装。升级流程内置 SHA-256 完整性校验、安全解压校验、自动备份与失败回滚。未配置或无令牌时此功能不可用，不影响其他功能。

**手动升级**：从 GitHub [Releases](https://github.com/52okp/tuchuang/releases) 下载新版代码 → 覆盖站点目录（**保留** `.env`、`installed.lock`、`storage/app/uploads`）→ 执行：

```bash
php artisan migrate --force
php artisan optimize:clear
```
