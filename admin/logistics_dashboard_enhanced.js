class AdminLogisticsManager {
    constructor() {
        this.refreshInterval = 30000; // 30 seconds
        this.map = null;
        this.markers = {};
        this.infoWindow = null;
        this.initDashboard();
        this.setupEventListeners();
        this.startRealTimeUpdates();
    }
    
    initDashboard() {
        this.loadLogisticsStats();
        this.loadActiveDeliveries();
        this.loadDriversList();
        this.initMap();
    }
    
    setupEventListeners() {
        // Use event delegation for dynamic buttons
        const container = document.getElementById('activeDeliveriesContainer');
        if (!container) return;
        
        container.addEventListener('click', (e) => {
            if (e.target.closest('.btn-auto-assign')) {
                this.handleAutoAssign(e);
            } else if (e.target.closest('.btn-manual-assign')) {
                this.handleManualAssign(e);
            } else if (e.target.closest('.btn-view-tracking')) {
                this.viewTracking(e);
            } else if (e.target.closest('.btn-reassign')) {
                this.handleReassign(e);
            }
        });
        
        // Filter buttons - these are static
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.filterDeliveries(e));
        });

        const driversContainer = document.getElementById('driversListContainer');
        if (driversContainer) {
            driversContainer.addEventListener('click', e => {
                const detailsButton = e.target.closest('.btn-driver-details');
                if (detailsButton) {
                    const driverId = detailsButton.dataset.driverId;
                    // Assuming a global function `openDriverDetails` exists as implied by original code
                    if (typeof openDriverDetails === 'function') {
                        openDriverDetails(driverId);
                    } else {
                        console.warn(`Global function 'openDriverDetails' not found. Driver ID: ${driverId}`);
                        this.showMessage('Driver details functionality is not available.', 'info');
                    }
                }
            });
        }

        const confirmBtn = document.getElementById('confirmAssignBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => this.handleConfirmAssignment());
        }
    }
    
    handleConfirmAssignment() {
        const modalElement = document.getElementById('driverSelectionModal');
        const orderId = modalElement.dataset.orderId;
        const selectedDriver = modalElement.querySelector('input[name="driver"]:checked');

        if (!selectedDriver) {
            this.showMessage('Please select a driver first.', 'warning');
            return;
        }
        this.assignDriver(orderId, selectedDriver.value);
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    }
    
    async loadLogisticsStats() {
        try {
            const response = await fetch('api/get_logistics_stats.php');
            if (!response.ok) throw new Error('Failed to load stats');
            const data = await response.json();
            
            if (data.success && data.stats) {
                const stats = data.stats;
                const elems = {
                    'totalDeliveries': stats.total_deliveries || 0,
                    'activeDeliveries': stats.active_deliveries || 0,
                    'completedDeliveries': stats.completed_deliveries || 0,
                    'failedDeliveries': stats.failed_deliveries || 0,
                    'averageRating': (stats.average_rating || 0).toFixed(2),
                    'successRate': ((stats.success_rate || 0).toFixed(1) + '%')
                };
                
                Object.entries(elems).forEach(([id, value]) => {
                    const elem = document.getElementById(id);
                    if (elem) elem.textContent = value;
                });
            }
        } catch (error) {
            console.error('Error loading stats:', error.message);
        }
    }
    
    async loadActiveDeliveries() {
        try {
            const response = await fetch('api/get_active_deliveries.php');
            const deliveries = await response.json();
            
            const container = document.getElementById('activeDeliveriesContainer');
            container.innerHTML = '';
            
            if (deliveries.length === 0) {
                container.innerHTML = '<p class="text-center text-muted">No active deliveries</p>';
                return;
            }
            
            deliveries.forEach(delivery => {
                container.appendChild(this.createDeliveryCard(delivery));
            });
        } catch (error) {
            console.error('Error loading deliveries:', error);
        }
    }
    
    createDeliveryCard(delivery) {
        const card = document.createElement('div');
        card.className = 'delivery-card-compact';
        card.innerHTML = `
            <div class="delivery-card-header">
                <h5>#${delivery.order_number}</h5>
                <span class="badge bg-${this.getStatusColor(delivery.current_status)}">
                    ${this.formatStatus(delivery.current_status)}
                </span>
            </div>
            <div class="delivery-card-body">
                <p><strong>Customer:</strong> ${delivery.customer_name}</p>
                <p><strong>Driver:</strong> ${delivery.driver_name || 'Unassigned'}</p>
                <p><strong>Location:</strong> ${delivery.delivery_address.substring(0, 40)}...</p>
                <p><strong>Amount:</strong> ₱${parseFloat(delivery.total_amount).toFixed(2)}</p>
            </div>
            <div class="delivery-card-actions">
                <button class="btn btn-sm btn-info btn-view-tracking" data-order-id="${delivery.order_id}">
                    <i class="fas fa-map"></i> Track
                </button>
                ${!delivery.driver_id ? `
                    <button class="btn btn-sm btn-success btn-auto-assign" data-order-id="${delivery.order_id}">
                        <i class="fas fa-robot"></i> Auto Assign
                    </button>
                    <button class="btn btn-sm btn-warning btn-manual-assign" data-order-id="${delivery.order_id}">
                        <i class="fas fa-hand"></i> Manual
                    </button>
                ` : `
                    <button class="btn btn-sm btn-warning btn-reassign" data-order-id="${delivery.order_id}">
                        <i class="fas fa-refresh"></i> Reassign
                    </button>
                `}
            </div>
        `;
        return card;
    }
    
    async loadDriversList() {
        try {
            const response = await fetch('api/get_drivers_list.php');
            const drivers = await response.json();
            
            const container = document.getElementById('driversListContainer');
            container.innerHTML = '';
            
            drivers.forEach(driver => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${driver.first_name} ${driver.last_name}</td>
                    <td>
                        <span class="badge bg-${driver.is_available ? 'success' : 'danger'}">
                            ${driver.is_available ? 'Available' : 'Busy'}
                        </span>
                    </td>
                    <td>${driver.current_deliveries_count}/${driver.max_deliveries_per_day}</td>
                    <td>
                        <div class="rating">
                            ${'<i class="fas fa-star text-warning"></i>'.repeat(Math.floor(driver.avg_rating))}
                            <small>${driver.avg_rating.toFixed(2)}</small>
                        </div>
                    </td>
                    <td>${driver.success_rate.toFixed(1)}%</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary btn-driver-details" data-driver-id="${driver.id}">
                            Details
                        </button>
                    </td>
                `;
                container.appendChild(row);
            });
        } catch (error) {
            console.error('Error loading drivers:', error);
        }
    }
    
    async handleAutoAssign(e) { // e is the event object
        const orderId = e.target.closest('.btn-auto-assign').getAttribute('data-order-id');
        const confirmed = await this.confirmAction({
            title: 'Auto-assign driver?',
            text: 'The system will assign the best available driver for this delivery.',
            icon: 'question',
            confirmButtonText: 'Yes, auto-assign'
        });
        if (!confirmed) return;
        
        try {
            const response = await fetch('api/auto_assign_driver.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${orderId}`
            });
            
            const result = await response.json();
            if (result.success) {
                this.showMessage(`Driver ${result.driver_name} assigned successfully!`, 'success');
                this.loadActiveDeliveries();
            } else {
                this.showMessage(result.message ? ('Error: ' + result.message) : 'Unable to auto-assign the driver.', 'error');
            }
        } catch (error) {
            this.showMessage('Error assigning driver: ' + error.message, 'error');
        }
    }
    
    async handleManualAssign(e) { // e is the event object
        const orderId = e.target.closest('.btn-manual-assign').getAttribute('data-order-id');
        this.showManualAssignModal(orderId);
    }

    async showManualAssignModal(orderId) {
        try {
            const response = await fetch(`api/get_available_drivers.php?order_id=${orderId}`);
            const drivers = await response.json();
            
            this.showDriverSelectionModal(drivers, orderId);
        } catch (error) {
            this.showMessage('Error loading available drivers: ' + error.message, 'error');
        }
    }
    
    showDriverSelectionModal(drivers, orderId) {
        const modalElement = document.getElementById('driverSelectionModal');
        const container = document.getElementById('driverSelectionContainer');
        
        if (!modalElement || !container) {
            this.showMessage('Modal elements not found in page', 'error');
            return;
        }
        
        modalElement.dataset.orderId = orderId;
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        container.innerHTML = '';
        
        if (drivers.length === 0) {
            container.innerHTML = '<p class="text-center text-muted">No available drivers found for this delivery.</p>';
        } else {
            drivers.forEach(driver => {
                const option = document.createElement('div');
                option.className = 'driver-option';
                option.innerHTML = `
                    <input type="radio" name="driver" value="${driver.id}" id="driver_${driver.id}">
                    <label for="driver_${driver.id}">
                        <strong>${driver.first_name} ${driver.last_name}</strong>
                        <span class="rating">⭐ ${driver.avg_rating.toFixed(1)}</span>
                        <small>Distance: ${driver.distance_km.toFixed(1)}km | Deliveries: ${driver.current_deliveries_count}/${driver.max_deliveries_per_day}</small>
                    </label>
                `;
                container.appendChild(option);
            });
        }
        
        modal.show();
    }
    async assignDriver(orderId, driverId) {
        try {
            const response = await fetch('api/assign_driver_manual.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${orderId}&driver_id=${driverId}`
            });
            
            const result = await response.json();
            if (result.success) {
                this.showMessage('Driver assigned successfully!', 'success');
                this.loadActiveDeliveries();
            } else {
                this.showMessage(result.message ? ('Error: ' + result.message) : 'Unable to assign the selected driver.', 'error');
            }
        } catch (error) {
            this.showMessage('Error assigning driver: ' + error.message, 'error');
        }
    }
    
    viewTracking(e) {
        const orderId = e.target.closest('.btn-view-tracking').getAttribute('data-order-id');
        window.open(`tracking_map.php?order_id=${orderId}`, '_blank', 'width=1000,height=700');
    }
    
    async handleReassign(e) {
        const orderId = e.target.closest('.btn-reassign').getAttribute('data-order-id');
        const confirmed = await this.confirmAction({
            title: 'Reassign delivery?',
            text: 'This will reopen driver selection for the delivery.',
            icon: 'warning',
            confirmButtonText: 'Yes, reassign'
        });
        if (!confirmed) return;
        
        this.showManualAssignModal(orderId);
    }

    async confirmAction(options = {}) {
        if (window.swalConfirmAction) {
            return window.swalConfirmAction(options);
        }
        if (typeof Swal !== 'undefined') {
            const config = Object.assign({
                title: 'Confirm action?',
                text: '',
                icon: 'question',
                confirmButtonText: 'Confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33'
            }, options || {});
            const result = await Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonText: config.confirmButtonText,
                cancelButtonText: config.cancelButtonText,
                confirmButtonColor: config.confirmButtonColor,
                cancelButtonColor: config.cancelButtonColor
            });
            return !!(result && result.isConfirmed);
        }
        return false;
    }

    showMessage(message, type = 'info') {
        if (typeof Swal !== 'undefined') {
            const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
            Swal.fire({
                icon: iconMap[type] || 'info',
                title: type === 'success' ? 'Success' : (type === 'error' ? 'Error' : 'Notice'),
                text: message,
                confirmButtonColor: '#3085d6'
            });
            return;
        }
        console.log('[AdminLogisticsManager][' + type + ']', message);
    }
    
    filterDeliveries(e) {
        const status = e.target.getAttribute('data-status');
        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        e.target.classList.add('active');
        
        this.loadActiveDeliveries();
    }
    
    initMap() {
        // Initialize Google Map for real-time driver tracking
        const mapElement = document.getElementById('liveTrackingMap');
        if (!mapElement) {
            console.warn('Map element not found');
            return;
        }
        
        if (typeof google === 'undefined' || !google.maps) {
            console.warn('Google Maps API not loaded');
            return;
        }
        
        const mapOptions = {
            center: { lat: 14.3294, lng: 120.9367 }, // Cavite
            zoom: 12,
            mapTypeId: 'roadmap'
        };
        
        this.map = new google.maps.Map(mapElement, mapOptions);
        this.infoWindow = new google.maps.InfoWindow();
        this.loadDriverLocations();
    }
    
    async loadDriverLocations() {
        if (!this.map) return;
        
        try {
            const response = await fetch('api/get_drivers_locations.php');
            if (!response.ok) throw new Error('Failed to load driver locations');
            const drivers = await response.json();
            
            if (!Array.isArray(drivers)) {
                console.error('Invalid drivers data format');
                return;
            }

            const driverIds = new Set(drivers.map(d => d.id));

            // Remove markers for drivers no longer present
            for (const driverId in this.markers) {
                if (!driverIds.has(parseInt(driverId, 10))) {
                    this.markers[driverId].setMap(null);
                    delete this.markers[driverId];
                }
            }
            
            drivers.forEach(driver => {
                const lat = parseFloat(driver.latitude);
                const lng = parseFloat(driver.longitude);
                
                if (isNaN(lat) || isNaN(lng)) {
                    // Don't show drivers with invalid locations
                    return;
                }

                const position = { lat, lng };
                const infoContent = `
                    <div>
                        <h6>${driver.first_name || 'Unknown'} ${driver.last_name || 'Driver'}</h6>
                        <p>Status: <strong>${this.formatStatus(driver.status || 'N/A')}</strong></p>
                        <p>Current Orders: ${driver.current_deliveries_count || 0}</p>
                        <p>Rating: ⭐ ${parseFloat(driver.avg_rating || 0).toFixed(2)}</p>
                    </div>
                `;

                if (this.markers[driver.id]) {
                    // Update existing marker
                    this.markers[driver.id].setPosition(position);
                    this.markers[driver.id].setIcon(this.getMarkerIcon(driver.status));
                    this.markers[driver.id].infoContent = infoContent;
                } else {
                    // Create new marker
                    const marker = new google.maps.Marker({
                        position,
                        map: this.map,
                        title: `${driver.first_name} ${driver.last_name}`,
                        icon: this.getMarkerIcon(driver.status)
                    });
                    marker.infoContent = infoContent;
                    marker.addListener('click', () => {
                        this.infoWindow.setContent(marker.infoContent); // Set the content for the shared info window
                        this.infoWindow.open(this.map, marker); // Open the info window
                        this.map.panTo(marker.getPosition()); // Smoothly pan the map to the marker's position
                    });
                    this.markers[driver.id] = marker;
                }
            });
        } catch (error) {
            console.error('Error loading driver locations:', error.message);
        }
    }
    
    getMarkerIcon(status) {
        const colors = {
            'available': 'http://maps.google.com/mapfiles/ms/micons/green-dot.png',
            'on_delivery': 'http://maps.google.com/mapfiles/ms/micons/yellow-dot.png',
            'busy': 'http://maps.google.com/mapfiles/ms/micons/red-dot.png',
        };
        return colors[status] || 'http://maps.google.com/mapfiles/ms/micons/gray-dot.png';
    }
    
    getStatusColor(status) {
        const colors = {
            'pending': 'secondary',
            'assigned': 'info',
            'picked_up': 'primary',
            'on_the_way': 'warning',
            'arriving': 'success',
            'delivered': 'success',
            'failed': 'danger'
        };
        return colors[status] || 'secondary';
    }
    
    formatStatus(status) {
        if (!status) return 'N/A';
        return status.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    }
    
    startRealTimeUpdates() {
        setInterval(() => {
            this.loadLogisticsStats();
            this.loadActiveDeliveries();
            this.loadDriverLocations();
        }, this.refreshInterval);
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    const manager = new AdminLogisticsManager();
    window.logisticsManager = manager;
});
