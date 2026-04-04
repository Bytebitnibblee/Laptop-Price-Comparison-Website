<style>
    .admin-nav {
        background: linear-gradient(135deg, #071525 0%, #0c2340 100%);
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
        background: #ffffff;
        padding: 2rem;
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(12, 35, 64, 0.07), 0 1px 3px rgba(10, 10, 10, 0.04);
        text-align: center;
        border: 1px solid rgba(12, 35, 64, 0.1);
    }
    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        color: #0c2340;
    }
    .stat-label {
        color: #5c6b80;
        margin-top: 0.5rem;
        font-size: 0.95rem;
    }
    .data-table {
        background: #ffffff;
        padding: 2rem;
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(12, 35, 64, 0.07), 0 1px 3px rgba(10, 10, 10, 0.04);
        margin-bottom: 2rem;
        border: 1px solid rgba(12, 35, 64, 0.1);
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid rgba(12, 35, 64, 0.1);
    }
    th {
        background: #eef1f6;
        font-weight: 600;
        color: #0a0a0a;
    }
    td {
        color: #3d4f66;
    }
    .laptop-thumb {
        width: 60px;
        height: 40px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid rgba(12, 35, 64, 0.12);
    }
    .scraper-panel {
        background: #ffffff;
        padding: 2rem;
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(12, 35, 64, 0.07), 0 1px 3px rgba(10, 10, 10, 0.04);
        margin-bottom: 2rem;
        border: 1px solid rgba(12, 35, 64, 0.1);
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
        background: #eef1f6;
        padding: 1.5rem;
        border-radius: 12px;
        text-align: center;
        border: 1px solid rgba(12, 35, 64, 0.1);
    }
    .stat-item strong {
        color: #0c2340;
    }
    .admin-panel {
        background: #ffffff;
        padding: 2rem;
        border-radius: 16px;
        max-width: 800px;
        border: 1px solid rgba(12, 35, 64, 0.1);
        box-shadow: 0 4px 24px rgba(12, 35, 64, 0.07), 0 1px 3px rgba(10, 10, 10, 0.04);
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
