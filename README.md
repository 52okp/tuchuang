<h1 align="left">蓝空图床（tuchuang）</h1>

☁ 基于 [Lsky Pro](https://github.com/lsky-org/lsky-pro) 二次开发的图床程序，接入统一更新服务。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-orange.svg)](http://php.net)
[![Release](https://img.shields.io/github/v/release/52okp/tuchuang)](https://github.com/52okp/tuchuang/releases)
[![License](https://img.shields.io/badge/license-GPL_V3.0-yellowgreen.svg)](https://github.com/52okp/tuchuang/blob/master/LICENSE)

[安装指南（宝塔面板）](INSTALL-BT.md)

> 本项目在 Lsky Pro 开源版基础上维护，主要改动：
> - 自动更新迁移到统一更新服务（令牌认证、整包 SHA-256 校验、安全解压、失败回滚）
> - 接口文档页 `/api` 开放游客访问
> - 附带宝塔面板一键部署指南与独立回归测试

### 📌 功能特性

* [x] 支持本地等多种第三方云储存：`AWS S3`、`阿里云 OSS`、`腾讯云 COS`、`七牛云`、`又拍云`、`SFTP`、`FTP`、`WebDav`、`Minio`
* [x] 多种数据库驱动支持：`MySQL 5.7+`、`PostgreSQL 9.6+`、`SQLite 3.8.8+`、`SQL Server 2017+`
* [x] 多图上传、拖拽上传、粘贴上传、动态设置策略上传、一键复制链接
* [x] 瀑布流图片管理，支持右键菜单、多选、重命名等操作
* [x] 角色组配置：每组可绑定多个储存策略，可设置路径命名规则、上传频率限制、图片审核
* [x] 图片水印（文字/图片/平铺/位置/偏移/旋转）
* [x] Token 化 API（上传、图片管理、相册管理），接口文档游客可查看
* [x] 在线一键升级（统一更新服务，含完整性校验与失败回滚）
* [x] 图片广场

### 🛠 环境要求

- PHP >= 8.0.2（推荐 8.2）
- PHP 扩展：`BCMath`、`Ctype`、`DOM`、`Fileinfo`、`JSON`、`Mbstring`、`OpenSSL`、`PDO`、`Tokenizer`、`XML`、`Imagick`、`Zip`
- `readlink`、`symlink`、`putenv`、`getenv`、`chmod`、`chown`、`fileperms` 函数可用
- MySQL >= 5.7（或其他受支持的数据库）

详细的宝塔面板部署步骤与常见坑见 **[INSTALL-BT.md](INSTALL-BT.md)**。

### 🔄 升级

程序内置在线升级能力（Bearer 令牌认证、清单与整包双重 SHA-256 校验、防路径穿越/符号链接的安全解压、自动备份、失败自动回滚）。更新源通过 `.env` 的 `UPDATES_BASE_URL` / `UPDATES_PROJECT` / `UPDATES_TOKEN` 配置，由部署者自备更新服务与令牌。

未配置更新源的部署不影响正常使用，可从 [Releases](https://github.com/52okp/tuchuang/releases) 下载新版整包覆盖站点目录后执行 `php artisan migrate --force` 完成升级。

### 🧪 测试

升级服务含独立回归测试（不依赖完整 Laravel 环境）：

```bash
php tests/standalone/UpgradeServiceSecurityTest.php   # 恶意 ZIP 拒绝等安全用例
php tests/standalone/UpgradeServiceFlowTest.php       # 升级成功/失败回滚全流程
```

### 😋 鸣谢

- [Lsky Pro](https://github.com/lsky-org/lsky-pro) 及其作者 Wisp X —— 本项目的前身
- [Laravel](https://laravel.com) / [Tailwindcss](https://tailwindcss.com) / [Intervention/image](https://github.com/Intervention/image) / [league/flysystem](https://flysystem.thephpleague.com)

### 📃 开源许可

[GPL 3.0](https://opensource.org/licenses/GPL-3.0)

Copyright (c) 2018-present Lsky Pro, 2026-present 蓝空图床 contributors.
