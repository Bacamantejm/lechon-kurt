<?php
session_start();

require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=my_orders.php');
    exit;
}

if (!isset($_GET['order_id'])) {
    header('Location: my_orders.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$order_id = intval($_GET['order_id']);

// Fetch order and verify ownership and status
$order_query = "SELECT * FROM orders WHERE id = ? AND user_id = ? AND status IN ('delivered', 'completed')";
$stmt = mysqli_prepare($conn, $order_query);
mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
mysqli_stmt_execute($stmt);
$order_result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($order_result);
mysqli_stmt_close($stmt);

$page_title = "Leave a Review | Lechon Delights";
include 'includes/header.php';

if (!$order) {
    echo "<div class='container' style='padding: 50px; text-align: center;'><h2>Order not found or not eligible for review.</h2><a href='my_orders.php'>Go back to My Orders</a></div>";
    include 'includes/footer.php';
    exit;
}

// Fetch order items that have not been reviewed yet
$items_query = "SELECT oi.*, p.id as product_db_id FROM order_items oi JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = ? AND oi.is_reviewed = 0";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
$items = [];
while ($row = mysqli_fetch_assoc($items_result)) {
    $items[] = $row;
}
mysqli_stmt_close($stmt);

if (empty($items)) {
    echo "<div class='container' style='padding: 50px; text-align: center;'><h2>You have already reviewed all items for this order.</h2><a href='my_orders.php'>Go back to My Orders</a></div>";
    include 'includes/footer.php';
    exit;
}

// The rest of the HTML and form content
?>
<style>
/* Modern Review Page Styles */
:root {
    --primary-color: #c62828;
    --primary-dark: #b71c1c;
    --text-dark: #2c3e50;
    --text-light: #6c757d;
    --bg-light: #f8f9fa;
    --card-shadow: 0 10px 30px rgba(0,0,0,0.08);
    --transition: all 0.3s ease;
}

.review-page { padding: 60px 0; background-color: var(--bg-light); min-height: 80vh; }
.review-container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; border-radius: 20px; box-shadow: var(--card-shadow); animation: fadeIn 0.6s ease; }

.review-header { text-align: center; margin-bottom: 40px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
.review-header h1 { color: var(--primary-color); margin-bottom: 10px; font-size: 2.2rem; font-weight: 800; }
.review-header p { color: var(--text-light); font-size: 1.1rem; }

.product-review-card { background: white; padding: 25px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #eee; transition: var(--transition); }
.product-review-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-color: #ddd; transform: translateY(-2px); }
.product-review-card h3 { color: var(--text-dark); font-size: 1.3rem; margin-bottom: 15px; font-weight: 700; }

.rating-group { margin-bottom: 20px; }
.rating-group label { font-weight: 600; color: var(--text-dark); margin-bottom: 8px; display: block; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; }

.star-rating { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 8px; }
.star-rating input { display: none; }
.star-rating label { font-size: 2.2rem; color: #e0e0e0; cursor: pointer; transition: transform 0.2s, color 0.2s; }

.star-rating input:checked ~ label,
.star-rating:not(:checked) > label:hover,
.star-rating:not(:checked) > label:hover ~ label { color: #ffca28; }

.star-rating label:hover { transform: scale(1.2); }

.form-group textarea { width: 100%; padding: 15px; border: 2px solid #eee; border-radius: 10px; min-height: 120px; font-family: inherit; font-size: 1rem; transition: var(--transition); resize: vertical; }
.form-group textarea:focus { border-color: var(--primary-color); outline: none; box-shadow: 0 0 0 4px rgba(198, 40, 40, 0.1); }

.delivery-review-card { background: #f8f9fa; padding: 30px; border-radius: 12px; margin-top: 40px; border-left: 5px solid #007bff; }
.delivery-review-card h3 { color: #007bff; margin-bottom: 20px; }

.btn-primary { background: var(--primary-color); color: white; padding: 15px 40px; border: none; border-radius: 50px; cursor: pointer; font-size: 1.1rem; font-weight: 700; transition: var(--transition); box-shadow: 0 4px 15px rgba(198, 40, 40, 0.3); }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(198, 40, 40, 0.4); }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="review-page">
    <div class="review-container">
        <div class="review-header">
            <h1>Share Your Thoughts</h1>
            <p>Share your experience for Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
        </div>

        <form action="process_review.php" method="POST" class="review-form">
            <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
            <input type="hidden" name="submit_review" value="1">
            
            <?php foreach ($items as $index => $item): ?>
            <div class="product-review-card">
                <h3><?php echo htmlspecialchars($item['product_name']); ?></h3>
                <input type="hidden" name="reviews[<?php echo $index; ?>][product_id]" value="<?php echo $item['product_db_id']; ?>">
                <input type="hidden" name="reviews[<?php echo $index; ?>][order_item_id]" value="<?php echo $item['id']; ?>">

                <div class="rating-group">
                    <label>Overall Rating *</label>
                    <div class="star-rating">
                        <input type="radio" id="p<?php echo $index; ?>-star5" name="reviews[<?php echo $index; ?>][rating]" value="5" required/><label for="p<?php echo $index; ?>-star5" title="5 stars">★</label>
                        <input type="radio" id="p<?php echo $index; ?>-star4" name="reviews[<?php echo $index; ?>][rating]" value="4" /><label for="p<?php echo $index; ?>-star4" title="4 stars">★</label>
                        <input type="radio" id="p<?php echo $index; ?>-star3" name="reviews[<?php echo $index; ?>][rating]" value="3" /><label for="p<?php echo $index; ?>-star3" title="3 stars">★</label>
                        <input type="radio" id="p<?php echo $index; ?>-star2" name="reviews[<?php echo $index; ?>][rating]" value="2" /><label for="p<?php echo $index; ?>-star2" title="2 stars">★</label>
                        <input type="radio" id="p<?php echo $index; ?>-star1" name="reviews[<?php echo $index; ?>][rating]" value="1" /><label for="p<?php echo $index; ?>-star1" title="1 star">★</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="comment<?php echo $index; ?>">Your Review</label>
                    <textarea id="comment<?php echo $index; ?>" name="reviews[<?php echo $index; ?>][comment]" placeholder="How was the product? What did you like or dislike?"></textarea>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if ($order['delivery_option'] === 'delivery'): ?>
            <div class="delivery-review-card">
                <h3>How was the delivery?</h3>
                <div class="rating-group">
                    <label>Delivery Service Rating *</label>
                    <div class="star-rating">
                        <input type="radio" id="d-star5" name="delivery_rating" value="5" required/><label for="d-star5" title="5 stars">★</label>
                        <input type="radio" id="d-star4" name="delivery_rating" value="4" /><label for="d-star4" title="4 stars">★</label>
                        <input type="radio" id="d-star3" name="delivery_rating" value="3" /><label for="d-star3" title="3 stars">★</label>
                        <input type="radio" id="d-star2" name="delivery_rating" value="2" /><label for="d-star2" title="2 stars">★</label>
                        <input type="radio" id="d-star1" name="delivery_rating" value="1" /><label for="d-star1" title="1 star">★</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="delivery_comment">Delivery Comments</label>
                    <textarea id="delivery_comment" name="delivery_comment" placeholder="Share your feedback about the delivery service."></textarea>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="text-align: right; margin-top: 30px;">
                <button type="submit" name="submit_review" class="btn-primary">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reviewForm = document.querySelector('.review-form');

    if (reviewForm) {
        reviewForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            Swal.fire({
                title: 'Submit Review?',
                text: "Are you sure you want to submit your review(s)? You cannot edit them later.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#c62828',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Submit!',
                cancelButtonText: 'Not now'
            }).then((result) => {
                if (result.isConfirmed) {
                    // If confirmed, submit the form programmatically
                    reviewForm.submit();
                }
            });
        });
    }

    // Handle page load notifications (if process_review.php redirects back here)
    <?php if (isset($_SESSION['success_msg'])): ?>
        Swal.fire({ title: 'Success!', text: '<?php echo addslashes($_SESSION['success_msg']); ?>', icon: 'success', confirmButtonText: 'OK' });
        <?php unset($_SESSION['success_msg']); endif; ?>
    
    <?php if (isset($_SESSION['error_msg'])): ?>
        Swal.fire({ title: 'Error!', text: '<?php echo addslashes($_SESSION['error_msg']); ?>', icon: 'error', confirmButtonText: 'OK' });
        <?php unset($_SESSION['error_msg']); endif; ?>
});
</script>