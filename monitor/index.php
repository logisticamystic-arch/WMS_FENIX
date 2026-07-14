<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fénix WMS — Performance Monitor</title>
    
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <!-- FontAwesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* CSS RESET & VARIABLES */
        :root {
            --bg-primary: #0b0f19;
            --bg-secondary: #131b2e;
            --bg-card: rgba(30, 41, 59, 0.4);
            --bg-card-hover: rgba(30, 41, 59, 0.6);
            --border-color: rgba(255, 255, 255, 0.08);
            
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            
            --accent-color: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.2);
            --emerald-color: #10b981;
            --emerald-glow: rgba(16, 185, 129, 0.2);
            --amber-color: #f59e0b;
            --amber-glow: rgba(245, 158, 11, 0.2);
            --rose-color: #f43f5e;
            --rose-glow: rgba(244, 63, 94, 0.2);
            --cyan-color: #06b6d4;
            --cyan-glow: rgba(6, 182, 212, 0.2);
            
            --font-main: 'Inter', sans-serif;
            --font-title: 'Outfit', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* SIDEBAR LAYOUT */
        aside {
            width: 260px;
            background-color: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 24px;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 36px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent-color), var(--cyan-color));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            box-shadow: 0 0 15px var(--accent-glow);
        }

        .logo-text {
            font-family: var(--font-title);
            font-weight: 700;
            font-size: 20px;
            letter-spacing: -0.5px;
            background: linear-gradient(120deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-sub {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: -4px;
        }

        /* NAVIGATION */
        nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-speed);
            border: 1px solid transparent;
        }

        .nav-item:hover {
            color: var(--text-primary);
            background-color: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.05);
        }

        .nav-item.active {
            color: white;
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.15) 0%, rgba(99, 102, 241, 0.05) 100%);
            border-color: rgba(99, 102, 241, 0.3);
            box-shadow: inset 0 0 12px rgba(99, 102, 241, 0.1);
        }

        .nav-item i {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        /* FOOTER INFO */
        .sidebar-footer {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        /* MAIN CONTENT CONTAINER */
        main {
            margin-left: 260px;
            flex-grow: 1;
            padding: 40px;
            max-width: 1600px;
            width: calc(100% - 260px);
        }

        /* HEADER AREA */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 36px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .header-title h1 {
            font-family: var(--font-title);
            font-weight: 700;
            font-size: 28px;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        .header-title p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* BUTTONS & DROPDOWNS */
        .status-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.05);
        }
        .status-badge.offline {
            background: rgba(244, 63, 94, 0.1);
            color: var(--rose-color);
            border: 1px solid rgba(244, 63, 94, 0.2);
        }

        .status-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: currentColor;
            box-shadow: 0 0 8px currentColor;
            display: inline-block;
        }

        .btn {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all var(--transition-speed);
        }

        .btn:hover {
            background-color: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .btn-primary {
            background-color: var(--accent-color);
            border-color: transparent;
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            background-color: #4f46e5;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.4);
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: rgba(244, 63, 94, 0.1);
            border-color: rgba(244, 63, 94, 0.3);
            color: var(--rose-color);
        }

        .btn-danger:hover {
            background-color: var(--rose-color);
            color: white;
            border-color: transparent;
        }

        select {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            cursor: pointer;
            transition: all var(--transition-speed);
        }

        select:focus {
            border-color: var(--accent-color);
        }

        /* AUTO-REFRESH PROGRESS BAR */
        .progress-bar-container {
            width: 100%;
            height: 3px;
            background: rgba(255, 255, 255, 0.05);
            margin-bottom: 24px;
            border-radius: 2px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent-color), var(--cyan-color));
            box-shadow: 0 0 10px rgba(99, 102, 241, 0.5);
            transition: width 0.1s linear;
        }

        /* METRICS CARDS GRID */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all var(--transition-speed);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .kpi-card:hover {
            background: var(--bg-card-hover);
            border-color: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
        }

        .kpi-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .kpi-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .kpi-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-value {
            font-family: var(--font-title);
            font-size: 24px;
            font-weight: 700;
            color: white;
        }

        /* Color variations for KPI icons */
        .kpi-indigo { background: rgba(99, 102, 241, 0.1); color: var(--accent-color); border: 1px solid rgba(99, 102, 241, 0.2); }
        .kpi-emerald { background: rgba(16, 185, 129, 0.1); color: var(--emerald-color); border: 1px solid rgba(16, 185, 129, 0.2); }
        .kpi-amber { background: rgba(245, 158, 11, 0.1); color: var(--amber-color); border: 1px solid rgba(245, 158, 11, 0.2); }
        .kpi-rose { background: rgba(244, 63, 94, 0.1); color: var(--rose-color); border: 1px solid rgba(244, 63, 94, 0.2); }
        .kpi-cyan { background: rgba(6, 182, 212, 0.1); color: var(--cyan-color); border: 1px solid rgba(6, 182, 212, 0.2); }

        /* VISUALIZATION CONTAINER */
        .dashboard-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .dashboard-row {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            backdrop-filter: blur(10px);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .panel-title {
            font-family: var(--font-title);
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-body {
            position: relative;
        }

        /* TABLES */
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        th {
            color: var(--text-secondary);
            font-weight: 500;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            color: var(--text-primary);
        }

        tr:hover td {
            background-color: rgba(255, 255, 255, 0.01);
        }

        /* METHOD BADGES */
        .method-badge {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            display: inline-block;
            text-align: center;
            min-width: 45px;
        }
        .method-get { background: rgba(6, 182, 212, 0.1); color: var(--cyan-color); border: 1px solid rgba(6, 182, 212, 0.2); }
        .method-post { background: rgba(99, 102, 241, 0.1); color: var(--accent-color); border: 1px solid rgba(99, 102, 241, 0.2); }
        .method-put { background: rgba(245, 158, 11, 0.1); color: var(--amber-color); border: 1px solid rgba(245, 158, 11, 0.2); }
        .method-delete { background: rgba(244, 63, 94, 0.1); color: var(--rose-color); border: 1px solid rgba(244, 63, 94, 0.2); }

        /* STATUS BADGES FOR TABLE */
        .status-table {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-success { background: rgba(16, 185, 129, 0.1); color: var(--emerald-color); }
        .status-client-error { background: rgba(245, 158, 11, 0.1); color: var(--amber-color); }
        .status-server-error { background: rgba(244, 63, 94, 0.1); color: var(--rose-color); }

        /* TAB VIEWS CONTROLLER */
        .tab-content {
            display: none;
            animation: fadeIn 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* TRANSACTIONS SECTION SPECIFIC */
        .filters-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-input {
            flex-grow: 1;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            outline: none;
            transition: all var(--transition-speed);
        }

        .search-input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px var(--accent-glow);
        }

        .latency-colored {
            font-weight: 600;
        }
        .latency-low { color: var(--emerald-color); }
        .latency-medium { color: var(--amber-color); }
        .latency-high { color: var(--rose-color); }

        /* USERS VIEW */
        .users-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .user-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            transition: all var(--transition-speed);
        }

        .user-card:hover {
            background: var(--bg-card-hover);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(6, 182, 212, 0.2));
            border: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            font-weight: 600;
            position: relative;
        }

        .user-avatar .online-indicator {
            width: 10px;
            height: 10px;
            background-color: var(--emerald-color);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            position: absolute;
            bottom: 0;
            right: 0;
            box-shadow: 0 0 8px var(--emerald-color);
        }

        .user-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: white;
        }

        .user-role {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 6px;
            background-color: rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            width: fit-content;
            color: var(--text-secondary);
        }

        .user-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .user-meta span i {
            margin-right: 4px;
            width: 12px;
            text-align: center;
        }

        /* LOG VIEWER */
        .log-viewer-box {
            background-color: #05070f;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            height: 550px;
            overflow-y: auto;
            font-family: var(--font-mono);
            font-size: 12px;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .log-line {
            white-space: pre-wrap;
            word-break: break-all;
            padding: 2px 8px;
            border-radius: 4px;
            color: #d1d5db;
        }

        .log-line.log-slow { color: #f59e0b; background-color: rgba(245, 158, 11, 0.05); border-left: 3px solid var(--amber-color); }
        .log-line.log-error, .log-line.log-fatal { color: #f43f5e; background-color: rgba(244, 63, 94, 0.05); border-left: 3px solid var(--rose-color); }
        .log-line.log-warn { color: #fb7185; background-color: rgba(244, 63, 94, 0.02); }
        .log-line.log-jwt { color: #38bdf8; background-color: rgba(56, 189, 248, 0.03); }
        .log-line.log-info { color: #10b981; }

        /* REPORTS TAB STYLES */
        .reports-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
        }

        @media (max-width: 900px) {
            .reports-layout {
                grid-template-columns: 1fr;
            }
        }

        .reports-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .report-list-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px;
            height: 500px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .report-item-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            transition: all var(--transition-speed);
        }

        .report-item-card:hover, .report-item-card.active {
            background: rgba(99, 102, 241, 0.08);
            border-color: rgba(99, 102, 241, 0.4);
        }

        .report-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .report-item-date {
            font-size: 13px;
            font-weight: 600;
        }

        .score-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-title);
            font-weight: 800;
            font-size: 15px;
        }

        /* Score variants */
        .score-A { background: rgba(16, 185, 129, 0.15); color: var(--emerald-color); border: 2px solid var(--emerald-color); box-shadow: 0 0 8px var(--emerald-glow); }
        .score-B { background: rgba(6, 182, 212, 0.15); color: var(--cyan-color); border: 2px solid var(--cyan-color); box-shadow: 0 0 8px var(--cyan-glow); }
        .score-C { background: rgba(245, 158, 11, 0.15); color: var(--amber-color); border: 2px solid var(--amber-color); box-shadow: 0 0 8px var(--amber-glow); }
        .score-F { background: rgba(244, 63, 94, 0.15); color: var(--rose-color); border: 2px solid var(--rose-color); box-shadow: 0 0 8px var(--rose-glow); }

        .report-item-summary {
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
        }

        /* Report Main view */
        .report-main-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            min-height: 560px;
            display: flex;
            flex-direction: column;
        }

        .report-header-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 24px;
            margin-bottom: 24px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .report-score-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .report-score-badge {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-title);
            font-weight: 800;
            font-size: 36px;
        }

        .report-status-text {
            font-family: var(--font-title);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .report-period-text {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .anomaly-box {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 20px;
        }

        .anomaly-item-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--accent-color);
        }

        .anomaly-item-card.sev-Alta { border-left-color: var(--amber-color); }
        .anomaly-item-card.sev-Crítica { border-left-color: var(--rose-color); }
        .anomaly-item-card.sev-Media { border-left-color: var(--cyan-color); }

        .anomaly-item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        .anomaly-desc {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .anomaly-sol {
            font-size: 12px;
            background: rgba(255, 255, 255, 0.03);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 2px solid var(--emerald-color);
            color: #d1d5db;
        }

        .recs-list {
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .rec-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .rec-item i {
            color: var(--emerald-color);
            margin-top: 2px;
        }

        /* MODAL FOR SLOW HINTS */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.open {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 600px;
            max-width: 90%;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            animation: modalSlide 0.3s ease;
        }

        @keyframes modalSlide {
            from { transform: translateY(-30px); }
            to { transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
        }

        .modal-title {
            font-family: var(--font-title);
            font-size: 18px;
            font-weight: 600;
        }

        .modal-body {
            font-family: var(--font-mono);
            font-size: 13px;
            background-color: #070913;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            max-height: 350px;
            overflow-y: auto;
            white-space: pre-wrap;
            color: #e2e8f0;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
        }

        .modal-close:hover {
            color: white;
        }

        /* LOADER */
        .loader-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(99, 102, 241, 0.1);
            border-radius: 50%;
            border-top-color: var(--accent-color);
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <aside>
        <div class="logo-area">
            <div class="logo-icon">
                <i class="fa-solid fa-gauge-high"></i>
            </div>
            <div>
                <div class="logo-text">Fénix Monitor</div>
                <div class="logo-sub">Rendimiento WMS</div>
            </div>
        </div>

        <nav>
            <div class="nav-item active" onclick="switchTab('tab-dashboard', this)">
                <i class="fa-solid fa-chart-line"></i>
                Dashboard
            </div>
            <div class="nav-item" onclick="switchTab('tab-transactions', this)">
                <i class="fa-solid fa-bolt"></i>
                Transacciones Lentas
            </div>
            <div class="nav-item" onclick="switchTab('tab-users', this)">
                <i class="fa-solid fa-users"></i>
                Usuarios Activos
            </div>
            <div class="nav-item" onclick="switchTab('tab-database', this)">
                <i class="fa-solid fa-database"></i>
                Estadísticas de BD
            </div>
            <div class="nav-item" onclick="switchTab('tab-logs', this)">
                <i class="fa-solid fa-file-lines"></i>
                Visor de Logs
            </div>
            <div class="nav-item" onclick="switchTab('tab-reports', this)">
                <i class="fa-solid fa-file-shield"></i>
                Informes (2h)
            </div>
        </nav>

        <div class="sidebar-footer">
            <div>WMS Fénix Engine</div>
            <div id="sidebar-server-info">Cargando servidor...</div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main>
        
        <!-- HEADER -->
        <header>
            <div class="header-title">
                <h1 id="page-title">Dashboard General</h1>
                <p>Consumo de recursos, transacciones lentas y estado del sistema en tiempo real.</p>
            </div>

            <div class="header-controls">
                <div id="connection-status" class="status-badge">
                    <span class="dot"></span> <span class="label">CONECTADO</span>
                </div>
                
                <select id="refresh-interval" onchange="setupAutoRefresh()">
                    <option value="manual">Refresco Manual</option>
                    <option value="5000">Cada 5 Segundos</option>
                    <option value="10000" selected>Cada 10 Segundos</option>
                    <option value="30000">Cada 30 Segundos</option>
                    <option value="60000">Cada 1 Minuto</option>
                </select>

                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fa-solid fa-rotate"></i> Refrescar
                </button>
            </div>
        </header>

        <!-- PROGRESS BAR -->
        <div class="progress-bar-container">
            <div id="refresh-progress" class="progress-bar"></div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 1: DASHBOARD -->
        <!-- ============================================== -->
        <div id="tab-dashboard" class="tab-content active">
            
            <!-- KPI CARDS -->
            <div class="kpi-grid">
                
                <div class="kpi-card">
                    <div class="kpi-icon kpi-indigo">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Usuarios Activos</span>
                        <span id="kpi-active-users" class="kpi-value">--</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon kpi-amber">
                        <i class="fa-solid fa-stopwatch"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Latencia Promedio</span>
                        <span id="kpi-avg-latency" class="kpi-value">-- ms</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon kpi-rose">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Errores 24h</span>
                        <span id="kpi-errors-24h" class="kpi-value">--</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan">
                        <i class="fa-solid fa-network-wired"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Conexiones BD</span>
                        <span id="kpi-db-conns" class="kpi-value">--</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon kpi-emerald">
                        <i class="fa-solid fa-hard-drive"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Uso de Disco</span>
                        <span id="kpi-disk-used" class="kpi-value">--</span>
                    </div>
                </div>

            </div>

            <!-- CHARTS ROW -->
            <div class="dashboard-row">
                
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">
                            <i class="fa-solid fa-gauge"></i> Endpoints más Lentos (Promedio ms)
                        </span>
                    </div>
                    <div class="panel-body" style="height: 320px;">
                        <canvas id="chart-slowest-endpoints"></canvas>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">
                            <i class="fa-solid fa-memory"></i> Consumo de Memoria por Endpoint (Promedio KB)
                        </span>
                    </div>
                    <div class="panel-body" style="height: 320px;">
                        <canvas id="chart-memory-usage"></canvas>
                    </div>
                </div>

            </div>

            <!-- SECOND ROW: QUICK STATS -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">
                        <i class="fa-solid fa-arrow-down-up-lock"></i> Resumen de Respuestas HTTP
                    </span>
                </div>
                <div class="panel-body">
                    <div style="display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px; padding: 15px 0;">
                        <div style="text-align: center;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--emerald-color);" id="dashboard-total-requests">0</div>
                            <div style="font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-top: 4px;">Peticiones Registradas</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--cyan-color);" id="dashboard-success-requests">0</div>
                            <div style="font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-top: 4px;">Exitosas (2xx)</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--amber-color);" id="dashboard-warn-requests">0</div>
                            <div style="font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-top: 4px;">Clientes Err (4xx)</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 28px; font-weight: 700; color: var(--rose-color);" id="dashboard-error-requests">0</div>
                            <div style="font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-top: 4px;">Errores Críticos (5xx)</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================== -->
        <!-- TAB 2: SLOW TRANSACTIONS -->
        <!-- ============================================== -->
        <div id="tab-transactions" class="tab-content">
            
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title"><i class="fa-solid fa-bolt"></i> Historial de Peticiones Lentas / Pesadas</span>
                    <button class="btn btn-danger btn-sm" onclick="confirmClearMetrics()">
                        <i class="fa-solid fa-trash-can"></i> Purgar Historial
                    </button>
                </div>
                
                <div class="panel-body">
                    <!-- Filters -->
                    <div class="filters-row">
                        <input type="text" id="tx-filter-endpoint" class="search-input" placeholder="Filtrar por Endpoint o Patrón..." onkeyup="filterTransactionsTable()">
                        <select id="tx-filter-method" onchange="filterTransactionsTable()">
                            <option value="">Cualquier Método</option>
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                        <select id="tx-filter-latency" onchange="filterTransactionsTable()">
                            <option value="">Cualquier Retraso</option>
                            <option value="low">Menor a 3s</option>
                            <option value="medium">De 3s a 10s</option>
                            <option value="high">Mayor a 10s</option>
                        </select>
                    </div>

                    <div style="overflow-x: auto;">
                        <table id="transactions-table">
                            <thead>
                                <tr>
                                    <th>Fecha / Hora</th>
                                    <th>Método</th>
                                    <th>Endpoint</th>
                                    <th>Duración</th>
                                    <th>Memoria Peak</th>
                                    <th>Usuario</th>
                                    <th>Empresa</th>
                                    <th>IP</th>
                                    <th>Detalles / Hint</th>
                                </tr>
                            </thead>
                            <tbody id="transactions-table-body">
                                <tr>
                                    <td colspan="9" style="text-align: center; color: var(--text-muted);">Cargando transacciones...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================== -->
        <!-- TAB 3: ACTIVE USERS -->
        <!-- ============================================== -->
        <div id="tab-users" class="tab-content">
            
            <div class="panel" style="margin-bottom: 24px;">
                <div class="panel-body" style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h2 style="font-family: var(--font-title); font-size: 20px; font-weight: 700; margin-bottom: 4px;">Sesiones Activas (Últimos 5 Minutos)</h2>
                        <p style="color: var(--text-secondary); font-size: 13px;">Usuarios autenticados a través de JWT que han interactuado con la API recientemente.</p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 32px; font-weight: 700; color: var(--emerald-color);" id="active-users-count">0</span>
                        <span style="color: var(--text-muted); font-size: 16px;"> / <span id="total-users-count">0</span> registrados</span>
                    </div>
                </div>
            </div>

            <div id="users-grid" class="users-list">
                <!-- Se poblará dinámicamente con las tarjetas de usuario -->
            </div>

        </div>

        <!-- ============================================== -->
        <!-- TAB 4: DATABASE STATISTICS -->
        <!-- ============================================== -->
        <div id="tab-database" class="tab-content">
            
            <!-- DB KPI Overview -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon kpi-indigo">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Motor DB</span>
                        <span id="db-kpi-driver" class="kpi-value">--</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-cyan">
                        <i class="fa-solid fa-weight-hanging"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Tamaño Total DB</span>
                        <span id="db-kpi-size" class="kpi-value">--</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-emerald">
                        <i class="fa-solid fa-percent"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Cache Hit Ratio</span>
                        <span id="db-kpi-cache" class="kpi-value">--%</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon kpi-amber">
                        <i class="fa-solid fa-plug"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Conexiones Activas / Totales</span>
                        <span id="db-kpi-conns" class="kpi-value">-- / --</span>
                    </div>
                </div>
            </div>

            <!-- Table Sizes Panel -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title"><i class="fa-solid fa-table-cells"></i> Top 10 Tablas por Tamaño Total (Datos + Índices)</span>
                </div>
                <div class="panel-body">
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nombre de Tabla</th>
                                    <th>Tamaño Total</th>
                                    <th>Tamaño Solo Datos</th>
                                    <th>Tamaño Índices</th>
                                    <th>Tuplas Muertas (Bloat)</th>
                                    <th>Recomendación</th>
                                </tr>
                            </thead>
                            <tbody id="db-tables-body">
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted);">Cargando estadísticas de base de datos...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================== -->
        <!-- TAB 5: LOG VIEWER -->
        <!-- ============================================== -->
        <div id="tab-logs" class="tab-content">
            
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title"><i class="fa-solid fa-terminal"></i> Registro de Actividad (logs/app.log)</span>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="log-filter-query" class="search-input" placeholder="Buscar texto en logs..." onkeyup="filterLogLines()" style="padding: 6px 12px; width: 250px;">
                        <select id="log-lines-count" onchange="loadLogs()" style="padding: 6px 12px;">
                            <option value="50">Últimas 50 líneas</option>
                            <option value="100" selected>Últimas 100 líneas</option>
                            <option value="200">Últimas 200 líneas</option>
                            <option value="500">Últimas 500 líneas</option>
                        </select>
                    </div>
                </div>
                <div class="panel-body">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; color: var(--text-secondary);">
                        <span id="log-file-size">Tamaño de archivo: --</span>
                        <span>Se colorea automáticamente según la severidad (`[SLOW]`, `[ERROR]`, etc.).</span>
                    </div>

                    <div id="log-container" class="log-viewer-box">
                        <div style="color: var(--text-muted);">Cargando logs del sistema...</div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================== -->
        <!-- TAB 6: PERFORMANCE REPORTS -->
        <!-- ============================================== -->
        <div id="tab-reports" class="tab-content">
            <div class="reports-layout">
                <!-- Sidebar: Report files list -->
                <div class="reports-sidebar">
                    <button class="btn btn-primary" onclick="generateNewReportNow()" style="width: 100%;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generar Reporte Ahora
                    </button>
                    
                    <div style="font-size: 11px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; margin-top: 8px;">Historial de Informes</div>
                    <div id="reports-list-container" class="report-list-container">
                        <!-- Cargar dinámicamente los reportes -->
                    </div>
                </div>
                
                <!-- Main panel: Report detail view -->
                <div id="report-main-panel" class="report-main-panel">
                    <div style="text-align: center; margin: auto; color: var(--text-muted);">
                        <i class="fa-solid fa-file-invoice" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                        Seleccione un informe del listado de la izquierda para ver su desglose de rendimiento.
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- DETALLES MODAL -->
    <div id="details-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="details-modal-title" class="modal-title">Detalles de la Transacción</span>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="details-modal-body">
                <!-- Contenido dinámico -->
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        // Configuración Global de la Aplicación
        const API_URL = 'api.php';
        let currentTabId = 'tab-dashboard';
        let refreshTimer = null;
        let countdownTimer = null;
        let nextRefreshTime = 0;
        let refreshDuration = 10000; // 10s por defecto

        // Instancias de Gráficos de Chart.js
        let chartEndpoints = null;
        let chartMemory = null;

        // Caché de datos para filtros rápidos locales
        let rawTransactionsData = [];
        let rawLogLines = [];

        // Inicialización
        window.addEventListener('load', () => {
            refreshData();
            
            fetch(`${API_URL}?action=system_stats`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('sidebar-server-info').innerText = `${data.os} | PHP ${data.php_version}`;
                        document.getElementById('kpi-disk-used').innerText = `${data.disk.used_percent}% (${data.disk.used_pretty})`;
                    }
                }).catch(err => console.error(err));

            setupAutoRefresh();
        });

        // Cambiar entre pestañas
        function switchTab(tabId, el) {
            currentTabId = tabId;
            
            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            el.classList.add('active');

            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');

            const text = el.innerText.trim();
            document.getElementById('page-title').innerText = text;

            if (tabId === 'tab-logs') {
                loadLogs();
            } else if (tabId === 'tab-database') {
                loadDbStats();
            } else if (tabId === 'tab-reports') {
                loadReportsList();
            }
        }

        // Configuración del Auto-Refresco
        function setupAutoRefresh() {
            const intervalVal = document.getElementById('refresh-interval').value;
            
            if (refreshTimer) clearInterval(refreshTimer);
            if (countdownTimer) clearInterval(countdownTimer);
            
            const progressBar = document.getElementById('refresh-progress');
            progressBar.style.width = '0%';

            if (intervalVal === 'manual') {
                return;
            }

            refreshDuration = parseInt(intervalVal);
            nextRefreshTime = Date.now() + refreshDuration;
            
            countdownTimer = setInterval(() => {
                const now = Date.now();
                const remaining = nextRefreshTime - now;
                const percent = Math.max(0, Math.min(100, 100 - (remaining / refreshDuration) * 100));
                progressBar.style.width = `${percent}%`;
            }, 100);

            refreshTimer = setInterval(() => {
                refreshData();
                nextRefreshTime = Date.now() + refreshDuration;
            }, refreshDuration);
        }

        // Función Principal para Refrescar Datos
        function refreshData() {
            const statusBadge = document.getElementById('connection-status');
            
            Promise.all([
                fetch(`${API_URL}?action=metrics`).then(r => r.json()),
                fetch(`${API_URL}?action=active_users`).then(r => r.json()),
                fetch(`${API_URL}?action=slow_requests_detail`).then(r => r.json())
            ])
            .then(([metricsData, usersData, slowReqsData]) => {
                statusBadge.className = 'status-badge';
                statusBadge.querySelector('.label').innerText = 'CONECTADO';
                
                if (usersData.success) {
                    document.getElementById('kpi-active-users').innerText = usersData.active_count;
                    document.getElementById('active-users-count').innerText = usersData.active_count;
                    document.getElementById('total-users-count').innerText = usersData.total_registered;
                    renderUsersList(usersData.users);
                }

                if (metricsData.success) {
                    if (metricsData.has_metrics) {
                        document.getElementById('kpi-avg-latency').innerText = `${metricsData.summary.avg_duration_24h_ms} ms`;
                        document.getElementById('kpi-errors-24h').innerText = metricsData.summary.errors_24h;
                        
                        document.getElementById('dashboard-total-requests').innerText = metricsData.summary.total_requests_logged;
                        
                        let successCount = 0;
                        let clientErrCount = 0;
                        let serverErrCount = 0;
                        
                        metricsData.status_distribution.forEach(item => {
                            const code = parseInt(item.status_code);
                            if (code >= 200 && code < 400) successCount += parseInt(item.count);
                            else if (code >= 400 && code < 500) clientErrCount += parseInt(item.count);
                            else if (code >= 500) serverErrCount += parseInt(item.count);
                        });
                        
                        document.getElementById('dashboard-success-requests').innerText = successCount;
                        document.getElementById('dashboard-warn-requests').innerText = clientErrCount;
                        document.getElementById('dashboard-error-requests').innerText = serverErrCount;

                        renderCharts(metricsData.slowest_endpoints);
                    }
                }

                if (slowReqsData.success) {
                    rawTransactionsData = slowReqsData.metrics;
                    renderTransactionsTable(slowReqsData.metrics);
                }

                if (currentTabId === 'tab-logs') {
                    loadLogs();
                } else if (currentTabId === 'tab-database') {
                    loadDbStats();
                } else if (currentTabId === 'tab-reports') {
                    loadReportsList(true); // silent reload
                }
            })
            .catch(err => {
                console.error(err);
                statusBadge.className = 'status-badge offline';
                statusBadge.querySelector('.label').innerText = 'DESCONECTADO';
            });
        }

        // Renderizar gráficos de Chart.js
        function renderCharts(endpoints) {
            const labels = endpoints.map(e => `${e.metodo} ${cleanPattern(e.endpoint_pattern)}`);
            const latencies = endpoints.map(e => parseInt(e.avg_duracion));
            const memories = endpoints.map(e => parseInt(e.avg_memoria));

            const chartOptions = {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(255,255,255,0.08)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#94a3b8', font: { family: 'Inter', size: 10 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#cbd5e1', font: { family: 'Inter', size: 10 } }
                    }
                }
            };

            if (chartEndpoints) {
                chartEndpoints.data.labels = labels;
                chartEndpoints.data.datasets[0].data = latencies;
                chartEndpoints.update();
            } else {
                const ctx = document.getElementById('chart-slowest-endpoints').getContext('2d');
                chartEndpoints = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: latencies,
                            backgroundColor: 'rgba(99, 102, 241, 0.7)',
                            borderColor: 'rgb(99, 102, 241)',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: chartOptions
                });
            }

            if (chartMemory) {
                chartMemory.data.labels = labels;
                chartMemory.data.datasets[0].data = memories;
                chartMemory.update();
            } else {
                const ctx = document.getElementById('chart-memory-usage').getContext('2d');
                chartMemory = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: memories,
                            backgroundColor: 'rgba(6, 182, 212, 0.7)',
                            borderColor: 'rgb(6, 182, 212)',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: chartOptions
                });
            }
        }

        function cleanPattern(pattern) {
            if (!pattern) return '';
            return pattern.replace('/WMS_FENIX/public/api', '').replace('/WMS_PROORIENTE/public/api', '');
        }

        // Renderizar tabla de transacciones lentas
        function renderTransactionsTable(data) {
            const tbody = document.getElementById('transactions-table-body');
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-muted);">No se encontraron peticiones lentas registradas.</td></tr>';
                return;
            }

            data.forEach(item => {
                const tr = document.createElement('tr');
                
                let latencyClass = 'latency-low';
                const seconds = (item.duracion_ms / 1000).toFixed(1);
                if (item.duracion_ms >= 10000) {
                    latencyClass = 'latency-high';
                } else if (item.duracion_ms >= 3000) {
                    latencyClass = 'latency-medium';
                }

                const mb = (item.memoria_kb / 1024).toFixed(1);
                const methodBadge = `<span class="method-badge method-${item.metodo.toLowerCase()}">${item.metodo}</span>`;

                let statusClass = 'status-success';
                if (item.status_code >= 500) statusClass = 'status-server-error';
                else if (item.status_code >= 400) statusClass = 'status-client-error';
                const statusBadge = `<span class="status-table ${statusClass}">${item.status_code}</span>`;

                const detailButton = item.slow_query_hint 
                    ? `<button class="btn btn-sm" onclick="showHintModal('${escapeHtml(item.slow_query_hint)}')"><i class="fa-solid fa-code"></i> Ver Hint</button>`
                    : `<span style="color: var(--text-muted); font-size: 11px;">Ninguno</span>`;

                tr.innerHTML = `
                    <td>${item.created_at}</td>
                    <td>${methodBadge}</td>
                    <td style="font-weight: 500; font-family: var(--font-mono); font-size: 12px;" title="${item.endpoint}">${cleanPattern(item.endpoint)}</td>
                    <td class="latency-colored ${latencyClass}">${seconds} s (${item.duracion_ms}ms)</td>
                    <td style="font-family: var(--font-mono);">${mb} MB</td>
                    <td>${item.usuario_nombre || '<span style="color: var(--text-muted);">Anónimo</span>'}</td>
                    <td>${item.empresa_nombre || '<span style="color: var(--text-muted);">-</span>'}</td>
                    <td style="color: var(--text-muted); font-family: var(--font-mono);">${item.ip || '-'}</td>
                    <td>${detailButton}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function filterTransactionsTable() {
            const endpointQuery = document.getElementById('tx-filter-endpoint').value.toLowerCase();
            const methodFilter = document.getElementById('tx-filter-method').value;
            const latencyFilter = document.getElementById('tx-filter-latency').value;

            const filtered = rawTransactionsData.filter(item => {
                const matchEndpoint = item.endpoint.toLowerCase().includes(endpointQuery) || 
                                      (item.endpoint_pattern && item.endpoint_pattern.toLowerCase().includes(endpointQuery));
                const matchMethod = methodFilter === "" || item.metodo === methodFilter;
                
                let matchLatency = true;
                if (latencyFilter === 'low') {
                    matchLatency = item.duracion_ms < 3000;
                } else if (latencyFilter === 'medium') {
                    matchLatency = item.duracion_ms >= 3000 && item.duracion_ms < 10000;
                } else if (latencyFilter === 'high') {
                    matchLatency = item.duracion_ms >= 10000;
                }

                return matchEndpoint && matchMethod && matchLatency;
            });

            renderTransactionsTable(filtered);
        }

        // Renderizar lista de usuarios activos
        function renderUsersList(users) {
            const grid = document.getElementById('users-grid');
            grid.innerHTML = '';

            if (users.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--text-muted); background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color);"><i class="fa-solid fa-users-slash" style="font-size: 32px; margin-bottom: 12px; display: block;"></i> No hay usuarios conectados en los últimos 5 minutos.</div>';
                return;
            }

            users.forEach(user => {
                const card = document.createElement('div');
                card.className = 'user-card';
                const initials = user.nombre.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase();
                const timeDiff = calculateRelativeTime(user.ultima_actividad);

                card.innerHTML = `
                    <div class="user-avatar">
                        ${initials}
                        <div class="online-indicator"></div>
                    </div>
                    <div class="user-details">
                        <div class="user-name">${user.nombre}</div>
                        <div class="user-role">${user.rol}</div>
                        <div class="user-meta">
                            <span><i class="fa-solid fa-building"></i> ${user.empresa_nombre || 'Sin Empresa'}</span>
                            <span><i class="fa-solid fa-location-dot"></i> ${user.sucursal_nombre || 'Sin Sucursal'}</span>
                            <span style="color: var(--emerald-color); font-weight: 500;"><i class="fa-solid fa-clock-rotate-left"></i> Activo ${timeDiff}</span>
                        </div>
                    </div>
                `;
                grid.appendChild(card);
            });
        }

        // Cargar estadísticas de Base de Datos
        function loadDbStats() {
            fetch(`${API_URL}?action=db_stats`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('db-kpi-driver').innerText = data.driver.toUpperCase();
                        document.getElementById('db-kpi-size').innerText = data.db_size;
                        document.getElementById('db-kpi-cache').innerText = `${data.cache_hit_ratio}%`;
                        document.getElementById('db-kpi-conns').innerText = `${data.connections.active} / ${data.connections.total}`;
                        
                        document.getElementById('kpi-db-conns').innerText = data.connections.active;

                        const tbody = document.getElementById('db-tables-body');
                        tbody.innerHTML = '';

                        data.table_sizes.forEach(table => {
                            const tr = document.createElement('tr');
                            let rec = '<span style="color: var(--emerald-color); font-weight: 500;">Saludable</span>';
                            if (parseInt(table.dead_tuples) > 500) {
                                rec = '<span style="color: var(--rose-color); font-weight: 600;">Requiere VACUUM</span>';
                            } else if (parseInt(table.dead_tuples) > 100) {
                                rec = '<span style="color: var(--amber-color); font-weight: 500;">Monitorear</span>';
                            }

                            tr.innerHTML = `
                                <td style="font-family: var(--font-mono); font-weight: 500;">${table.table_name}</td>
                                <td style="font-weight: 600;">${table.total_size}</td>
                                <td style="color: var(--text-secondary);">${table.table_size}</td>
                                <td style="color: var(--text-secondary);">${table.index_size}</td>
                                <td style="font-family: var(--font-mono);">${table.dead_tuples}</td>
                                <td>${rec}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }
                }).catch(err => console.error(err));
        }

        // Cargar Logs del Sistema
        function loadLogs() {
            const count = document.getElementById('log-lines-count').value;
            fetch(`${API_URL}?action=logs&lines=${count}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('log-file-size').innerText = `Tamaño del Archivo: ${data.log_size}`;
                        rawLogLines = data.content;
                        renderLogLines(data.content);
                    }
                }).catch(err => console.error(err));
        }

        // Renderizar líneas de logs coloreadas
        function renderLogLines(lines) {
            const container = document.getElementById('log-container');
            container.innerHTML = '';

            if (lines.length === 0) {
                container.innerHTML = '<div style="color: var(--text-muted);">No hay registros en el archivo de log.</div>';
                return;
            }

            lines.forEach(line => {
                if (!line.trim()) return;

                const div = document.createElement('div');
                div.className = 'log-line';

                if (line.includes('[SLOW]')) {
                    div.classList.add('log-slow');
                } else if (line.includes('[ERROR]') || line.includes('[FATAL]') || line.includes('[FATAL_SHUTDOWN]')) {
                    div.classList.add('log-error');
                } else if (line.includes('[WARN]')) {
                    div.classList.add('log-warn');
                } else if (line.includes('[JWT]')) {
                    div.classList.add('log-jwt');
                } else if (line.includes('[INFO]')) {
                    div.classList.add('log-info');
                }

                div.innerText = line;
                container.appendChild(div);
            });
        }

        function filterLogLines() {
            const q = document.getElementById('log-filter-query').value.toLowerCase();
            if (!q) {
                renderLogLines(rawLogLines);
                return;
            }
            const filtered = rawLogLines.filter(line => line.toLowerCase().includes(q));
            renderLogLines(filtered);
        }

        // Purgar Métricas de Rendimiento
        function confirmClearMetrics() {
            if (confirm('¿Está seguro de que desea eliminar todo el historial de métricas de rendimiento?\nEsta acción no se puede deshacer.')) {
                if (confirm('Por favor confirme una segunda vez para proceder con la purga de base de datos.')) {
                    fetch(`${API_URL}?action=clear_metrics&confirm=true`, {
                        method: 'POST'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            refreshData();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(err => alert('Error al conectar con la API de purga.'));
                }
            }
        }

        // ════════════════════════════════════════════════════════════════════════
        // REPORTES SCRIPTING
        // ════════════════════════════════════════════════════════════════════════

        let activeReportFilename = '';

        function loadReportsList(silent = false) {
            const container = document.getElementById('reports-list-container');
            if (!silent && container.children.length === 0) {
                container.innerHTML = '<div class="loader-container"><div class="spinner"></div></div>';
            }
            
            fetch(`${API_URL}?action=reports_list`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        container.innerHTML = '';
                        if (data.reports.length === 0) {
                            container.innerHTML = '<div style="text-align: center; color: var(--text-muted); font-size: 13px; padding: 20px;">No se encontraron reportes. Haz clic en "Generar Reporte Ahora".</div>';
                            return;
                        }
                        
                        data.reports.forEach(rep => {
                            const card = document.createElement('div');
                            card.className = `report-item-card ${activeReportFilename === rep.filename ? 'active' : ''}`;
                            card.onclick = () => selectReport(rep.filename, card);
                            
                            card.innerHTML = `
                                <div class="report-item-header">
                                    <span class="report-item-date">${rep.date}</span>
                                    <span class="score-circle score-${rep.score}">${rep.score}</span>
                                </div>
                                <div class="report-item-summary">
                                    <span>Peticiones: <strong>${rep.total_requests}</strong></span>
                                    <span>Errores: <strong style="color:${rep.errors_count > 0 ? 'var(--rose-color)' : 'inherit'}">${rep.errors_count}</strong></span>
                                </div>
                            `;
                            container.appendChild(card);
                        });
                        
                        // Seleccionar automáticamente el primero si no hay ninguno activo
                        if (activeReportFilename === '' && data.reports.length > 0) {
                            selectReport(data.reports[0].filename, container.children[0]);
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = '<div style="color: var(--rose-color); font-size: 13px; text-align: center;">Error al cargar reportes.</div>';
                });
        }

        function selectReport(filename, cardEl) {
            activeReportFilename = filename;
            
            // Highlight card
            document.querySelectorAll('.report-item-card').forEach(c => c.classList.remove('active'));
            if (cardEl) cardEl.classList.add('active');
            
            const mainPanel = document.getElementById('report-main-panel');
            mainPanel.innerHTML = '<div class="loader-container"><div class="spinner"></div></div>';
            
            fetch(`${API_URL}?action=get_report&filename=${filename}`)
                .then(r => r.json())
                .then(report => {
                    // Generar HTML del informe detallado
                    let statusColor = 'var(--emerald-color)';
                    if (report.overall_status === 'Crítico') statusColor = 'var(--rose-color)';
                    else if (report.overall_status === 'Advertencia') statusColor = 'var(--amber-color)';
                    else if (report.overall_status === 'Optimizable') statusColor = 'var(--cyan-color)';
                    
                    let anomaliesHtml = '';
                    if (report.anomalies.length === 0) {
                        anomaliesHtml = '<div style="color: var(--emerald-color); font-size: 13px; background: rgba(16,185,129,0.05); padding: 16px; border-radius: 8px; border: 1px dashed rgba(16,185,129,0.3);"><i class="fa-solid fa-circle-check"></i> No se detectaron anomalías significativas en este periodo. El sistema opera dentro de los parámetros normales.</div>';
                    } else {
                        report.anomalies.forEach(anom => {
                            anomaliesHtml += `
                                <div class="anomaly-item-card sev-${anom.severity}">
                                    <div class="anomaly-item-header">
                                        <span><i class="fa-solid fa-triangle-exclamation"></i> ${anom.type}</span>
                                        <span style="font-size: 11px; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.05);">${anom.severity}</span>
                                    </div>
                                    <div class="anomaly-desc">${anom.description}</div>
                                    <div class="anomaly-sol">
                                        <strong>Recomendación:</strong> ${anom.solution}
                                    </div>
                                </div>
                            `;
                        });
                    }
                    
                    let recsHtml = '';
                    if (report.recommendations.length === 0) {
                        recsHtml = '<div class="rec-item"><i class="fa-solid fa-check"></i> Mantener la configuración actual.</div>';
                    } else {
                        report.recommendations.forEach(rec => {
                            recsHtml += `
                                <div class="rec-item">
                                    <i class="fa-solid fa-circle-arrow-right"></i>
                                    <span>${rec}</span>
                                </div>
                            `;
                        });
                    }
                    
                    let endpointsHtml = '';
                    if (report.slowest_endpoints_2h.length === 0) {
                        endpointsHtml = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Sin llamados lentos en el periodo.</td></tr>';
                    } else {
                        report.slowest_endpoints_2h.forEach(ep => {
                            endpointsHtml += `
                                <tr>
                                    <td><span class="method-badge method-${ep.metodo.toLowerCase()}">${ep.metodo}</span></td>
                                    <td style="font-family: var(--font-mono); font-size:12px;">${cleanPattern(ep.endpoint_pattern)}</td>
                                    <td><strong>${(ep.avg_duracion/1000).toFixed(1)} s</strong> (${ep.avg_duracion}ms)</td>
                                    <td style="color:var(--text-secondary);">${(ep.avg_memoria/1024).toFixed(1)} MB</td>
                                </tr>
                            `;
                        });
                    }

                    mainPanel.innerHTML = `
                        <div class="report-header-section">
                            <div class="report-score-block">
                                <div class="report-score-badge score-${report.score}">${report.score}</div>
                                <div>
                                    <div class="report-status-text" style="color: ${statusColor}">${report.overall_status}</div>
                                    <div class="report-period-text">
                                        Periodo: <strong>${report.period.start}</strong> a <strong>${report.period.end}</strong>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 12px; color: var(--text-muted);">
                                Generado el: ${report.timestamp}
                            </div>
                        </div>
                        
                        <!-- KPIs -->
                        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 24px;">
                            <div class="kpi-card" style="padding: 16px;">
                                <div class="kpi-info">
                                    <span class="kpi-label" style="font-size:10px;">Peticiones (2h)</span>
                                    <span class="kpi-value" style="font-size:20px;">${report.metrics.total_requests_2h}</span>
                                </div>
                            </div>
                            <div class="kpi-card" style="padding: 16px;">
                                <div class="kpi-info">
                                    <span class="kpi-label" style="font-size:10px;">Latencia Media</span>
                                    <span class="kpi-value" style="font-size:20px;">${report.metrics.avg_latency_2h} ms</span>
                                </div>
                            </div>
                            <div class="kpi-card" style="padding: 16px;">
                                <div class="kpi-info">
                                    <span class="kpi-label" style="font-size:10px;">Errores 5xx</span>
                                    <span class="kpi-value" style="font-size:20px; color:${report.metrics.errors_2h > 0 ? 'var(--rose-color)' : 'inherit'};">${report.metrics.errors_2h}</span>
                                </div>
                            </div>
                            <div class="kpi-card" style="padding: 16px;">
                                <div class="kpi-info">
                                    <span class="kpi-label" style="font-size:10px;">Peticiones Lentas</span>
                                    <span class="kpi-value" style="font-size:20px;">${report.metrics.slow_requests_2h}</span>
                                </div>
                            </div>
                            <div class="kpi-card" style="padding: 16px;">
                                <div class="kpi-info">
                                    <span class="kpi-label" style="font-size:10px;">Conexiones Activas DB</span>
                                    <span class="kpi-value" style="font-size:20px;">${report.metrics.db_active_connections}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-row" style="margin-bottom: 24px;">
                            <!-- Panel Anomalias -->
                            <div class="panel" style="margin-bottom:0;">
                                <div class="panel-header" style="margin-bottom:12px; padding-bottom:6px;">
                                    <span class="panel-title" style="font-size:14px;"><i class="fa-solid fa-bug"></i> Análisis de Errores e Incidencias</span>
                                </div>
                                <div class="anomaly-box">
                                    ${anomaliesHtml}
                                </div>
                            </div>
                            
                            <!-- Panel Recomendaciones -->
                            <div class="panel" style="margin-bottom:0;">
                                <div class="panel-header" style="margin-bottom:12px; padding-bottom:6px;">
                                    <span class="panel-title" style="font-size:14px;"><i class="fa-solid fa-clipboard-list"></i> Recomendaciones del Sistema</span>
                                </div>
                                <div class="recs-list">
                                    ${recsHtml}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Slowest Endpoints Table -->
                        <div class="panel">
                            <div class="panel-header" style="margin-bottom:12px; padding-bottom:6px;">
                                <span class="panel-title" style="font-size:14px;"><i class="fa-solid fa-stopwatch-20"></i> Endpoints más lentos del periodo (2h)</span>
                            </div>
                            <div style="overflow-x: auto;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Método</th>
                                            <th>Endpoint</th>
                                            <th>Promedio Latencia</th>
                                            <th>Promedio Memoria</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${endpointsHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                })
                .catch(err => {
                    console.error(err);
                    mainPanel.innerHTML = '<div style="color: var(--rose-color); font-size: 13px; text-align: center; margin: auto;">Error al cargar el detalle del informe.</div>';
                });
        }

        function generateNewReportNow() {
            const btn = document.querySelector('.reports-sidebar button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generando...';
            
            fetch(`${API_URL}?action=generate_report`)
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    if (data.success) {
                        activeReportFilename = ''; // reset to select the newest
                        loadReportsList();
                    } else {
                        alert('Error al generar: ' + data.message);
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert('Error al enviar solicitud de generación.');
                });
        }

        // Helpers de cálculo y formateo
        function calculateRelativeTime(dateTimeStr) {
            if (!dateTimeStr) return 'nunca';
            const activityTime = new Date(dateTimeStr.replace(' ', 'T'));
            const now = new Date();
            const diffSeconds = Math.floor((now - activityTime) / 1000);
            
            if (diffSeconds < 60) {
                return 'hace unos instantes';
            }
            const diffMinutes = Math.floor(diffSeconds / 60);
            if (diffMinutes < 60) {
                return `hace ${diffMinutes} min`;
            }
            const diffHours = Math.floor(diffMinutes / 60);
            return `hace ${diffHours} hr`;
        }

        function showHintModal(hintText) {
            document.getElementById('details-modal-body').innerText = hintText;
            document.getElementById('details-modal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('details-modal').classList.remove('open');
        }

        function escapeHtml(text) {
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
