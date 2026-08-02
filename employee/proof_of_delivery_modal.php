<!-- Enhanced Proof of Delivery Modal -->
<div class="modal fade" id="proofOfDeliveryModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-camera"></i> Proof of Delivery</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="closeProofModal" aria-label="Close"></button>
            </div>
            <form id="proofOfDeliveryForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" id="podTrackingId" name="tracking_id">
                    <input type="hidden" id="podOrderId" name="order_id">
                    <input type="hidden" id="podDriverId" name="driver_id">
                    
                    <!-- Photo Section -->
                    <div class="form-group mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-image"></i> Photo of Delivered Item *
                        </label>
                        <div class="photo-input-wrapper mb-3">
                            <div id="cameraPreview" class="camera-preview">
                                <video id="cameraVideo" autoplay playsinline style="display: none; width: 100%; border-radius: 8px;"></video>
                                <canvas id="photoCanvas" style="display: none; width: 100%;"></canvas>
                                <div id="photoPlaceholder" class="photo-placeholder">
                                    <i class="fas fa-camera fa-3x"></i>
                                    <p>Click to capture from camera or upload</p>
                                </div>
                                <img id="capturedPhoto" style="display: none; width: 100%; border-radius: 8px;">
                            </div>
                        </div>
                        
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-outline-primary" id="startCameraBtn">
                                <i class="fas fa-video"></i> Start Camera
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="capturePhotoBtn" style="display: none;">
                                <i class="fas fa-camera"></i> Capture Photo
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="stopCameraBtn" style="display: none;">
                                <i class="fas fa-stop"></i> Stop Camera
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="uploadPhotoBtn">
                                <i class="fas fa-upload"></i> Upload Photo
                            </button>
                            <input type="file" id="photoFileInput" name="photo" accept="image/*" style="display: none;">
                        </div>
                        
                        <div class="form-text mt-2">
                            Capture or upload a clear photo showing the delivered items
                        </div>
                    </div>
                    
                    <!-- Delivery Condition -->
                    <div class="form-group mb-4">
                        <label for="deliveryCondition" class="form-label fw-bold">
                            <i class="fas fa-box"></i> Delivery Condition *
                        </label>
                        <select id="deliveryCondition" name="delivery_condition" class="form-select" required>
                            <option value="">Select condition...</option>
                            <option value="good">Good - No damage</option>
                            <option value="minor_damage">Minor Damage</option>
                            <option value="major_damage">Major Damage</option>
                            <option value="incomplete">Incomplete Items</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <!-- Signature Section -->
                    <div class="form-group mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-signature"></i> Customer Signature (Optional)
                        </label>
                        <div id="signaturePad" style="border: 2px solid #dee2e6; border-radius: 8px; background: white;">
                            <canvas id="signatureCanvas" width="400" height="200" style="cursor: crosshair; border-radius: 8px; display: block;"></canvas>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSignatureBtn">
                                Clear Signature
                            </button>
                        </div>
                        <input type="hidden" id="signatureData" name="signature">
                    </div>
                    
                    <!-- Delivery Notes -->
                    <div class="form-group mb-4">
                        <label for="deliveryNotes" class="form-label fw-bold">
                            <i class="fas fa-notes-medical"></i> Delivery Notes
                        </label>
                        <textarea id="deliveryNotes" name="delivery_notes" class="form-control" rows="3" 
                                  placeholder="e.g., Item received in good condition, customer satisfied, etc."></textarea>
                    </div>
                    
                    <!-- Customer Name Confirmation -->
                    <div class="form-group mb-4">
                        <label for="customerNameConfirm" class="form-label fw-bold">
                            <i class="fas fa-user"></i> Customer Name (Confirmation) *
                        </label>
                        <input type="text" id="customerNameConfirm" name="customer_name" class="form-control" required 
                               placeholder="Enter customer's name to confirm delivery">
                    </div>
                    
                    <!-- Location -->
                    <div class="form-group mb-4">
                        <label class="form-label fw-bold">
                            <i class="fas fa-map-marker-alt"></i> Delivery Location
                        </label>
                        <div class="location-info">
                            <p id="locationDisplay" style="margin-bottom: 10px; color: #666;">
                                <i class="fas fa-spinner fa-spin"></i> Getting location...
                            </p>
                            <input type="hidden" id="podLatitude" name="latitude">
                            <input type="hidden" id="podLongitude" name="longitude">
                            <button type="button" class="btn btn-sm btn-outline-info" id="updateLocationBtn">
                                <i class="fas fa-refresh"></i> Update Location
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Submit Proof of Delivery
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.photo-placeholder {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    height: 300px;
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    color: #6c757d;
    cursor: pointer;
    transition: all 0.3s;
}

.photo-placeholder:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.camera-preview {
    position: relative;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
}

#cameraVideo, #photoCanvas {
    display: block;
    width: 100%;
    border-radius: 8px;
}

.location-info {
    background: #f0f7ff;
    padding: 12px;
    border-radius: 8px;
    border-left: 4px solid #2196F3;
}

