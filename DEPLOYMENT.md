# PHP后端部署指南

## 📋 系统要求

### 服务器环境
- **PHP**: 7.4+ (推荐 8.0+)
- **Web服务器**: Apache 2.4+ 或 Nginx 1.18+
- **扩展要求**:
  - `curl` - 用于HTTP请求
  - `json` - JSON处理
  - `mbstring` - 多字节字符串处理

### 检查PHP环境
```bash
# 检查PHP版本
php -v

# 检查必需扩展
php -m | grep -E "(curl|json|mbstring)"

# 检查cURL支持
php -r "echo extension_loaded('curl') ? 'cURL已安装' : 'cURL未安装';"
```

## 🚀 部署步骤

### 1. 上传文件
将项目文件上传到Web服务器：
```
your-domain.com/
├── index.html          # 前端页面
├── script.js           # 前端脚本
├── favicon.ico         # 网站图标
├── demo.png           # 演示图片
└── backend/           # 后端API
    ├── api/
    │   ├── check-service.php
    │   ├── check-all.php
    │   └── config.php
    ├── includes/
    │   ├── cors.php
    │   └── functions.php
    ├── cache/         # 自动创建
    ├── logs/          # 自动创建
    └── .htaccess
```

### 2. 设置目录权限
```bash
# 设置基本权限
chmod 755 backend/
chmod 755 backend/api/
chmod 755 backend/includes/

# 设置缓存和日志目录权限
chmod 777 backend/cache/
chmod 777 backend/logs/

# 或者更安全的方式（推荐）
chown -R www-data:www-data backend/cache/
chown -R www-data:www-data backend/logs/
chmod 755 backend/cache/
chmod 755 backend/logs/
```

### 3. Apache配置

#### 启用必需模块
```bash
# 启用重写模块
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod expires
sudo a2enmod deflate

# 重启Apache
sudo systemctl restart apache2
```

#### 虚拟主机配置
```apache
<VirtualHost *:80>
    ServerName docker-monitor.your-domain.com
    DocumentRoot /var/www/docker-monitor
    
    <Directory /var/www/docker-monitor>
        AllowOverride All
        Require all granted
        
        # 启用.htaccess
        Options -Indexes +FollowSymLinks
        
        # 安全设置
        <FilesMatch "\.(htaccess|htpasswd|ini|log|sh|sql)$">
            Require all denied
        </FilesMatch>
    </Directory>
    
    # 日志配置
    ErrorLog ${APACHE_LOG_DIR}/docker-monitor_error.log
    CustomLog ${APACHE_LOG_DIR}/docker-monitor_access.log combined
</VirtualHost>
```

### 4. Nginx配置

```nginx
server {
    listen 80;
    server_name docker-monitor.your-domain.com;
    root /var/www/docker-monitor;
    index index.html;
    
    # 前端文件
    location / {
        try_files $uri $uri/ =404;
    }
    
    # PHP API处理
    location ~ ^/backend/api/.*\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # 禁止访问敏感目录
    location ~ ^/backend/(includes|cache|logs)/ {
        deny all;
        return 404;
    }
    
    # 禁止访问敏感文件
    location ~ \.(htaccess|htpasswd|ini|log|sh|sql|conf)$ {
        deny all;
        return 404;
    }
    
    # 启用gzip
    gzip on;
    gzip_types application/json text/css application/javascript;
    
    # 缓存设置
    location ~* \.(css|js|png|jpg|jpeg|gif|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

## 🔧 配置说明

### 1. 修改API配置
编辑 `backend/api/config.php`：

```php
// 修改允许的域名
$allowed_origins = [
    'https://your-domain.com',
    'https://www.your-domain.com'
];

// 调整缓存时间（秒）
define('CACHE_DURATION', 300); // 5分钟

// 调整超时时间
define('DEFAULT_TIMEOUT', 10); // 10秒
```

### 2. 修改前端API路径
如果后端部署在不同路径，修改 `script.js`：

```javascript
// 修改API基础路径
const API_BASE_URL = './backend/api'; // 相对路径
// 或
const API_BASE_URL = 'https://api.your-domain.com'; // 绝对路径
```

### 3. 安全配置

#### 启用HTTPS（推荐）
```bash
# 使用Let's Encrypt
sudo certbot --apache -d docker-monitor.your-domain.com
```

#### 防火墙设置
```bash
# 只开放必要端口
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## 🧪 测试部署

### 1. 测试API端点
```bash
# 测试单个服务检测
curl -X POST https://your-domain.com/backend/api/check-service.php \
  -H "Content-Type: application/json" \
  -d '{"url":"https://docker.mirrors.ustc.edu.cn"}'

# 测试批量检测
curl https://your-domain.com/backend/api/check-all.php
```

### 2. 检查日志
```bash
# 查看API日志
tail -f backend/logs/monitor.log

# 查看Apache错误日志
tail -f /var/log/apache2/docker-monitor_error.log
```

### 3. 性能测试
```bash
# 使用ab进行压力测试
ab -n 100 -c 10 https://your-domain.com/backend/api/check-all.php
```

## 🔍 故障排除

### 常见问题

#### 1. CORS错误
**症状**: 浏览器控制台显示跨域错误
**解决**: 检查 `backend/includes/cors.php` 中的域名配置

#### 2. 权限错误
**症状**: 500错误，无法写入缓存或日志
**解决**: 
```bash
sudo chown -R www-data:www-data backend/cache/ backend/logs/
sudo chmod 755 backend/cache/ backend/logs/
```

#### 3. cURL错误
**症状**: API返回cURL相关错误
**解决**: 检查PHP cURL扩展和网络连接

#### 4. 超时问题
**症状**: 检测时间过长或超时
**解决**: 调整 `config.php` 中的超时设置

### 调试模式
在 `config.php` 中启用调试：
```php
// 开发环境启用错误显示
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 启用详细日志
$logConfig['level'] = 'DEBUG';
```

## 📊 监控和维护

### 1. 日志轮转
```bash
# 创建logrotate配置
sudo nano /etc/logrotate.d/docker-monitor

# 内容：
/var/www/docker-monitor/backend/logs/*.log {
    daily
    missingok
    rotate 7
    compress
    notifempty
    create 644 www-data www-data
}
```

### 2. 定期清理缓存
```bash
# 创建清理脚本
#!/bin/bash
find /var/www/docker-monitor/backend/cache/ -name "*.json" -mtime +1 -delete

# 添加到crontab
0 2 * * * /path/to/cleanup-cache.sh
```

### 3. 性能监控
- 监控API响应时间
- 检查服务器资源使用
- 定期检查日志文件大小

## 🚀 生产环境优化

### 1. 启用OPcache
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=4000
```

### 2. 数据库集成（可选）
如需持久化存储历史数据，可集成MySQL：
```sql
CREATE TABLE service_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_url VARCHAR(255),
    status ENUM('healthy','slow','error','timeout'),
    response_time INT,
    http_code INT,
    check_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3. 负载均衡
对于高并发场景，可部署多个后端实例。

---

**部署完成后，访问您的域名即可看到实时的Docker镜像监控服务！**
