<?php
require_once __DIR__ . '/module_common.php';

$selected_alert = trim((string)($_GET['alert'] ?? ''));
saRenderModuleHeader('Alerts', 'Security & Shop Alerts', $admin_info);
?>
<style>
    .sa-alerts-page { max-width:1180px; }
    .sa-alerts-toolbar { display:flex; justify-content:space-between; align-items:end; gap:16px; margin-bottom:18px; }
    .sa-alerts-toolbar h2 { margin:0; color:#101828; font-size:1.35rem; }
    .sa-alerts-list { display:grid; gap:10px; }
    .sa-alert-card { display:block; padding:17px; background:#fff; border:1px solid #eaecf0; border-radius:12px; color:#101828; text-decoration:none; box-shadow:0 1px 3px rgba(16,24,40,.04); }
    .sa-alert-card:hover, .sa-alert-card.selected { border-color:#b3261e; }
    .sa-alert-head { display:flex; justify-content:space-between; gap:12px; margin-bottom:7px; }
    .sa-alert-title { font-weight:700; }
    .sa-alert-copy { color:#475467; line-height:1.5; }
    .sa-alert-meta { color:#667085; font-size:.78rem; margin-top:8px; }
    .sa-alert-empty { padding:42px 20px; text-align:center; color:#667085; background:#fff; border:1px solid #eaecf0; border-radius:12px; }
    .sa-alert-filter { min-width:190px; }
    @media (max-width:576px) { .sa-alerts-toolbar { align-items:flex-start; flex-direction:column; } .sa-alert-filter { width:100%; } }
</style>
<main class="sa-alerts-page">
    <div class="sa-alerts-toolbar">
        <div>
            <h2>Security &amp; Shop Alerts</h2>
            <p class="module-subtext mb-0">Security events, complaints, warnings, and platform anomalies.</p>
        </div>
        <select class="form-select sa-alert-filter" id="saAlertsFilter" aria-label="Filter alerts">
            <option value="all">All alerts</option>
            <option value="security">Security &amp; logs</option>
            <option value="complaints">Complaints &amp; reports</option>
        </select>
    </div>
    <div class="sa-alerts-list" id="saAlertsList"><div class="sa-alert-empty">Loading alerts...</div></div>
</main>
<?php
saRenderModuleFooter('<script>
(function () {
    const selectedAlert = ' . json_encode($selected_alert) . ';
    const list = document.getElementById("saAlertsList");
    const filter = document.getElementById("saAlertsFilter");
    const escapeHtml = (value) => String(value ?? "").replace(/[&<>\'"]/g, (character) => ({"&":"&amp;","<":"&lt;",">":"&gt;","\'":"&#39;","\\"":"&quot;"}[character]));
    let alerts = [];
    function render() {
        const category = filter.value;
        const visible = alerts.filter((alert) => category === "all"
            || (category === "security" && ["security", "anomaly", "incident"].includes(alert.category))
            || (category === "complaints" && ["complaint", "warning"].includes(alert.category)));
        if (!visible.length) {
            list.innerHTML = "<div class=\"sa-alert-empty\"><i class=\"fas fa-check-circle fa-2x mb-3\"></i><div>No alerts found.</div></div>";
            return;
        }
        list.innerHTML = visible.map((alert) => {
            const id = String(alert.id || "");
            const selected = id === selectedAlert ? " selected" : "";
            const destination = alert.link || ("alerts.php?alert=" + encodeURIComponent(id));
            return "<a class=\"sa-alert-card" + selected + "\" href=\"" + escapeHtml(destination) + "\">"
                + "<div class=\"sa-alert-head\"><span class=\"sa-alert-title\"><i class=\"fas " + escapeHtml(alert.icon || "fa-triangle-exclamation") + " me-1\"></i>" + escapeHtml(alert.title || "Alert") + "</span>"
                + "<span class=\"badge bg-light text-dark\">" + escapeHtml(alert.severity || "info") + "</span></div>"
                + "<div class=\"sa-alert-copy\">" + escapeHtml(alert.message || "") + "</div>"
                + "<div class=\"sa-alert-meta\">" + escapeHtml(alert.category_label || alert.category || "Alert") + " &middot; " + escapeHtml(alert.time_ago || "") + "</div></a>";
        }).join("");
    }
    filter.addEventListener("change", render);
    fetch("get_alerts.php?action=get_alerts", { credentials: "same-origin" })
        .then((response) => { if (!response.ok) throw new Error("Unable to load alerts."); return response.json(); })
        .then((payload) => { alerts = Array.isArray(payload.alerts) ? payload.alerts : []; render(); })
        .catch(() => { list.innerHTML = "<div class=\"sa-alert-empty\">Alerts are unavailable right now.</div>"; });
})();
</script>');
?>
