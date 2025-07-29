# 代码仓库上传指南

本指南将帮助您将 Docker 镜像加速服务监控项目上传到 GitHub、Gitee 和 cnbcool 三个平台。

## 📋 准备工作

项目已经初始化为 Git 仓库，并完成了初始提交。当前状态：
- ✅ Git 仓库已初始化
- ✅ 文件已添加并提交
- ✅ 用户信息已配置 (mcwl <mcwlgzs@qq.com>)

## 🚀 上传步骤

### 1. 上传到 GitHub

#### 步骤 1: 在 GitHub 创建仓库
1. 访问 [GitHub](https://github.com)
2. 点击右上角的 "+" 号，选择 "New repository"
3. 填写仓库信息：
   - Repository name: `docker-mirror-monitor`
   - Description: `Docker镜像加速服务监控平台 - 实时监控国内33+个Docker Hub镜像加速服务状态`
   - 选择 Public（公开）
   - **不要**勾选 "Initialize this repository with a README"
4. 点击 "Create repository"

#### 步骤 2: 添加 GitHub 远程仓库并推送
```bash
# 添加 GitHub 远程仓库（替换 YOUR_USERNAME 为您的 GitHub 用户名）
git remote add github https://github.com/YOUR_USERNAME/docker-mirror-monitor.git

# 推送到 GitHub
git branch -M main
git push -u github main
```

### 2. 上传到 Gitee

#### 步骤 1: 在 Gitee 创建仓库
1. 访问 [Gitee](https://gitee.com)
2. 点击右上角的 "+" 号，选择 "新建仓库"
3. 填写仓库信息：
   - 仓库名称: `docker-mirror-monitor`
   - 仓库介绍: `Docker镜像加速服务监控平台 - 实时监控国内33+个Docker Hub镜像加速服务状态`
   - 选择 "公开"
   - **不要**勾选 "使用 Readme 文件初始化这个仓库"
4. 点击 "创建"

#### 步骤 2: 添加 Gitee 远程仓库并推送
```bash
# 添加 Gitee 远程仓库（替换 YOUR_USERNAME 为您的 Gitee 用户名）
git remote add gitee https://gitee.com/YOUR_USERNAME/docker-mirror-monitor.git

# 推送到 Gitee
git push -u gitee main
```

### 3. 上传到 cnbcool

#### 步骤 1: 在 cnbcool 创建仓库
1. 访问 [cnbcool](https://cnbcool.com) 或相关代码托管平台
2. 根据平台界面创建新仓库
3. 填写仓库信息：
   - 仓库名称: `docker-mirror-monitor`
   - 描述: `Docker镜像加速服务监控平台`

#### 步骤 2: 添加 cnbcool 远程仓库并推送
```bash
# 添加 cnbcool 远程仓库（替换为实际的仓库地址）
git remote add cnbcool https://cnbcool.com/YOUR_USERNAME/docker-mirror-monitor.git

# 推送到 cnbcool
git push -u cnbcool main
```

## 🔧 完整命令序列

在项目目录下依次执行以下命令（请先在各平台创建仓库）：

```bash
# 查看当前状态
git status

# 添加所有远程仓库
git remote add github https://github.com/YOUR_USERNAME/docker-mirror-monitor.git
git remote add gitee https://gitee.com/YOUR_USERNAME/docker-mirror-monitor.git
git remote add cnbcool https://cnbcool.com/YOUR_USERNAME/docker-mirror-monitor.git

# 查看远程仓库
git remote -v

# 推送到所有平台
git push -u github main
git push -u gitee main
git push -u cnbcool main
```

## 📝 后续更新

当您需要更新代码时，可以使用以下命令同时推送到所有平台：

```bash
# 添加修改的文件
git add .

# 提交更改
git commit -m "更新描述"

# 推送到所有平台
git push github main
git push gitee main
git push cnbcool main
```

## 🔍 验证上传

上传完成后，您可以访问以下地址验证：

- GitHub: `https://github.com/YOUR_USERNAME/docker-mirror-monitor`
- Gitee: `https://gitee.com/YOUR_USERNAME/docker-mirror-monitor`
- cnbcool: `https://cnbcool.com/YOUR_USERNAME/docker-mirror-monitor`

## 🎯 项目亮点

在仓库描述中可以突出以下特点：
- 🌟 实时监控 33+ 个国内 Docker 镜像加速服务
- 📱 响应式设计，支持移动端和桌面端
- 🌙 深色模式支持
- ⚡ 纯前端实现，无需后端服务
- 🎨 Apple 风格设计，简洁优雅
- 📊 实时状态统计和响应时间监控
- 🔧 详细的配置指南

## 🏷️ 建议的标签 (Tags)

为仓库添加以下标签以提高可发现性：
- `docker`
- `mirror`
- `monitor`
- `china`
- `registry`
- `javascript`
- `html5`
- `css3`
- `responsive`
- `dark-mode`

## 📄 许可证

建议为项目添加 MIT 许可证，可以在各平台创建仓库时选择，或者手动添加 LICENSE 文件。

## 🔗 相关链接

- [Git 官方文档](https://git-scm.com/doc)
- [GitHub 帮助文档](https://docs.github.com/)
- [Gitee 帮助文档](https://gitee.com/help)

---

**注意**: 请将上述命令中的 `YOUR_USERNAME` 替换为您在各平台的实际用户名。
