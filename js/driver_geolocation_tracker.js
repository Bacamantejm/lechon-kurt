/**
 * Real-time Geolocation Tracking for Drivers
 * Integrates GPS tracking with WebSocket support for live updates
 */

class DriverGeolocationTracker {
    constructor() {
        const cfg = window.driverTrackingConfig || {};
        this.trackingActive = false;
        this.watchId = null;
        this.updateInterval = Number(cfg.updateInterval || 15000); // 15 seconds default
        this.lastUpdateTime = 0;
        this.apiUrl = cfg.apiUrl || this.resolveApiUrl();
    }

    resolveApiUrl() {
        const path = window.location.pathname || '';
        if (path.includes('/employee/')) {
            return '../api/update_driver_location.php';
        }
        return 'api/update_driver_location.php';
    }

    /**
     * Start tracking driver location
     */
    startTracking() {
        if (!navigator.geolocation) {
            console.error('Geolocation not supported');
            return false;
        }

        if (this.trackingActive) return true;

        this.trackingActive = true;

        // Get location immediately
        this.updateLocation();

        // Watch position for continuous updates
        this.watchId = navigator.geolocation.watchPosition(
            (position) => this.handlePositionUpdate(position),
            (error) => this.handlePositionError(error),
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 5000
            }
        );

        console.log('Driver geolocation tracking started');
        return true;
    }

    /**
     * Stop tracking
     */
    stopTracking() {
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.trackingActive = false;
            console.log('Driver geolocation tracking stopped');
        }
    }

    /**
     * Get current location
     */
    getCurrentLocation() {
        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 10000
            });
        });
    }

    /**
     * Handle position updates
     */
    handlePositionUpdate(position) {
        const currentTime = new Date().getTime();

        // Throttle updates to prevent excessive server calls
        if (currentTime - this.lastUpdateTime < this.updateInterval) {
            return;
        }

        this.sendLocationToServer(position);
        this.lastUpdateTime = currentTime;
    }

    /**
     * Handle position errors
     */
    handlePositionError(error) {
        const errorMessages = {
            1: 'Location permission denied',
            2: 'Location information unavailable',
            3: 'Location request timeout'
        };

        console.error('Geolocation error:', errorMessages[error.code] || 'Unknown error');
    }

    /**
     * Send location to server
     */
    async sendLocationToServer(position) {
        const { latitude, longitude, accuracy } = position.coords;

        try {
            const payload = new URLSearchParams();
            payload.set('latitude', latitude);
            payload.set('longitude', longitude);
            payload.set('accuracy', accuracy || 0);

            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: payload.toString()
            });

            const result = await response.json();

            if (result.success) {
                console.log('Location updated successfully');
            } else {
                console.error('Failed to update location:', result.message);
            }
        } catch (error) {
            console.error('Error sending location:', error);
        }
    }

    /**
     * Update location immediately
     */
    updateLocation() {
        this.getCurrentLocation()
            .then((position) => this.sendLocationToServer(position))
            .catch((error) => this.handlePositionError(error));
    }
}

// Initialize tracking when page loads
document.addEventListener('DOMContentLoaded', () => {
    const enabledByClass = document.body.classList.contains('driver-app');
    const enabledByData = document.body.dataset.driverTracking === 'enabled';
    const enabledByConfig = !!(window.driverTrackingConfig && window.driverTrackingConfig.enabled);

    if (enabledByClass || enabledByData || enabledByConfig) {
        window.geoTracker = new DriverGeolocationTracker();
        window.geoTracker.startTracking();

        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            if (window.geoTracker) {
                window.geoTracker.stopTracking();
            }
        });
    }
});
