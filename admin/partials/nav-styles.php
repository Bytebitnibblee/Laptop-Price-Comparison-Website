<style>
    .admin-nav {
        background: linear-gradient(135deg, var(--navy-deep) 0%, var(--navy) 100%);
        padding: 1rem 0;
        margin-bottom: 2rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 4px 16px rgba(7, 21, 37, 0.25);
    }
    .admin-nav ul {
        list-style: none;
        display: flex;
        gap: 2rem;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
        flex-wrap: wrap;
    }
    .admin-nav a {
        color: #e2e8f0;
        text-decoration: none;
        font-weight: 500;
    }
    .admin-nav a:hover {
        color: #ffffff;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 3rem;
    }
    .stat-card {
        background: var(--surface);
        padding: 2rem;
        border-radius: 16px;
        box-shadow: var(--shadow-primary);
        text-align: center;
        border: 1px solid var(--card-border);
    }
    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--navy);
    }
    .stat-label {
        color: var(--text-muted);
        margin-top: 0.5rem;
        font-size: 0.95rem;
    }
    .data-table {
        background: var(--surface);
        padding: 2rem;
        border-radius: 16px;
        box-shadow: var(--shadow-primary);
        margin-bottom: 2rem;
        border: 1px solid var(--card-border);
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--card-border);
    }
    th {
        background: var(--surface-muted);
        font-weight: 600;
        color: var(--text-primary);
    }
    td {
        color: var(--text-secondary);
    }
    .laptop-thumb {
        width: 60px;
        height: 40px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--card-border);
    }
    .scraper-panel {
        background: var(--surface);
        padding: 2rem;
        border-radius: 16px;
        box-shadow: var(--shadow-primary);
        margin-bottom: 2rem;
        border: 1px solid var(--card-border);
    }
    .output-box {
        background: #0a0a0a;
        color: #e2e8f0;
        padding: 1.5rem;
        border-radius: 10px;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: 0.9rem;
        max-height: 400px;
        overflow-y: auto;
        white-space: pre-wrap;
        margin-top: 1rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .stat-box {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-top: 2rem;
    }
    .stat-item {
        background: var(--surface-muted);
        padding: 1.5rem;
        border-radius: 12px;
        text-align: center;
        border: 1px solid var(--card-border);
    }
    .stat-item strong {
        color: var(--navy);
    }
    .admin-panel {
        background: var(--surface);
        padding: 2rem;
        border-radius: 16px;
        max-width: 800px;
        border: 1px solid var(--card-border);
        box-shadow: var(--shadow-primary);
    }
    .admin-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
</style>
