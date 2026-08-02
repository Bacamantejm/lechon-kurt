<?php
/**
 * ============================================================================
 * FORECASTING SYSTEM - ADMIN QUICK REFERENCE
 * ============================================================================
 * Add this to admin/index.php or create a new widget
 * 
 * Shows system status and quick links in admin dashboard
 */

function renderForecastingWidget() {
    $conn = new mysqli("localhost", "root", "", "lechon_db");
    if ($conn->connect_error) {
        return;
    }
    
    // Check if tables exist
    $tables_check = $conn->query("SHOW TABLES LIKE 'forecasts'");
    $system_active = $tables_check && $tables_check->num_rows > 0;
    
    if (!$system_active) {
        return;
    }
    
    // Get system health
    $health_query = $conn->query("
        SELECT 
            (SELECT COUNT(*) FROM forecasts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as forecasts_today,
            (SELECT COUNT(*) FROM decisions_recommendations WHERE status = 'pending') as pending_recommendations,
            (SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as orders_week
    ");
    
    $health = $health_query->fetch_assoc();
    
    ?>
    <div class="card mt-4" style="border-left: 4px solid #667eea;">
        <div class="card-header" style="background-color: #667eea; color: white;">
            <h5 class="mb-0">
                <i class="fas fa-brain"></i> Forecasting & Decision Support
            </h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4 text-center">
                    <div style="font-size: 2em; color: #667eea; font-weight: bold;">
                        <?php echo $health['pending_recommendations'] ?? 0; ?>
                    </div>
                    <small class="text-muted">Pending Recommendations</small>
                </div>
                <div class="col-md-4 text-center">
                    <div style="font-size: 2em; color: #667eea; font-weight: bold;">
                        <?php echo $health['forecasts_today'] ?? 0; ?>
                    </div>
                    <small class="text-muted">Forecasts Today</small>
                </div>
                <div class="col-md-4 text-center">
                    <div style="font-size: 2em; color: #667eea; font-weight: bold;">
                        <?php echo $health['orders_week'] ?? 0; ?>
                    </div>
                    <small class="text-muted">Orders (7 Days)</small>
                </div>
            </div>
            
            <div class="btn-group w-100" role="group">
                <a href="forecasting_dashboard.php" class="btn btn-sm btn-primary" 
                   style="background-color: #667eea; border-color: #667eea; flex: 1;">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
                <a href="forecasting_dashboard.php?view=recommendations" class="btn btn-sm btn-outline-primary" 
                   style="flex: 1;">
                    <i class="fas fa-lightbulb"></i> Recommendations
                </a>
                <a href="events.php" class="btn btn-sm btn-outline-primary" style="flex: 1;">
                    <i class="fas fa-calendar"></i> Events
                </a>
            </div>
        </div>
    </div>
    <?php
}
?>