.form-control, .form-select {
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.form-control:focus, .form-select:focus {
    border-color: #c62828;
    box-shadow: 0 0 0 0.2rem rgba(198, 40, 40, 0.25);
}
</style>

<script>
// Proof of Delivery Script
class ProofOfDeliveryHandler {
    constructor() {
        this.video = document.getElementById('cameraVideo');
        this.canvas = document.getElementById('photoCanvas');
        this.capturedPhoto = document.getElementById('capturedPhoto');
        this.photoPlaceholder = document.getElementById('photoPlaceholder');
        this.cameraStream = null;
        this.signatureCanvas = document.getElementById('signatureCanvas');
        this.isDrawing = false;
        this.initEventListeners();
        this.initSignaturePad();
        this.initGeolocation();
    }
    
    initEventListeners() {
        document.getElementById('startCameraBtn').addEventListener('click', () => this.startCamera());
        document.getElementById('capturePhotoBtn').addEventListener('click', () => this.capturePhoto());
        document.getElementById('stopCameraBtn').addEventListener('click', () => this.stopCamera());
        document.getElementById('uploadPhotoBtn').addEventListener('click', () => document.getElementById('photoFileInput').click());
        document.getElementById('photoFileInput').addEventListener('change', (e) => this.handlePhotoUpload(e));
        document.getElementById('updateLocationBtn').addEventListener('click', () => this.updateLocation());
        document.getElementById('clearSignatureBtn').addEventListener('click', () => this.clearSignature());
        document.getElementById('proofOfDeliveryForm').addEventListener('submit', (e) => this.submitProof(e));
    }
    
    async startCamera() {
        try {
            const constraints = { video: { facingMode: 'environment' } };
            this.cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
            this.video.srcObject = this.cameraStream;
            this.video.style.display = 'block';
            this.photoPlaceholder.style.display = 'none';
            
            document.getElementById('startCameraBtn').style.display = 'none';
            document.getElementById('capturePhotoBtn').style.display = 'inline-block';
            document.getElementById('stopCameraBtn').style.display = 'inline-block';
        } catch (error) {
            alert('Error accessing camera: ' + error.message);
        }
    }
    
    capturePhoto() {
        const context = this.canvas.getContext('2d');
        this.canvas.width = this.video.videoWidth;
        this.canvas.height = this.video.videoHeight;
        context.drawImage(this.video, 0, 0);
        
        this.canvas.toBlob((blob) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                this.capturedPhoto.src = e.target.result;
                this.capturedPhoto.style.display = 'block';
            };
            reader.readAsDataURL(blob);
        });
        
        this.video.style.display = 'none';
        document.getElementById('capturePhotoBtn').textContent = '✓ Photo Captured - Take Another?';
        document.getElementById('capturePhotoBtn').classList.add('btn-success');
        
        // Store the blob for upload
        this.canvas.toBlob((blob) => {
            this.photoBlob = blob;
        });
    }
    
    stopCamera() {
        if (this.cameraStream) {
            this.cameraStream.getTracks().forEach(track => track.stop());
            this.video.style.display = 'none';
            this.photoPlaceholder.style.display = 'flex';
            
            document.getElementById('startCameraBtn').style.display = 'inline-block';
            document.getElementById('capturePhotoBtn').style.display = 'none';
            document.getElementById('stopCameraBtn').style.display = 'none';
            document.getElementById('capturePhotoBtn').textContent = '📸 Capture Photo';
            document.getElementById('capturePhotoBtn').classList.remove('btn-success');
        }
    }
    
    handlePhotoUpload(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                this.capturedPhoto.src = event.target.result;
                this.capturedPhoto.style.display = 'block';
                this.photoPlaceholder.style.display = 'none';
                this.photoBlob = file;
            };
            reader.readAsDataURL(file);
        }
    }
    
    initSignaturePad() {
        const ctx = this.signatureCanvas.getContext('2d', { willReadFrequently: true });
        
        this.signatureCanvas.addEventListener('mousedown', (e) => {
            this.isDrawing = true;
            const rect = this.signatureCanvas.getBoundingClientRect();
            ctx.beginPath();
            ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
        });
        
        this.signatureCanvas.addEventListener('mousemove', (e) => {
            if (this.isDrawing) {
                const rect = this.signatureCanvas.getBoundingClientRect();
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#000';
                ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
                ctx.stroke();
            }
        });
        
        this.signatureCanvas.addEventListener('mouseup', () => {
            this.isDrawing = false;
        });
        
        this.signatureCanvas.addEventListener('mouseleave', () => {
            this.isDrawing = false;
        });
    }
    
    clearSignature() {
        const ctx = this.signatureCanvas.getContext('2d', { willReadFrequently: true });
        ctx.clearRect(0, 0, this.signatureCanvas.width, this.signatureCanvas.height);
    }
    
    initGeolocation() {
        this.updateLocation();
    }
    
    updateLocation() {
        if (navigator.geolocation) {
            document.getElementById('locationDisplay').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;
                    const accuracy = position.coords.accuracy;
                    
                    document.getElementById('podLatitude').value = lat;
                    document.getElementById('podLongitude').value = lon;
                    
                    document.getElementById('locationDisplay').innerHTML = 
                        `<i class="fas fa-check-circle text-success"></i> Location: ${lat.toFixed(4)}, ${lon.toFixed(4)} <br>
                         <small>Accuracy: ±${accuracy.toFixed(0)}m</small>`;
                },
                (error) => {
                    document.getElementById('locationDisplay').innerHTML = 
                        `<i class="fas fa-exclamation-triangle text-warning"></i> Could not get location: ${error.message}`;
                }
            );
        }
    }
    
    async submitProof(e) {
        e.preventDefault();
        
        const submitBtn = document.querySelector('#proofOfDeliveryForm button[type="submit"]');
        const originalBtnHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
        // Validate required fields
        if (!this.capturedPhoto.src || this.capturedPhoto.style.display === 'none') {
            Swal.fire('Validation Error', 'Please capture or upload a photo.', 'warning');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
            return;
        }
        
        if (!document.getElementById('deliveryCondition').value) {
            Swal.fire('Validation Error', 'Please select the delivery condition.', 'warning');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
            return;
        }
        
        if (!document.getElementById('customerNameConfirm').value) {
            Swal.fire('Validation Error', 'Please confirm the customer\'s name.', 'warning');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
            return;
        }
        
        // Submit
        try {
            // Create FormData
            const formData = new FormData();
            formData.append('tracking_id', document.getElementById('podTrackingId').value);
            formData.append('order_id', document.getElementById('podOrderId').value);
            formData.append('driver_id', document.getElementById('podDriverId').value);
            formData.append('delivery_condition', document.getElementById('deliveryCondition').value);
            formData.append('delivery_notes', document.getElementById('deliveryNotes').value);
            formData.append('customer_name', document.getElementById('customerNameConfirm').value);
            formData.append('latitude', document.getElementById('podLatitude').value);
            formData.append('longitude', document.getElementById('podLongitude').value);
            
            // Asynchronously handle photo blob
            if (this.photoBlob) {
                formData.append('photo', this.photoBlob, 'delivery_proof.jpg');
            } else if (this.capturedPhoto.src.startsWith('data:')) {
                const photoRes = await fetch(this.capturedPhoto.src);
                const photoBlob = await photoRes.blob();
                formData.append('photo', photoBlob, 'delivery_proof.jpg');
            }
            
            // Asynchronously handle signature blob
            if (this.signatureCanvas.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, this.signatureCanvas.width, this.signatureCanvas.height).data.some(val => val !== 0)) {
                const signatureBlob = await new Promise(resolve => this.signatureCanvas.toBlob(resolve, 'image/png'));
                if (signatureBlob) {
                    formData.append('signature', signatureBlob, 'signature.png');
                }
            }

            const response = await fetch('../api/submit_proof_of_delivery.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            let result;
            try {
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(`Invalid response from server: ${response.status}. Please check console for details.`);
            }
            
            if (!response.ok || !result.success) {
                Swal.fire('Submission Failed', result.message || 'An unknown server error occurred.', 'error');
            } else {
                const modalElement = document.getElementById('proofOfDeliveryModal');
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    // Blur any focused element inside the modal before hiding to prevent accessibility warnings.
                    const focusedElement = modalElement.querySelector(':focus');
                    if (focusedElement) {
                        focusedElement.blur();
                    }
                    modalInstance.hide();
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Delivered!',
                    text: 'Proof of delivery submitted. This item will be moved to your history.',
                    showConfirmButton: false,
                    timer: 2500
                });

                // Update the UI on logistics.php
                const trackingId = document.getElementById('podTrackingId').value;
                const cardContainer = document.getElementById('delivery-card-' + trackingId);
                if (cardContainer) {
                    cardContainer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    cardContainer.style.opacity = '0';
                    cardContainer.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        cardContainer.remove();
                        const activeCountBadge = document.querySelector('#active-tab .badge');
                        if (activeCountBadge) {
                            let currentCount = parseInt(activeCountBadge.textContent, 10);
                            if (!isNaN(currentCount) && currentCount > 0) {
                                activeCountBadge.textContent = currentCount - 1;
                            }
                        }
                    }, 500);
                }
            }
        } catch (error) {
            console.error('Proof of delivery submission failed:', error);
            Swal.fire('Submission Error', 'An unexpected error occurred. Please check the console (F12) for details.', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
        }
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    new ProofOfDeliveryHandler();
    
    // Set tracking ID when modal is shown
    document.getElementById('proofOfDeliveryModal').addEventListener('show.bs.modal', (e) => {
        const button = e.relatedTarget;
        const trackingId = button.getAttribute('data-tracking-id');
        const orderId = button.getAttribute('data-order-id');
        const driverId = button.getAttribute('data-driver-id');
        
        document.getElementById('podTrackingId').value = trackingId;
        document.getElementById('podOrderId').value = orderId;
        document.getElementById('podDriverId').value = driverId;
    });
});
</script>
