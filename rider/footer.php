    <!-- Fixed Bottom Navigation (Section 24) -->
    <nav class="rider-bottom-nav">
        <a href="index.php" class="rider-nav-item <?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="active_delivery.php" class="rider-nav-item <?php echo in_array($current_page, ['active_delivery.php', 'history.php'], true) ? 'active' : ''; ?>">
            <i class="fas fa-route"></i>
            <span>Delivery</span>
        </a>
        <a href="earnings.php" class="rider-nav-item <?php echo $current_page === 'earnings.php' ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i>
            <span>Earnings</span>
        </a>
        <a href="wallet.php" class="rider-nav-item <?php echo $current_page === 'wallet.php' ? 'active' : ''; ?>">
            <i class="fas fa-money-bill-wave"></i>
            <span>COD Cash</span>
        </a>
        <a href="notifications.php" class="rider-nav-item <?php echo $current_page === 'notifications.php' ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i>
            <span>Alerts</span>
            <?php if (!empty($unread_notifs) && $unread_notifs > 0): ?>
                <span class="rider-nav-badge"><?php echo $unread_notifs; ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="rider-nav-item <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-circle"></i>
            <span>Profile</span>
        </a>
    </nav>
</div><!-- /.rider-mobile-container -->

<!-- Core Scripts -->
<script src="../js/jquery-3.7.1.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Geolocation streaming helper for active riders
let riderGeoWatchId = null;
function broadcastRiderLocation(onCoordsUpdate) {
    if (!navigator.geolocation) return;
    if (riderGeoWatchId !== null) return;

    riderGeoWatchId = navigator.geolocation.watchPosition(
        (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            const acc = pos.coords.accuracy || 0;

            if (typeof onCoordsUpdate === 'function') {
                onCoordsUpdate(lat, lng, acc);
            }

            // Sync with backend API
            const formData = new FormData();
            formData.append('action', 'update_location');
            formData.append('latitude', lat);
            formData.append('longitude', lng);
            formData.append('accuracy', acc);

            fetch('api_rider.php', {
                method: 'POST',
                body: formData
            }).catch(e => console.debug('Rider GPS ping fail:', e));
        },
        (err) => {
            console.debug('Geolocation watch error:', err.message);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 2000 }
    );
}

// Auto-start GPS broadcast if online or busy
<?php if (!empty($rider) && in_array($rider['duty_status'], ['online', 'busy'], true)): ?>
document.addEventListener('DOMContentLoaded', () => {
    broadcastRiderLocation();
});
<?php endif; ?>
</script>
</body>
</html>
