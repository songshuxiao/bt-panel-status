<?php
/**
 * 宝塔服务器实时监控（每2秒刷新）
 */

// ================= 配置区域 =================
$servers = [
    [
        'name'  => '服务器 A',
        'panel' => 'https://192.168.1.245:8888',
        'key'   => 'your_second_api_key_here'
    ],
    [
        'name'  => '服务器 B',
        'panel' => 'https://192.168.1.246:8888',
        'key'   => 'your_second_api_key_here'
    ]
];
// =============================================

/**
 * 宝塔 API 封装类（精简版）
 */
class BtApi {
    private $bt_key, $bt_panel, $cookie_file;

    public function __construct($panel, $key) {
        $this->bt_panel = rtrim($panel, '/');
        $this->bt_key   = $key;
        $this->cookie_file = dirname(__FILE__) . '/' . md5($this->bt_panel) . '.cookie';
        if (!file_exists($this->cookie_file)) touch($this->cookie_file);
    }

    private function getKeyData() {
        $now = time();
        return [
            'request_token' => md5($now . md5($this->bt_key)),
            'request_time'  => $now
        ];
    }

    private function httpPost($url, $data, $timeout = 30) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $out = curl_exec($ch);
        curl_close($ch);
        return $out;
    }

    public function getSystemTotal() {
        $url = $this->bt_panel . '/system?action=GetSystemTotal';
        return json_decode($this->httpPost($url, $this->getKeyData()), true);
    }

    public function getDiskInfo() {
        $url = $this->bt_panel . '/system?action=GetDiskInfo';
        return json_decode($this->httpPost($url, $this->getKeyData()), true);
    }

    public function getNetWork() {
        $url = $this->bt_panel . '/system?action=GetNetWork';
        return json_decode($this->httpPost($url, $this->getKeyData()), true);
    }
}

// ================= API 数据接口 =================
if (isset($_GET['action']) && $_GET['action'] === 'getData') {
    header('Content-Type: application/json');
    $result = ['servers' => []];
    foreach ($servers as $svr) {
        $api = new BtApi($svr['panel'], $svr['key']);
        $sys  = $api->getSystemTotal();
        $disk = $api->getDiskInfo();
        $net  = $api->getNetWork();

        $result['servers'][] = [
            'name' => $svr['name'],
            'sys'  => $sys ?: null,
            'disk' => $disk ?: null,
            'net'  => $net ?: null
        ];
    }
    echo json_encode($result);
    exit;
}

