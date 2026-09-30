/**
 * Tailwind Play CDN 主题配置。
 *
 * 必须在 <script src="https://cdn.tailwindcss.com"></script> 之后加载：
 * Play CDN 需要先定义 window.tailwind 才会读取该配置。
 */
tailwind.config = {
    // 深色模式由 <html class="dark"> 控制，与 script.js 的 initThemeToggle() 对应。
    // v1.x 遗漏了此项，导致 .dark 类始终无法让 dark: 变体生效。
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                'docker-blue': '#007AFF',
                'apple-blue': '#007AFF',
                'apple-green': '#34C759',
                'apple-orange': '#FF9500',
                'apple-red': '#FF3B30',
                'apple-gray': '#8E8E93',
                'apple-bg': '#F2F2F7',
                'apple-card': '#FFFFFF',
                'apple-border': '#E5E5EA'
            },
            fontFamily: {
                'sf': ['-apple-system', 'BlinkMacSystemFont', 'SF Pro Display', 'Segoe UI', 'Roboto', 'sans-serif']
            },
            boxShadow: {
                'apple': '0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06)',
                'apple-lg': '0 10px 25px rgba(0, 0, 0, 0.1), 0 4px 6px rgba(0, 0, 0, 0.05)',
                'apple-xl': '0 20px 40px rgba(0, 0, 0, 0.1), 0 8px 16px rgba(0, 0, 0, 0.06)'
            },
            borderRadius: {
                'apple': '12px',
                'apple-lg': '16px',
                'apple-xl': '20px'
            }
        }
    }
};
