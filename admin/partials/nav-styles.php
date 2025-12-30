<style>
    .admin-nav {
        background: #343a40;
        padding: 1rem 0;
        margin-bottom: 2rem;
    }
    .admin-nav ul {
        list-style: none;
        display: flex;
        gap: 2rem;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }
    .admin-nav a {
        color: white;
        text-decoration: none;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 3rem;
    }
    .stat-card {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        text-align: center;
    }
    .stat-value {
        font-size: 2.5rem;
        font-weight: bold;
        color: #667eea;
    }
    .stat-label {
        color: #666;
        margin-top: 0.5rem;
    }
    .data-table {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solidrgb(204, 183, 183);
    }
    th {
        background:rgb(140, 145, 149);
        font-weight: 600;
    }
    .laptop-thumb {
        width: 60px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
    }
    .scraper-panel {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
    }
    .output-box {
        background: #1e1e1e;
        color: #d4d4d4;
        padding: 1.5rem;
        border-radius: 5px;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        max-height: 400px;
        overflow-y: auto;
        white-space: pre-wrap;
        margin-top: 1rem;
    }
    .stat-box {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-top: 2rem;
    }
    .stat-item {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        text-align: center;
    }
</style>