// ================= 主页面 HTML =================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器实时监控</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.4.3/echarts.min.js"></script>
    <style>
        body {
            background: #f0f2f5;
            padding: 15px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .container { max-width: 1600px; }
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            border: none;
            margin-bottom: 12px;
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            font-weight: 600;
            padding: 8px 14px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .card-body { padding: 10px 14px 14px; }
        .stat-label {
            color: #6c757d;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .stat-value { font-size: 1.1rem; font-weight: 700; color: #1a1a2e; }
        .icon-circle {
            width: 30px; height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .bg-soft-primary { background: #e7f3ff; color: #0d6efd; }
        .bg-soft-warning { background: #fff3cd; color: #ffc107; }
        .bg-soft-success { background: #d4edda; color: #198754; }
        .bg-soft-info { background: #d1ecf1; color: #0dcaf0; }
        .bg-soft-secondary { background: #e9ecef; color: #6c757d; }

        .progress { height: 6px; border-radius: 6px; background-color: #e9ecef; }
        .progress-bar { border-radius: 6px; }

        .disk-item {
            border-bottom: 1px solid #f1f3f5;
            padding: 6px 0;
        }
        .disk-item:last-child { border-bottom: none; }
        .disk-label { font-weight: 500; font-size: 0.85rem; }
        .disk-usage { font-size: 0.7rem; color: #6c757d; }

        .chart-container {
            width: 100%;
            height: 200px;
        }

        /* 内存环形容器：SVG作为背景，文字居中覆盖 */
        .mem-svg-container {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto;
        }
        .mem-svg-container svg {
            display: block;
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        .mem-svg-container .circle-bg {
            fill: none;
            stroke: #e5e9f2;
            stroke-width: 14;
        }
        .mem-svg-container .circle-progress {
            fill: none;
            stroke-width: 14;
            stroke-linecap: round;
            transition: stroke-dasharray 0.6s, stroke 0.6s;
        }
        .mem-percent-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 20px;
            font-weight: bold;
            color: #1a1a2e;
            pointer-events: none;
            text-align: center;
            line-height: 1.2;
        }

        .server-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e9ecef;
        }
        .server-title i { margin-right: 6px; }

        .col-separator {
            border-right: 1px solid #dee2e6;
        }
        @media (max-width: 768px) {
            .col-separator {
                border-right: none;
                border-bottom: 2px solid #dee2e6;
                padding-bottom: 20px;
                margin-bottom: 20px;
            }
        }
        .text-muted.small { font-size: 0.7rem; }
        .mb-1 { margin-bottom: 0.15rem !important; }
        .mb-2 { margin-bottom: 0.3rem !important; }
        .mb-3 { margin-bottom: 0.5rem !important; }
        .mt-1 { margin-top: 0.15rem !important; }

        .error-box {
            background: #fff5f5;
            border: 1px solid #fcc;
            border-radius: 8px;
            padding: 10px 14px;
            color: #d63031;
            margin-bottom: 12px;
            font-size: 0.85rem;
        }

        /* 内存卡片左侧 - 已去掉大字 */
        .mem-left {
            flex: 1;
            text-align: left;
            padding-right: 10px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .mem-left .detail {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .mem-left .detail .used {
            font-weight: 700;
        }
        .mem-right {
            flex: 1.2;
            min-width: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .footer-link {
            color: #6c757d;
            text-decoration: none;
        }
        .footer-link:hover {
            color: #0d6efd;
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- 头部 -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h1 class="h4 mb-0 fw-bold"><i class="fas fa-server me-2" style="color:#0d6efd;"></i>服务器实时监控</h1>
            <p class="text-muted small mt-0" style="font-size:0.75rem;" id="updateTime">最后更新：--</p>
        </div>
        <div>
            <span class="badge bg-success me-2" id="statusBadge">● 实时</span>
        </div>
    </div>

    <!-- 双栏容器 -->
    <div class="row g-3" id="mainRow">
        <!-- 两栏将由 JavaScript 动态生成 -->
    </div>

    <footer class="text-center text-muted small mt-3" style="font-size:0.75rem;">
        <i class="fab fa-github"></i>
        <a href="https://github.com/songshuxiao/bt-panel-status" target="_blank" class="footer-link">Github开源地址</a>
    </footer>
</div>

<script>
    // =============== 全局配置 ===============
    const MAX_POINTS = 60;
    const REFRESH_INTERVAL = 2000;

    const serverData = {};
    const charts = {};
    const memRingCache = {};

    // =============== 工具函数 ===============
    function getTimeStr() {
        const d = new Date();
        return d.toTimeString().slice(0,8);
    }

    function getMemColor(pct) {
        if (pct <= 80) return '#52c41a';
        if (pct <= 90) return '#faad14';
        return '#f5222d';
    }

    // =============== 初始化服务器列 ===============
    function initColumns(servers) {
        const row = document.getElementById('mainRow');
        row.innerHTML = '';
        servers.forEach((s, idx) => {
            const col = document.createElement('div');
            col.className = `col-12 col-md-6 ${idx === 0 ? 'col-separator' : ''}`;
            col.id = `serverCol${idx}`;

            const title = document.createElement('div');
            title.className = 'server-title';
            title.innerHTML = `<i class="fas fa-server" style="color:${idx===0?'#0d6efd':'#198754'};"></i> ${s.name}`;
            col.appendChild(title);

            col.appendChild(createSysCard(idx));
            col.appendChild(createChartCard('CPU & 负载', 'cpu', idx));
            col.appendChild(createMemCard(idx));
            col.appendChild(createChartCard('流量', 'net', idx));
            col.appendChild(createDiskCard(idx));

            row.appendChild(col);

            serverData[idx] = {
                time: [],
                cpu: [],
                load: [],
                down: [],
                up: []
            };

            charts[`cpu_${idx}`] = initLineChart(`cpuChart_${idx}`, 'cpu', idx);
            charts[`net_${idx}`] = initLineChart(`netChart_${idx}`, 'net', idx);

            initMemRingSVG(idx);
        });
    }

    // ---------- 创建卡片 ----------
    function createSysCard(idx) {
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
            <div class="card-header"><span class="icon-circle bg-soft-primary"><i class="fas fa-info-circle"></i></span> 系统信息</div>
            <div class="card-body" id="sysInfo_${idx}">
                <div class="d-flex flex-wrap justify-content-between mb-1"><span class="stat-label">操作系统</span><span class="fw-semibold" style="font-size:0.85rem;">--</span></div>
                <div class="d-flex flex-wrap justify-content-between mb-1"><span class="stat-label">面板版本</span><span class="fw-semibold" style="font-size:0.85rem;">--</span></div>
                <div class="d-flex flex-wrap justify-content-between mb-1"><span class="stat-label">运行时间</span><span class="fw-semibold" style="font-size:0.85rem;">--</span></div>
                <div class="d-flex flex-wrap justify-content-between mb-0"><span class="stat-label">CPU 核心</span><span class="fw-semibold" style="font-size:0.85rem;">--</span></div>
            </div>
        `;
        return card;
    }

    function createChartCard(title, type, idx) {
        const card = document.createElement('div');
        card.className = 'card';
        const iconMap = { cpu: 'bg-soft-warning fa-microchip', net: 'bg-soft-secondary fa-network-wired' };
        card.innerHTML = `
            <div class="card-header"><span class="icon-circle ${iconMap[type]}"><i class="fas ${iconMap[type].split(' ')[1]}"></i></span> ${title}</div>
            <div class="card-body">
                <div class="chart-container" id="${type}Chart_${idx}"></div>
            </div>
        `;
        return card;
    }

    // 内存卡片 - 左侧无大字，右侧环形SVG + div文字
    function createMemCard(idx) {
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
            <div class="card-header"><span class="icon-circle bg-soft-success"><i class="fas fa-memory"></i></span> 内存</div>
            <div class="card-body" style="display:flex; align-items:center; padding:10px 14px;">
                <div class="mem-left">
                    <div class="detail" id="memDetail_${idx}">
                        <span class="used" style="font-size:1.2rem;">--</span>
                        <span style="font-size:0.9rem; color:#6c757d;"> / --</span>
                    </div>
                </div>
                <div class="mem-right">
                    <div class="mem-svg-container" id="memRingContainer_${idx}">
                        <svg viewBox="0 0 100 100">
                            <circle class="circle-bg" cx="50" cy="50" r="43" />
                            <circle class="circle-progress" cx="50" cy="50" r="43"
                                    stroke="#52c41a"
                                    stroke-dasharray="0, 270.18"
                                    stroke-dashoffset="0" />
                        </svg>
                        <div class="mem-percent-text" id="memPercentText_${idx}">0%</div>
                    </div>
                </div>
            </div>
        `;
        return card;
    }

    function createDiskCard(idx) {
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
            <div class="card-header"><span class="icon-circle bg-soft-info"><i class="fas fa-hdd"></i></span> 磁盘分区 <span class="badge bg-light text-dark ms-2" id="diskCount_${idx}">0</span></div>
            <div class="card-body" id="diskBody_${idx}">
                <div class="text-muted text-center small">加载中...</div>
            </div>
        `;
        return card;
    }

    // ---------- 初始化内存环形 ----------
    function initMemRingSVG(idx) {
        const container = document.getElementById(`memRingContainer_${idx}`);
        if (!container) return;
        const svg = container.querySelector('svg');
        const progressCircle = svg.querySelector('.circle-progress');
        const textDiv = document.getElementById(`memPercentText_${idx}`);

        const radius = 43;
        const circumference = 2 * Math.PI * radius;

        memRingCache[idx] = {
            progressCircle,
            textDiv,
            circumference,
            radius
        };
    }

    function updateMemRingSVG(idx, pct, color) {
        const cache = memRingCache[idx];
        if (!cache) return;
        const { progressCircle, textDiv, circumference } = cache;
        const val = Math.min(pct, 100);
        const dash = (val / 100) * circumference;
        const gap = circumference - dash;
        progressCircle.setAttribute('stroke-dasharray', `${dash}, ${gap}`);
        progressCircle.setAttribute('stroke', color);
        if (textDiv) {
            textDiv.textContent = val.toFixed(0) + '%';
        }
    }

    // ---------- 初始化ECharts折线图 ----------
    function initLineChart(domId, type, idx) {
        const dom = document.getElementById(domId);
        if (!dom) return null;
        const chart = echarts.init(dom);
        let option = {
            tooltip: { trigger: 'axis' },
            legend: { show: true, bottom: 0, left: 'center', icon: 'roundRect', itemWidth: 12, itemHeight: 6 },
            grid: { left: 50, right: 20, top: 20, bottom: 40 },
            xAxis: { type: 'category', data: [], axisLabel: { fontSize: 10, interval: 5 } },
            series: []
        };

        if (type === 'cpu') {
            option.yAxis = [
                { 
                    type: 'value', 
                    name: '', 
                    min: 0, 
                    max: 100, 
                    axisLabel: { fontSize: 10, formatter: '{value}%' } 
                },
                { 
                    type: 'value', 
                    name: '', 
                    min: 0, 
                    axisLabel: { fontSize: 10, formatter: '{value}%' } 
                }
            ];
            option.series = [
                { 
                    name: 'CPU 使用率', 
                    type: 'line', 
                    data: [], 
                    smooth: true, 
                    yAxisIndex: 0, 
                    lineStyle: { color: '#0d6efd' },
                    tooltip: { valueFormatter: (v) => v !== null ? v.toFixed(2) + '%' : '--' }
                },
                { 
                    name: '负载百分比', 
                    type: 'line', 
                    data: [], 
                    smooth: true, 
                    yAxisIndex: 1, 
                    lineStyle: { color: '#ffc107' },
                    tooltip: { valueFormatter: (v) => v !== null ? v.toFixed(2) + '%' : '--' }
                }
            ];
        } else if (type === 'net') {
            option.yAxis = { 
                type: 'value', 
                name: '',
                axisLabel: { 
                    fontSize: 10,
                    formatter: function(value) { return Math.round(value); }
                } 
            };
            option.series = [
                { 
                    name: '下行 (KB/s)', 
                    type: 'line', 
                    data: [], 
                    smooth: true, 
                    lineStyle: { color: '#0d6efd' },
                    tooltip: { valueFormatter: (v) => v !== null ? v.toFixed(2) + ' KB/s' : '--' }
                },
                { 
                    name: '上行 (KB/s)', 
                    type: 'line', 
                    data: [], 
                    smooth: true, 
                    lineStyle: { color: '#dc3545' },
                    tooltip: { valueFormatter: (v) => v !== null ? v.toFixed(2) + ' KB/s' : '--' }
                }
            ];
        }
        chart.setOption(option);
        return chart;
    }

    // =============== 更新数据 ===============
    function updateData() {
        fetch('?action=getData')
            .then(res => res.json())
            .then(data => {
                if (!data.servers) return;
                const now = getTimeStr();
                document.getElementById('updateTime').innerText = '最后更新：' + now;

                data.servers.forEach((svr, idx) => {
                    // ---- 系统信息 ----
                    if (svr.sys) {
                        const sysBody = document.getElementById(`sysInfo_${idx}`);
                        if (sysBody) {
                            const spans = sysBody.querySelectorAll('.fw-semibold');
                            if (spans.length >= 4) {
                                spans[0].textContent = svr.sys.system || '--';
                                spans[1].textContent = 'v' + (svr.sys.version || '--');
                                spans[2].textContent = svr.sys.time || '--';
                                spans[3].textContent = svr.sys.cpuNum ? svr.sys.cpuNum + ' 核' : '--';
                            }
                        }
                    }

                    // ---- 磁盘分区 ----
                    const diskBody = document.getElementById(`diskBody_${idx}`);
                    const diskCount = document.getElementById(`diskCount_${idx}`);
                    if (diskBody && svr.disk && Array.isArray(svr.disk) && svr.disk.length > 0) {
                        diskCount.textContent = svr.disk.length;
                        let html = '';
                        svr.disk.forEach(item => {
                            const pct = item.size && item.size[3] ? parseInt(item.size[3]) : 0;
                            const barColor = pct > 85 ? 'bg-danger' : (pct > 70 ? 'bg-warning' : 'bg-primary');
                            html += `
                                <div class="disk-item">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-0">
                                        <div><span class="disk-label"><i class="fas fa-folder-open me-1"></i>${item.path || '/'}</span>
                                        <span class="disk-usage ms-2">${item.size ? item.size[0] : '--'} · ${item.size ? item.size[1] : '--'} 已用 · ${item.size ? item.size[2] : '--'} 可用</span></div>
                                        <span class="fw-semibold" style="font-size:0.8rem; ${pct>85?'color:#dc3545;':''}">${pct}%</span>
                                    </div>
                                    <div class="progress mt-1"><div class="progress-bar ${barColor}" style="width:${Math.min(pct,100)}%"></div></div>
                                    <div class="text-muted small mt-0" style="font-size:0.65rem;">Inode: ${item.inodes ? item.inodes.join(' · ') : '--'}</div>
                                </div>
                            `;
                        });
                        diskBody.innerHTML = html;
                    } else {
                        diskBody.innerHTML = '<div class="text-muted text-center small">无磁盘信息</div>';
                        if (diskCount) diskCount.textContent = '0';
                    }

                    // ---- 内存环形 + 文字 ----
                    if (svr.sys && svr.sys.memTotal && svr.sys.memRealUsed !== undefined) {
                        const total = svr.sys.memTotal;
                        const used = svr.sys.memRealUsed;
                        const pct = total > 0 ? (used / total * 100) : 0;
                        const val = Math.round(pct * 10) / 10;
                        const color = getMemColor(val);

                        const detailEl = document.getElementById(`memDetail_${idx}`);
                        if (detailEl) {
                            const usedSpan = detailEl.querySelector('.used');
                            const totalSpan = detailEl.querySelector('span:last-child');
                            if (usedSpan) {
                                usedSpan.textContent = `${used} MB`;
                                usedSpan.style.color = color;
                            }
                            if (totalSpan) totalSpan.textContent = ` / ${total} MB`;
                        }

                        updateMemRingSVG(idx, val, color);
                    }

                    // ---- 折线图数据 ----
                    const q = serverData[idx];
                    if (svr.sys && svr.net) {
                        const cpu = svr.sys.cpuRealUsed !== undefined ? parseFloat(svr.sys.cpuRealUsed) : null;
                        const cpuNum = svr.sys.cpuNum || 1;
                        const loadRaw = svr.net.load && svr.net.load.one !== undefined ? parseFloat(svr.net.load.one) : null;
                        const loadPercent = loadRaw !== null ? (loadRaw / cpuNum * 100) : null;

                        const down = svr.net.down !== undefined ? parseFloat(svr.net.down) : null;
                        const up = svr.net.up !== undefined ? parseFloat(svr.net.up) : null;

                        q.time.push(now);
                        q.cpu.push(cpu);
                        q.load.push(loadPercent);
                        q.down.push(down);
                        q.up.push(up);

                        if (q.time.length > MAX_POINTS) {
                            q.time.shift(); q.cpu.shift(); q.load.shift(); q.down.shift(); q.up.shift();
                        }

                        updateLineChart(idx, 'cpu');
                        updateLineChart(idx, 'net');
                    }
                });
            })
            .catch(err => console.error('数据请求失败:', err));
    }

    // =============== 更新折线图（修复数据缩放问题） ===============
    function updateLineChart(idx, type) {
        const chart = charts[`${type}_${idx}`];
        if (!chart) return;
        const q = serverData[idx];
        if (!q || q.time.length === 0) return;

        const option = chart.getOption();
        option.xAxis[0].data = q.time.slice();

        if (type === 'cpu') {
            option.series[0].data = q.cpu.slice();
            option.series[1].data = q.load.slice();
            chart.setOption(option, true);
            return;
        }

        // ===== 网络流量部分 =====
        // 1. 取最近3个点（约6秒）决定单位和纵轴
        const recentCount = Math.min(3, q.down.length);
        const recentDown = q.down.slice(-recentCount);
        const recentUp = q.up.slice(-recentCount);
        const allRecent = recentDown.concat(recentUp).filter(v => v !== null && v !== undefined);
        let maxVal = 0;
        if (allRecent.length > 0) maxVal = Math.max(...allRecent);
        if (maxVal === 0) {
            const allValues = q.down.concat(q.up).filter(v => v !== null && v !== undefined);
            if (allValues.length > 0) maxVal = Math.max(...allValues);
        }

        const useMB = maxVal >= 1024;
        const unit = useMB ? 'MB/s' : 'KB/s';
        const divisor = useMB ? 1024 : 1;

        // 更新图例
        option.series[0].name = '下行 (' + unit + ')';
        option.series[1].name = '上行 (' + unit + ')';

        // ***** 关键修复：数据缩放 *****
        option.series[0].data = q.down.slice().map(v => v !== null ? v / divisor : null);
        option.series[1].data = q.up.slice().map(v => v !== null ? v / divisor : null);

        // 更新 tooltip（已缩放）
        option.series[0].tooltip = {
            valueFormatter: (v) => v !== null ? v.toFixed(2) + ' ' + unit : '--'
        };
        option.series[1].tooltip = {
            valueFormatter: (v) => v !== null ? v.toFixed(2) + ' ' + unit : '--'
        };

        // ----- 纵轴自适应（基于缩放后的数据） -----
        const scaledData = option.series[0].data.concat(option.series[1].data).filter(v => v !== null);
        let maxDisplay = 0;
        if (scaledData.length > 0) maxDisplay = Math.max(...scaledData);
        if (maxDisplay === 0) maxDisplay = 0.5; // 避免空数据时轴为0

        let step, maxAxis;
        if (useMB) {
            // MB/s 模式
            if (maxDisplay <= 2) {
                step = 0.5;
            } else {
                step = 1;
            }
            maxAxis = Math.ceil(maxDisplay / step) * step;
            if (maxAxis === 0) maxAxis = step;
        } else {
            // KB/s 模式：自动步进，但保证最大值至少 5
            maxAxis = Math.ceil(maxDisplay * 1.2);
            if (maxAxis < 5) maxAxis = 5;
            step = 'auto';
        }

        const yAxis = option.yAxis[0];
        if (useMB) {
            yAxis.max = maxAxis;
            yAxis.interval = step;
            yAxis.axisLabel.formatter = function(value) {
                if (step % 1 !== 0) {
                    return value.toFixed(1);
                } else {
                    return Math.round(value).toString();
                }
            };
        } else {
            yAxis.max = maxAxis;
            delete yAxis.interval;
            yAxis.splitNumber = 5;
            yAxis.axisLabel.formatter = function(value) {
                return Math.round(value).toString();
            };
        }

        chart.setOption(option, true);
    }

    // =============== 启动 ===============
    const serverNames = <?php echo json_encode(array_column($servers, 'name')); ?>;
    const serverList = serverNames.map(name => ({ name }));
    initColumns(serverList);

    updateData();
    setInterval(updateData, REFRESH_INTERVAL);

    window.addEventListener('resize', () => {
        Object.values(charts).forEach(ch => ch && ch.resize());
    });
</script>
</body>
</html>