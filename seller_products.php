<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/SubscriptionAccessService.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

function getLatestApprovedFranchise($conn, $user_id) {
    $query = "SELECT business_name, business_type
              FROM franchise_applications
              WHERE user_id = ? AND status = 'approved'
              ORDER BY reviewed_at DESC, created_at DESC
              LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = $result ? mysqli_fetch_assoc($result) : null;
    if ($result) {
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
    return $data ?: null;
}

// Check if user can manage seller products
$user_id = (int)$_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user_info = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($stmt);

$has_seller_access = $user_info && (($user_info['account_type'] ?? '') === 'organization');
if (!$has_seller_access) {
    $approved_franchise = getLatestApprovedFranchise($conn, $user_id);
    if ($approved_franchise) {
        $business_name = trim((string)($approved_franchise['business_name'] ?? ''));
        $business_type = trim((string)($approved_franchise['business_type'] ?? ''));

        $promote_query = "UPDATE users
                          SET account_type = 'organization',
                              business_name = COALESCE(NULLIF(business_name, ''), ?),
                              business_type = COALESCE(NULLIF(business_type, ''), ?)
                          WHERE id = ?";
        $promote_stmt = mysqli_prepare($conn, $promote_query);
        if ($promote_stmt) {
            mysqli_stmt_bind_param($promote_stmt, "ssi", $business_name, $business_type, $user_id);
            mysqli_stmt_execute($promote_stmt);
            mysqli_stmt_close($promote_stmt);
        }

        $_SESSION['account_type'] = 'organization';
        $has_seller_access = true;
    }
}

if (!$has_seller_access) {
    header('Location: index.php?error=not_approved');
    exit;
}

$seller_sub_details = SubscriptionAccessService::getShopSubscriptionDetails($conn, $user_id);
$can_upload_products = !empty($seller_sub_details['can_upload_products']);

// Ensure sizes column exists for weight-based options
$check_sizes_col = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'sizes'");
if ($check_sizes_col && mysqli_num_rows($check_sizes_col) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN sizes TEXT NULL DEFAULT NULL COMMENT 'JSON array of weight and size options' AFTER image");
}
if ($check_sizes_col) mysqli_free_result($check_sizes_col);

if (!function_exists('processProductWeightTiers')) {
    function processProductWeightTiers(array &$uploaded_images): ?string {
        $sizes_array = [];
        if (!empty($_POST['weight_tiers']) && is_array($_POST['weight_tiers'])) {
            foreach ($_POST['weight_tiers'] as $idx => $tier) {
                $tier_name = trim((string)($tier['name'] ?? ''));
                $tier_price = floatval($tier['price'] ?? 0);
                $tier_good_for = trim((string)($tier['good_for'] ?? ''));
                $tier_img = trim((string)($tier['existing_image'] ?? ''));

                if (!empty($_FILES['weight_tier_images']['name'][$idx])) {
                    $f_name = $_FILES['weight_tier_images']['name'][$idx];
                    $f_tmp = $_FILES['weight_tier_images']['tmp_name'][$idx];
                    $f_size = (int)($_FILES['weight_tier_images']['size'][$idx] ?? 0);
                    $f_err = $_FILES['weight_tier_images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;

                    if ($f_err === UPLOAD_ERR_OK && $f_size > 0 && is_uploaded_file($f_tmp)) {
                        $ext = strtolower(pathinfo((string)$f_name, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $target_dir = __DIR__ . '/uploads/products/';
                            if (!is_dir($target_dir)) {
                                @mkdir($target_dir, 0755, true);
                            }
                            $new_fn = time() . '_' . uniqid('wt_') . '.' . $ext;
                            if (move_uploaded_file($f_tmp, $target_dir . $new_fn)) {
                                $tier_img = 'uploads/products/' . $new_fn;
                                if (!in_array($tier_img, $uploaded_images, true)) {
                                    $uploaded_images[] = $tier_img;
                                }
                            }
                        }
                    }
                }

                if ($tier_name !== '' && $tier_price > 0) {
                    $sizes_array[] = [
                        'name' => $tier_name,
                        'weight' => $tier_name,
                        'price' => $tier_price,
                        'good_for' => $tier_good_for,
                        'image' => $tier_img
                    ];
                }
            }
        }
        return !empty($sizes_array) ? json_encode(array_values($sizes_array)) : null;
    }
}

if (function_exists('ensureProductMultipleImagesSchema')) {
    ensureProductMultipleImagesSchema($conn);
}

// Handle product status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product'])) {
    $product_id = intval($_POST['product_id']);
    
    // Verify product belongs to this seller
    $verify_query = "SELECT p.id FROM products p WHERE p.id = ? AND p.seller_id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($verify_stmt, "ii", $product_id, $user_id);
    mysqli_stmt_execute($verify_stmt);
    mysqli_stmt_store_result($verify_stmt);
    
    if (mysqli_stmt_num_rows($verify_stmt) > 0) {
        mysqli_stmt_close($verify_stmt);
        $toggle_query = "UPDATE products SET is_active = NOT is_active WHERE id = ?";
        $toggle_stmt = mysqli_prepare($conn, $toggle_query);
        mysqli_stmt_bind_param($toggle_stmt, "i", $product_id);
        mysqli_stmt_execute($toggle_stmt);
        mysqli_stmt_close($toggle_stmt);
        $_SESSION['success'] = "Product status updated successfully.";
    } else {
        mysqli_stmt_close($verify_stmt);
        $_SESSION['error'] = "Unauthorized action.";
    }
}

// Handle add new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_product'])) {
    if (!$can_upload_products) {
        $_SESSION['error'] = "Uploading products is restricted. Please upgrade to an active Starter, Growth, or Pro Subscription Plan.";
        header("Location: seller_products.php");
        exit;
    }

    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description']);
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);
    
    $errors = [];
    
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    // Handle image upload (supports multiple images and legacy single input)
    $uploaded_images = [];
    if (!empty($_FILES['product_images']['name'])) {
        if (function_exists('handleProductMultiImageUpload')) {
            $uploaded_images = handleProductMultiImageUpload($_FILES['product_images'], 'uploads/products/');
        }
    } elseif (!empty($_FILES['product_image']['name']) && (int)($_FILES['product_image']['size'] ?? 0) > 0) {
        if (function_exists('handleProductMultiImageUpload')) {
            $uploaded_images = handleProductMultiImageUpload($_FILES['product_image'], 'uploads/products/');
        }
    }

    // Process weight-based pricing tiers
    $sizes_json = processProductWeightTiers($uploaded_images);
    if ($sizes_json !== null) {
        $tmp_sizes = json_decode($sizes_json, true);
        if (!empty($tmp_sizes)) {
            if ($price <= 0) {
                $price = min(array_column($tmp_sizes, 'price'));
            }
            if ($weight_info === '' && !empty($tmp_sizes[0]['weight'])) {
                $weight_info = $tmp_sizes[0]['weight'];
            }
            if ($pax_info === '' && !empty($tmp_sizes[0]['good_for'])) {
                $pax_info = $tmp_sizes[0]['good_for'];
            }
        }
    }

    $image_path = $uploaded_images[0] ?? '';
    $additional_images = array_slice($uploaded_images, 1);
    $additional_images_json = !empty($additional_images) ? json_encode(array_values($additional_images)) : null;

    if (empty($errors)) {
        // Generate unique product code
        do {
            $product_code = 'prod-' . bin2hex(random_bytes(3));
            $check_stmt = mysqli_prepare($conn, "SELECT 1 FROM products WHERE product_id = ? LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, "s", $product_code);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            $exists = mysqli_stmt_num_rows($check_stmt) > 0;
            mysqli_stmt_close($check_stmt);
        } while ($exists);

        $insert_query = "INSERT INTO products (seller_id, product_id, name, category, price, description, weight_info, pax_info, image, additional_images, sizes, stock, is_active, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, "isssdssssssi", $user_id, $product_code, $name, $category, $price, $description, $weight_info, $pax_info, $image_path, $additional_images_json, $sizes_json, $stock);

        if (mysqli_stmt_execute($stmt)) {
            $new_product_id = mysqli_insert_id($conn);

            // Save Bill of Materials (BOM) recipe ingredients from checklist or dynamic rows
            $recipe_items = [];
            if (!empty($_POST['recipe_ingredients']) && is_array($_POST['recipe_ingredients'])) {
                foreach ($_POST['recipe_ingredients'] as $m_id => $r_data) {
                    if (!empty($r_data['selected'])) {
                        $mat_id = intval($m_id);
                        $qty = floatval($r_data['qty'] ?? 0);
                        if ($mat_id > 0 && $qty > 0) {
                            $recipe_items[] = ['material_id' => $mat_id, 'quantity_needed' => $qty];
                        }
                    }
                }
            } elseif (!empty($_POST['ingredients']) && is_array($_POST['ingredients'])) {
                foreach ($_POST['ingredients'] as $ing) {
                    $mat_id = intval($ing['material_id'] ?? 0);
                    $qty = floatval($ing['quantity_needed'] ?? 0);
                    if ($mat_id > 0 && $qty > 0) {
                        $recipe_items[] = ['material_id' => $mat_id, 'quantity_needed' => $qty];
                    }
                }
            }

            if (!empty($recipe_items)) {
                $bom_ins = mysqli_prepare($conn, "INSERT INTO bill_of_materials (product_id, material_id, quantity_needed) VALUES (?, ?, ?)");
                foreach ($recipe_items as $ritem) {
                    if ($bom_ins) {
                        mysqli_stmt_bind_param($bom_ins, "iid", $new_product_id, $ritem['material_id'], $ritem['quantity_needed']);
                        mysqli_stmt_execute($bom_ins);
                    }
                }
                if ($bom_ins) mysqli_stmt_close($bom_ins);
            }

            // Sync ingredient availability immediately
            require_once 'includes/IngredientStockManager.php';
            IngredientStockManager::syncProductAvailabilityForStore($conn, $user_id);

            $_SESSION['success'] = "Menu item '$name' added successfully! It is now live on the menu.";
            header("Location: seller_products.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to add product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}

// Handle update product & re-upload images
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $product_id = intval($_POST['product_id']);
    
    // Verify product belongs to this seller
    $verify_query = "SELECT id, image, additional_images FROM products WHERE id = ? AND seller_id = ?";
    $v_stmt = mysqli_prepare($conn, $verify_query);
    mysqli_stmt_bind_param($v_stmt, "ii", $product_id, $user_id);
    mysqli_stmt_execute($v_stmt);
    $v_res = mysqli_stmt_get_result($v_stmt);
    $existing_product = $v_res ? mysqli_fetch_assoc($v_res) : null;
    mysqli_stmt_close($v_stmt);

    if (!$existing_product) {
        $_SESSION['error'] = "Unauthorized action. Product not found or does not belong to your shop.";
        header("Location: seller_products.php");
        exit;
    }

    $name = trim($_POST['product_name']);
    $category = trim($_POST['product_category']);
    $price = floatval($_POST['product_price']);
    $description = trim($_POST['product_description'] ?? '');
    $weight_info = trim($_POST['weight_info'] ?? '');
    $pax_info = trim($_POST['pax_info'] ?? '');
    $stock = intval($_POST['stock'] ?? 0);

    $errors = [];
    if (empty($name)) $errors[] = "Product name is required";
    if (empty($category)) $errors[] = "Category is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";

    if (empty($errors)) {
        // Collect kept existing images from checkboxes
        $kept_images = isset($_POST['kept_images']) && is_array($_POST['kept_images']) ? $_POST['kept_images'] : [];
        $valid_kept = [];
        $all_curr = function_exists('getProductAllImages') ? getProductAllImages($existing_product) : array_filter([$existing_product['image'] ?? '']);
        foreach ($kept_images as $kimg) {
            $kimg_clean = trim((string)$kimg);
            if (in_array($kimg_clean, $all_curr, true)) {
                $valid_kept[] = $kimg_clean;
            }
        }

        // Upload any newly selected images (shop owners can upload multiple images anytime)
        $newly_uploaded = [];
        if (!empty($_FILES['product_images']['name'])) {
            if (function_exists('handleProductMultiImageUpload')) {
                $newly_uploaded = handleProductMultiImageUpload($_FILES['product_images'], 'uploads/products/');
            }
        }

        // Process weight-based pricing tiers
        $sizes_json = processProductWeightTiers($newly_uploaded);
        if ($sizes_json !== null) {
            $tmp_sizes = json_decode($sizes_json, true);
            if (!empty($tmp_sizes)) {
                if ($price <= 0) {
                    $price = min(array_column($tmp_sizes, 'price'));
                }
                if ($weight_info === '' && !empty($tmp_sizes[0]['weight'])) {
                    $weight_info = $tmp_sizes[0]['weight'];
                }
                if ($pax_info === '' && !empty($tmp_sizes[0]['good_for'])) {
                    $pax_info = $tmp_sizes[0]['good_for'];
                }
            }
        }
        if ($price <= 0) $errors[] = "Price must be greater than 0";

        // Combine kept images + newly uploaded images
        $final_images = array_merge($valid_kept, $newly_uploaded);
        $final_primary = $final_images[0] ?? '';
        $final_additional = array_slice($final_images, 1);
        $final_additional_json = !empty($final_additional) ? json_encode(array_values($final_additional)) : null;

        $update_query = "UPDATE products SET name = ?, category = ?, price = ?, description = ?, weight_info = ?, pax_info = ?, stock = ?, image = ?, additional_images = ?, sizes = ?, updated_at = NOW() WHERE id = ? AND seller_id = ?";
        $u_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($u_stmt, "ssdsssisssii", $name, $category, $price, $description, $weight_info, $pax_info, $stock, $final_primary, $final_additional_json, $sizes_json, $product_id, $user_id);

        if (mysqli_stmt_execute($u_stmt)) {
            // Update Bill of Materials (BOM) recipe ingredients from checklist or dynamic rows
            mysqli_query($conn, "DELETE FROM bill_of_materials WHERE product_id = " . intval($product_id));
            $recipe_items = [];
            if (!empty($_POST['recipe_ingredients']) && is_array($_POST['recipe_ingredients'])) {
                foreach ($_POST['recipe_ingredients'] as $m_id => $r_data) {
                    if (!empty($r_data['selected'])) {
                        $mat_id = intval($m_id);
                        $qty = floatval($r_data['qty'] ?? 0);
                        if ($mat_id > 0 && $qty > 0) {
                            $recipe_items[] = ['material_id' => $mat_id, 'quantity_needed' => $qty];
                        }
                    }
                }
            } elseif (!empty($_POST['ingredients']) && is_array($_POST['ingredients'])) {
                foreach ($_POST['ingredients'] as $ing) {
                    $mat_id = intval($ing['material_id'] ?? 0);
                    $qty = floatval($ing['quantity_needed'] ?? 0);
                    if ($mat_id > 0 && $qty > 0) {
                        $recipe_items[] = ['material_id' => $mat_id, 'quantity_needed' => $qty];
                    }
                }
            }

            if (!empty($recipe_items)) {
                $bom_ins = mysqli_prepare($conn, "INSERT INTO bill_of_materials (product_id, material_id, quantity_needed) VALUES (?, ?, ?)");
                foreach ($recipe_items as $ritem) {
                    if ($bom_ins) {
                        mysqli_stmt_bind_param($bom_ins, "iid", $product_id, $ritem['material_id'], $ritem['quantity_needed']);
                        mysqli_stmt_execute($bom_ins);
                    }
                }
                if ($bom_ins) mysqli_stmt_close($bom_ins);
            }

            // Sync ingredient availability immediately
            require_once 'includes/IngredientStockManager.php';
            IngredientStockManager::syncProductAvailabilityForStore($conn, $user_id);

            $_SESSION['success'] = "Menu item '$name' updated successfully! All photo, recipe, and stock changes are now live.";
        } else {
            $_SESSION['error'] = "Failed to update product: " . mysqli_error($conn);
        }
        mysqli_stmt_close($u_stmt);
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }

    header("Location: seller_products.php");
    exit;
}

// Handle adding new ingredient / raw material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    $mat_name = trim($_POST['material_name'] ?? '');
    $mat_unit = trim($_POST['material_unit'] ?? 'kg');
    $initial_stock = intval(round(floatval($_POST['initial_stock'] ?? 0)));
    $reorder_level = intval(round(floatval($_POST['reorder_level'] ?? 10)));
    $cost_per_unit = floatval($_POST['cost_per_unit'] ?? 0);

    if (!empty($mat_name) && !empty($mat_unit)) {
        if (function_exists('ensureStoreMaterialInventoryTable')) {
            ensureStoreMaterialInventoryTable($conn);
        }
        // 1. Insert into materials table
        $m_stmt = mysqli_prepare($conn, "INSERT INTO materials (name, unit, current_stock, min_level, cost_per_unit, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($m_stmt) {
            $init_d = (float)$initial_stock;
            $reord_d = (float)$reorder_level;
            mysqli_stmt_bind_param($m_stmt, "ssddd", $mat_name, $mat_unit, $init_d, $reord_d, $cost_per_unit);
            if (mysqli_stmt_execute($m_stmt)) {
                $new_mat_id = mysqli_insert_id($conn);
                // 2. Initialize store-level inventory
                $smi_stmt = mysqli_prepare($conn, "
                    INSERT INTO store_material_inventory (store_id, material_id, current_stock, reorder_level, unit, last_restocked_at) 
                    VALUES (?, ?, ?, ?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE current_stock = VALUES(current_stock)
                ");
                if ($smi_stmt) {
                    mysqli_stmt_bind_param($smi_stmt, "iidss", $user_id, $new_mat_id, $init_d, $reord_d, $mat_unit);
                    mysqli_stmt_execute($smi_stmt);
                    mysqli_stmt_close($smi_stmt);
                }
                $_SESSION['success'] = "Ingredient '$mat_name' ({$initial_stock} {$mat_unit}) added to inventory successfully!";
            } else {
                $_SESSION['error'] = "Failed to add ingredient: " . mysqli_error($conn);
            }
            mysqli_stmt_close($m_stmt);
        }
    } else {
        $_SESSION['error'] = "Ingredient name and unit of measurement are required.";
    }
    header("Location: seller_products.php?tab=ingredients");
    exit();
}

// Handle editing existing ingredient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_material'])) {
    $mat_id = intval($_POST['material_id'] ?? 0);
    $mat_name = trim($_POST['material_name'] ?? '');
    $mat_unit = trim($_POST['material_unit'] ?? 'kg');
    $reorder_level = floatval($_POST['reorder_level'] ?? 10);
    $cost_per_unit = floatval($_POST['cost_per_unit'] ?? 0);

    if ($mat_id > 0 && !empty($mat_name) && !empty($mat_unit)) {
        $u_m = mysqli_prepare($conn, "UPDATE materials SET name = ?, unit = ?, min_level = ?, cost_per_unit = ?, last_updated = NOW() WHERE id = ?");
        if ($u_m) {
            mysqli_stmt_bind_param($u_m, "ssddi", $mat_name, $mat_unit, $reorder_level, $cost_per_unit, $mat_id);
            mysqli_stmt_execute($u_m);
            mysqli_stmt_close($u_m);
        }
        $u_smi = mysqli_prepare($conn, "UPDATE store_material_inventory SET reorder_level = ?, unit = ? WHERE material_id = ? AND store_id = ?");
        if ($u_smi) {
            mysqli_stmt_bind_param($u_smi, "dsii", $reorder_level, $mat_unit, $mat_id, $user_id);
            mysqli_stmt_execute($u_smi);
            mysqli_stmt_close($u_smi);
        }
        $_SESSION['success'] = "Ingredient '$mat_name' updated successfully!";
    } else {
        $_SESSION['error'] = "Valid ingredient name and unit are required.";
    }
    header("Location: seller_products.php?tab=ingredients");
    exit();
}

// Handle deleting an ingredient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    $mat_id = intval($_POST['material_id'] ?? 0);
    if ($mat_id > 0) {
        mysqli_query($conn, "DELETE FROM bill_of_materials WHERE material_id = $mat_id");
        mysqli_query($conn, "DELETE FROM store_material_inventory WHERE material_id = $mat_id AND store_id = $user_id");
        mysqli_query($conn, "DELETE FROM materials WHERE id = $mat_id");
        $_SESSION['success'] = "Ingredient removed from catalog.";
    }
    header("Location: seller_products.php?tab=ingredients");
    exit();
}

// Handle submitting a refill request to a supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_supplier_refill'])) {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $urgency_level = in_array($_POST['urgency_level'] ?? '', ['normal', 'urgent', 'critical']) ? $_POST['urgency_level'] : 'normal';
    $requested_delivery_date = !empty($_POST['requested_delivery_date']) ? $_POST['requested_delivery_date'] : null;
    $store_notes = trim($_POST['store_notes'] ?? '');
    $refill_items = isset($_POST['refill_items']) && is_array($_POST['refill_items']) ? $_POST['refill_items'] : [];

    if ($supplier_id <= 0) {
        $_SESSION['error'] = "Please select a supplier.";
    } elseif (empty($refill_items)) {
        $_SESSION['error'] = "Please specify at least one ingredient to refill.";
    } else {
        $order_code = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $po_stmt = mysqli_prepare($conn, "
            INSERT INTO supplier_orders (order_code, supplier_id, store_id, requested_by, status, urgency_level, requested_delivery_date, store_notes, created_at) 
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
        ");
        if ($po_stmt) {
            mysqli_stmt_bind_param($po_stmt, "siiisss", $order_code, $supplier_id, $user_id, $user_id, $urgency_level, $requested_delivery_date, $store_notes);
            if (mysqli_stmt_execute($po_stmt)) {
                $supp_order_id = mysqli_insert_id($conn);
                $total_order_amount = 0.0;

                $item_ins = mysqli_prepare($conn, "
                    INSERT INTO supplier_order_items (order_id, material_id, quantity_requested, quantity_supplied, unit, locked_unit_price, line_total) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($refill_items as $item) {
                    $mat_id = intval($item['material_id'] ?? 0);
                    $qty_req = floatval($item['quantity_requested'] ?? 0);
                    if ($mat_id > 0 && $qty_req > 0) {
                        // Check wholesale catalog or fallback to material baseline cost
                        $price_chk = mysqli_prepare($conn, "SELECT wholesale_price, packaging_unit FROM supplier_catalog WHERE supplier_id = ? AND material_id = ? LIMIT 1");
                        $unit_price = 0.0;
                        $pack_unit = 'kg';
                        if ($price_chk) {
                            mysqli_stmt_bind_param($price_chk, "ii", $supplier_id, $mat_id);
                            mysqli_stmt_execute($price_chk);
                            $p_res = mysqli_stmt_get_result($price_chk);
                            if ($p_row = mysqli_fetch_assoc($p_res)) {
                                $unit_price = floatval($p_row['wholesale_price']);
                                $pack_unit = $p_row['packaging_unit'];
                            }
                            mysqli_stmt_close($price_chk);
                        }
                        if ($unit_price <= 0) {
                            $m_chk = mysqli_query($conn, "SELECT cost_per_unit, unit FROM materials WHERE id = $mat_id LIMIT 1");
                            if ($m_chk && ($m_r = mysqli_fetch_assoc($m_chk))) {
                                $unit_price = floatval($m_r['cost_per_unit']);
                                $pack_unit = $m_r['unit'];
                            }
                        }

                        $line_total = $qty_req * $unit_price;
                        $total_order_amount += $line_total;

                        if ($item_ins) {
                            mysqli_stmt_bind_param($item_ins, "iiddsdd", $supp_order_id, $mat_id, $qty_req, $qty_req, $pack_unit, $unit_price, $line_total);
                            mysqli_stmt_execute($item_ins);
                        }
                    }
                }
                if ($item_ins) mysqli_stmt_close($item_ins);

                // Update subtotal & total
                mysqli_query($conn, "UPDATE supplier_orders SET subtotal_amount = $total_order_amount, total_amount = $total_order_amount WHERE id = $supp_order_id");

                // Audit log
                mysqli_query($conn, "INSERT INTO supplier_order_status_logs (order_id, previous_status, new_status, action_by_user_id, notes) 
                                     VALUES ($supp_order_id, NULL, 'pending', $user_id, 'Refill request submitted by store owner')");

                // Notification to supplier user if linked
                $get_supp_user = mysqli_query($conn, "SELECT user_id FROM suppliers WHERE id = $supplier_id LIMIT 1");
                if ($get_supp_user && ($su_r = mysqli_fetch_assoc($get_supp_user))) {
                    $supp_uid = (int)($su_r['user_id'] ?? 0);
                    if ($supp_uid > 0) {
                        $n_title = "New Refill Request: $order_code";
                        $n_body = "A store owner has submitted refill request $order_code with $urgency_level priority.";
                        $n_stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, 'supplier_refill_request', ?, ?, 0, NOW())");
                        if ($n_stmt) {
                            mysqli_stmt_bind_param($n_stmt, "iss", $supp_uid, $n_title, $n_body);
                            mysqli_stmt_execute($n_stmt);
                            mysqli_stmt_close($n_stmt);
                        }
                    }
                }

                $_SESSION['success'] = "Refill request $order_code has been submitted to your supplier successfully!";
            } else {
                $_SESSION['error'] = "Failed to submit refill request: " . mysqli_error($conn);
            }
            mysqli_stmt_close($po_stmt);
        }
    }
    header("Location: seller_products.php?tab=procurement");
    exit();
}

// Handle logging self-procured purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_self_procurement'])) {
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $store_notes = trim($_POST['store_notes'] ?? '');
    $total_expenses = floatval($_POST['total_expenses'] ?? 0);
    $self_items = isset($_POST['self_items']) && is_array($_POST['self_items']) ? $_POST['self_items'] : [];

    // Optional receipt photo upload
    $receipt_image_path = null;
    if (!empty($_FILES['receipt_photo']['name']) && $_FILES['receipt_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['receipt_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts)) {
            $fname = 'rec_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $tdir = __DIR__ . '/uploads/procurement_receipts';
            if (!is_dir($tdir)) @mkdir($tdir, 0777, true);
            if (move_uploaded_file($_FILES['receipt_photo']['tmp_name'], $tdir . '/' . $fname)) {
                $receipt_image_path = 'uploads/procurement_receipts/' . $fname;
            }
        }
    }

    if (empty($self_items)) {
        $_SESSION['error'] = "Please specify at least one purchased ingredient.";
    } else {
        require_once 'includes/IngredientStockManager.php';
        $order_code = IngredientStockManager::logSelfProcuredPurchase(
            $conn, 
            $user_id, 
            $vendor_name, 
            $self_items, 
            $total_expenses, 
            $invoice_number, 
            $receipt_image_path, 
            $payment_method, 
            $store_notes
        );

        if ($order_code) {
            $_SESSION['success'] = "Purchase logged ($order_code)! Your stock has been restocked, affected menu items are reactivated, and the expense has been submitted to the Finance Department for liquidation review.";
        } else {
            $_SESSION['error'] = "Failed to record purchase log. Please verify details.";
        }
    }
    header("Location: seller_products.php?tab=ingredients");
    exit();
}

// Handle confirming delivery receipt for supplier order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_store_receipt'])) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $delivery_notes = trim($_POST['delivery_notes'] ?? '');

    if ($order_id > 0) {
        require_once 'includes/IngredientStockManager.php';
        $ok = IngredientStockManager::confirmStoreDeliveryReceipt($conn, $order_id, $user_id, $delivery_notes);
        if ($ok) {
            $_SESSION['success'] = "Delivery receipt confirmed! Your ingredient inventory has been automatically incremented and final invoice confirmed in the Finance Department.";
        } else {
            $_SESSION['error'] = "Unable to confirm delivery receipt.";
        }
    }
    header("Location: seller_products.php?tab=procurement");
    exit();
}

// Get seller's products
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';

$where_clauses = ["seller_id = ?"];
if ($search) {
    $where_clauses[] = "name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";
}
if ($category) {
    $where_clauses[] = "category = '" . mysqli_real_escape_string($conn, $category) . "'";
}

$where_clause = "WHERE " . implode(" AND ", $where_clauses);
$products_query = "SELECT * FROM products $where_clause ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE seller_id = ? " . 
    ($search ? "AND name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'" : "") .
    ($category ? " AND category = '" . mysqli_real_escape_string($conn, $category) . "'" : "") .
    " ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$products_result = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

// Get categories
$categories_query = "SELECT DISTINCT category FROM products WHERE seller_id = ? ORDER BY category";
$cat_stmt = mysqli_prepare($conn, $categories_query);
mysqli_stmt_bind_param($cat_stmt, "i", $user_id);
mysqli_stmt_execute($cat_stmt);
$categories_result = mysqli_stmt_get_result($cat_stmt);
mysqli_stmt_close($cat_stmt);

// Fetch all raw ingredients / materials
$materials_list = [];
$m_q = mysqli_query($conn, "SELECT id, name, unit, cost_per_unit FROM materials ORDER BY name ASC");
if ($m_q) {
    while ($m_row = mysqli_fetch_assoc($m_q)) {
        $materials_list[] = $m_row;
    }
}

// Fetch store-level ingredient stock for this store
if (function_exists('ensureStoreMaterialInventoryTable')) {
    ensureStoreMaterialInventoryTable($conn);
}

// Auto-backfill store_material_inventory from master materials if needed
@mysqli_query($conn, "
    INSERT IGNORE INTO store_material_inventory (store_id, material_id, current_stock, reorder_level, unit, last_restocked_at)
    SELECT {$user_id}, id, current_stock, min_level, unit, NOW() FROM materials
");

$store_ingredients = [];
$smi_q = @mysqli_prepare($conn, "
    SELECT m.id, m.name, m.unit, m.cost_per_unit, 
           COALESCE(smi.current_stock, m.current_stock, 0) as current_stock,
           COALESCE(smi.reorder_level, m.min_level, 10) as reorder_level,
           smi.last_restocked_at
    FROM materials m
    LEFT JOIN store_material_inventory smi ON smi.material_id = m.id AND smi.store_id = ?
    ORDER BY m.name ASC
");
if ($smi_q) {
    mysqli_stmt_bind_param($smi_q, "i", $user_id);
    mysqli_stmt_execute($smi_q);
    $smi_res = mysqli_stmt_get_result($smi_q);
    while ($smi_res && ($s_row = mysqli_fetch_assoc($smi_res))) {
        $store_ingredients[] = $s_row;
    }
    mysqli_stmt_close($smi_q);
}

// Robust fallback: if store_material_inventory query returned 0, load directly from materials!
if (empty($store_ingredients)) {
    $mat_fallback = @mysqli_query($conn, "
        SELECT id, name, unit, cost_per_unit, current_stock, min_level as reorder_level, last_updated as last_restocked_at
        FROM materials
        ORDER BY name ASC
    ");
    if ($mat_fallback) {
        while ($mf_row = mysqli_fetch_assoc($mat_fallback)) {
            $store_ingredients[] = $mf_row;
        }
    }
}

// Fetch registered active suppliers
$suppliers_list = function_exists('getActiveSuppliersList')
    ? getActiveSuppliersList($conn, $user_id)
    : [];
if (empty($suppliers_list)) {
    $supp_q = mysqli_query($conn, "SELECT id, name, contact_person, phone, email FROM suppliers ORDER BY name ASC");
    if ($supp_q) {
        while ($sp = mysqli_fetch_assoc($supp_q)) {
            $suppliers_list[] = $sp;
        }
    }
}

// Fetch BOM for all products
$product_boms = [];
$boms_q = mysqli_query($conn, "
    SELECT bom.product_id, bom.material_id, bom.quantity_needed, m.name, m.unit 
    FROM bill_of_materials bom
    INNER JOIN materials m ON m.id = bom.material_id
");
if ($boms_q) {
    while ($b_row = mysqli_fetch_assoc($boms_q)) {
        $p_id = (int)$b_row['product_id'];
        if (!isset($product_boms[$p_id])) {
            $product_boms[$p_id] = [];
        }
        $product_boms[$p_id][] = $b_row;
    }
}

// Fetch recent procurement refill requests submitted by this store
$procurement_requests = [];
$po_hist_q = mysqli_prepare($conn, "
    SELECT so.*, s.name as supplier_name, COUNT(soi.id) as total_items,
           GROUP_CONCAT(CONCAT(m.name, ' (', soi.quantity_requested, ' ', soi.unit, ')') SEPARATOR ', ') as items_summary
    FROM supplier_orders so
    LEFT JOIN suppliers s ON s.id = so.supplier_id
    LEFT JOIN supplier_order_items soi ON soi.order_id = so.id
    LEFT JOIN materials m ON m.id = soi.material_id
    WHERE so.store_id = ?
    GROUP BY so.id
    ORDER BY so.created_at DESC
    LIMIT 15
");
if ($po_hist_q) {
    mysqli_stmt_bind_param($po_hist_q, "i", $user_id);
    mysqli_stmt_execute($po_hist_q);
    $po_hist_res = mysqli_stmt_get_result($po_hist_q);
    while ($po_row = mysqli_fetch_assoc($po_hist_res)) {
        $procurement_requests[] = $po_row;
    }
    mysqli_stmt_close($po_hist_q);
}

$page_title = "My Products | Lechon Delights";
include 'includes/header.php';
?>

<link rel="stylesheet" href="css/bootstrap.min.css">

<style>
    /* Scoped Bootstrap adjustments for Seller Products & Ingredients */
    .seller-products-page .btn.btn-sm {
        padding: 6px 14px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
        line-height: 1.4 !important;
    }
    .seller-ingredients-table {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }
    .seller-ingredients-table th {
        font-size: 12px;
        font-weight: 700;
        color: #475467;
        background: #f8f9fa;
        border-bottom: 1px solid #eaecf0;
        padding: 12px 16px;
    }
    .seller-ingredients-table td {
        vertical-align: middle;
        padding: 12px 16px;
        border-bottom: 1px solid #f2f4f7;
        font-size: 13.5px;
    }
    .seller-ingredients-table tr:last-child td {
        border-bottom: none;
    }
    .seller-ingredients-table tr:hover td {
        background-color: #fafbfc;
    }
    .ingredient-check-item {
        transition: all 0.15s ease-in-out;
    }
    .ingredient-check-item:hover {
        border-color: #b3261e !important;
        background: #fff8f8 !important;
    }
    .metric-card-seller {
        border: 1px solid #eaecf0;
        border-radius: 12px;
        background: #ffffff;
        padding: 16px 20px;
        box-shadow: 0 1px 3px rgba(16,24,40,0.04);
    }
    .metric-card-seller .metric-num {
        font-size: 1.75rem;
        font-weight: 800;
        line-height: 1.1;
    }
    .seller-products-page {
        min-height: calc(100vh - 200px);
        background: linear-gradient(135deg, #f5f5f5 0%, #fafafa 100%);
        padding: 50px 0;
    }

    .seller-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 40px;
        padding-bottom: 30px;
        border-bottom: 2px solid rgba(198, 40, 40, 0.1);
    }

    .seller-header-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .header-content h1 {
        margin: 0;
        font-size: 32px;
        color: #333;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-content h1 i {
        color: #c62828;
    }

    .header-content p {
        margin: 8px 0 0 0;
        color: #666;
        font-size: 16px;
    }

    .btn {
        padding: 12px 28px;
        border-radius: 50px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-success {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    .btn-primary {
        background: #007bff;
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }

    .btn-dark {
        background: #212529;
        color: #fff;
        box-shadow: 0 4px 15px rgba(33, 37, 41, 0.28);
        text-decoration: none;
    }

    .btn-dark:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(33, 37, 41, 0.35);
    }

    .btn-primary:hover {
        background: #0056b3;
        transform: translateY(-2px);
    }

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-left: 4px solid;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .alert-success {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .alert-danger {
        background-color: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }

    .btn-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        opacity: 0.7;
        margin-left: auto;
    }

    .btn-close:hover {
        opacity: 1;
    }

    .section-header {
        margin-bottom: 30px;
    }

    .section-info {
        background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
        border-left: 4px solid #2196F3;
        padding: 16px 20px;
        margin-bottom: 20px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #1565c0;
        font-weight: 500;
    }

    .section-info i {
        font-size: 18px;
        color: #2196F3;
    }

    .filter-form {
        display: grid;
        grid-template-columns: 1fr 200px auto;
        gap: 12px;
        align-items: end;
    }

    .form-control,
    .form-select {
        padding: 12px 16px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-control:focus,
    .form-select:focus {
        outline: none;
        border-color: #c62828;
        box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
    }

    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 25px;
        margin-top: 30px;
    }

    .product-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
    }

    .product-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .product-image {
        width: 100%;
        height: 220px;
        background: linear-gradient(135deg, #f5f5f5, #eeeeee);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .product-card:hover .product-image img {
        transform: scale(1.05);
    }

    .product-image i {
        font-size: 48px;
        color: #ccc;
    }

    .product-header {
        padding: 16px 20px 12px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex: 1;
    }

    .product-header h5 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #333;
        flex: 1;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .badge-success {
        background: #d4edda;
        color: #155724;
    }

    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }

    .product-body {
        padding: 0 20px 16px;
        flex: 1;
        border-bottom: 1px solid #f0f0f0;
    }

    .product-category {
        margin: 0 0 8px 0;
        color: #666;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .product-price {
        margin: 0 0 12px 0;
        font-size: 20px;
        font-weight: 800;
        color: #c62828;
    }

    .product-info {
        margin: 6px 0;
        font-size: 13px;
        color: #666;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .product-info i {
        color: #999;
        width: 14px;
    }

    .product-footer {
        padding: 16px 20px;
        display: flex;
        gap: 10px;
    }

    .btn-sm {
        padding: 10px 16px;
        font-size: 13px;
        border-radius: 6px;
    }

    .no-results {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    }

    .no-results i {
        font-size: 48px;
        color: #ddd;
        margin-bottom: 20px;
        display: block;
    }

    .no-results p {
        color: #999;
        font-size: 16px;
        margin: 0;
    }

    /* Modal Styles */
    .modal-content {
        border: none;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }

    .modal-header {
        background: linear-gradient(135deg, #c62828, #b71c1c);
        border-bottom: none;
        padding: 25px;
        border-radius: 12px 12px 0 0;
    }

    .modal-header .modal-title {
        color: white;
        font-weight: 800;
        font-size: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .modal-body {
        padding: 30px;
    }

    .form-label {
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        display: block;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-control {
        border: 1px solid #e0e0e0;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
    }

    .form-control:focus {
        border-color: #c62828;
        box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
        outline: none;
    }

    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }

    .small {
        font-size: 12px;
        color: #999;
        display: block;
        margin-top: 6px;
    }

    .alert-info {
        background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
        border-left: 4px solid #2196F3;
        color: #1565c0;
        padding: 16px 20px;
        border-radius: 8px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }

    .alert-info i {
        color: #2196F3;
        margin-top: 2px;
    }

    .modal-footer {
        padding: 20px 30px;
        border-top: 1px solid #f0f0f0;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
        padding: 12px 28px;
        border-radius: 50px;
        border: none;
        cursor: pointer;
        font-weight: 600;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    @media (max-width: 768px) {
        .seller-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 20px;
        }

        .seller-header-actions {
            width: 100%;
        }

        .header-content h1 {
            font-size: 24px;
        }

        .filter-form {
            grid-template-columns: 1fr;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
    }

    @media (max-width: 576px) {
        .seller-products-page {
            padding-bottom: 140px !important;
        }

        .seller-header-actions {
            flex-direction: column;
            width: 100%;
        }

        .seller-header-actions > * {
            width: 100% !important;
            text-align: center;
            justify-content: center;
        }

        .products-grid {
            grid-template-columns: 1fr !important;
        }

        .product-card .card-actions {
            flex-direction: column;
            gap: 6px;
        }

        .product-card .card-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="seller-products-page">
    <div class="container">
        <?php $current_seller_tab = $_GET['tab'] ?? 'ingredients'; ?>
        <div class="seller-header">
            <div class="header-content">
                <h1><i class="fas fa-boxes-stacked"></i> Raw Ingredients &amp; Refill Orders</h1>
                <p>Manage raw ingredient stocks, wholesale supplier refill orders, and local market procurement</p>
            </div>
            <div class="seller-header-actions">
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#requestRefillModal" style="border-radius:8px; font-weight:700;">
                    <i class="fas fa-truck-ramp-box"></i> Request Supplier Refill
                </button>
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addMaterialModal" style="background:#b3261e; border-color:#b3261e; border-radius:8px; font-weight:700;">
                    <i class="fas fa-plus"></i> Add Ingredient
                </button>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                <button type="button" class="btn-close" onclick="this.parentElement.style.display='none';"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                <button type="button" class="btn-close" onclick="this.parentElement.style.display='none';"></button>
            </div>
        <?php endif; ?>

        <!-- Portal Tab Bar -->
        <div class="d-flex mb-4 gap-2 flex-wrap">
            <a href="seller_products.php?tab=ingredients" class="btn btn-sm <?php echo $current_seller_tab === 'ingredients' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="font-weight:700; border-radius:8px;">
                <i class="fas fa-boxes-stacked"></i> Raw Ingredients Inventory (<?php echo count($store_ingredients); ?>)
            </a>
            <a href="seller_products.php?tab=procurement" class="btn btn-sm <?php echo $current_seller_tab === 'procurement' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="font-weight:700; border-radius:8px;">
                <i class="fas fa-truck-ramp-box"></i> Supplier Refill Orders (<?php echo count($procurement_requests); ?>)
            </a>
            <a href="seller_products.php?tab=products" class="btn btn-sm <?php echo $current_seller_tab === 'products' ? 'btn-danger' : 'btn-outline-secondary'; ?>" style="font-weight:700; border-radius:8px;">
                <i class="fas fa-utensils"></i> Menu Products
            </a>
        </div>

        <?php if ($current_seller_tab === 'ingredients'): ?>
            <!-- RAW INGREDIENTS TAB -->
            <?php
            $ing_total = count($store_ingredients);
            $ing_low = 0;
            $ing_empty = 0;
            $ing_adequate = 0;
            foreach ($store_ingredients as $si) {
                $cs = floatval($si['current_stock'] ?? 0);
                $rl = floatval($si['reorder_level'] ?? 10);
                if ($cs <= 0) $ing_empty++;
                elseif ($cs <= $rl) $ing_low++;
                else $ing_adequate++;
            }
            ?>

            <!-- Stock Health Overview Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="metric-card-seller">
                        <div class="small text-muted fw-semibold mb-1">Total Ingredients</div>
                        <div class="metric-num text-dark"><?php echo $ing_total; ?></div>
                        <div class="small text-muted mt-1"><i class="fas fa-boxes-stacked me-1"></i> Registered raw materials</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card-seller">
                        <div class="small text-muted fw-semibold mb-1">Adequate Stock</div>
                        <div class="metric-num text-success"><?php echo $ing_adequate; ?></div>
                        <div class="small text-success mt-1"><i class="fas fa-check-circle me-1"></i> Healthy inventory</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card-seller">
                        <div class="small text-muted fw-semibold mb-1">Low Stock Warning</div>
                        <div class="metric-num" style="color: #b54708;"><?php echo $ing_low; ?></div>
                        <div class="small mt-1" style="color: #b54708;"><i class="fas fa-triangle-exclamation me-1"></i> Near reorder point</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card-seller">
                        <div class="small text-muted fw-semibold mb-1">Out of Stock</div>
                        <div class="metric-num text-danger"><?php echo $ing_empty; ?></div>
                        <div class="small text-danger mt-1"><i class="fas fa-ban me-1"></i> Blocks customer orders</div>
                    </div>
                </div>
            </div>

            <?php if ($ing_empty > 0): ?>
                <div class="alert alert-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" style="background:#fff1f0; border-color:#fee4e2; color:#b3261e; border-radius:12px;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-exclamation-triangle fs-4"></i>
                        <div>
                            <strong>Menu Deactivation Alert:</strong> You have <strong><?php echo $ing_empty; ?> ingredient(s)</strong> at 0 stock. Menu products that use these ingredients are automatically hidden from customers until replenished.
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#logSelfProcureModal">
                            <i class="fas fa-bolt me-1"></i> Instant Market Restock
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card p-4 border rounded-3 mb-4 bg-white" style="border-color:#eaecf0 !important; box-shadow:0 1px 3px rgba(16,24,40,0.04);">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3 pb-3 border-bottom">
                    <div>
                        <h5 class="fw-bold m-0" style="color:#101828;">
                            <i class="fas fa-boxes-stacked text-danger me-2"></i> Raw Ingredients Inventory
                        </h5>
                        <p class="text-muted small m-0 mt-1">
                            Register raw materials (Pork belly, Soy sauce, Garlic, Water, Spices) and keep track of actual shop stock levels.
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#addMaterialModal" style="background:#b3261e; border-color:#b3261e;">
                            <i class="fas fa-plus me-1"></i> Register Ingredient
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#logSelfProcureModal">
                            <i class="fas fa-receipt me-1"></i> Log Market Purchase
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger fw-bold" data-bs-toggle="modal" data-bs-target="#requestRefillModal">
                            <i class="fas fa-truck-ramp-box me-1"></i> Order Supplier
                        </button>
                        <a href="shopping_list.php" class="btn btn-sm btn-outline-dark fw-bold">
                            <i class="fas fa-list-check text-danger me-1"></i> Shopping List
                        </a>
                    </div>
                </div>

                <div class="table-responsive rounded-3 border" style="border-color:#eaecf0 !important;">
                    <table class="table seller-ingredients-table mb-0">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Current Store Stock</th>
                                <th>Reorder Level</th>
                                <th>Stock Status</th>
                                <th>Est. Cost / Unit</th>
                                <th>Last Restocked</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($store_ingredients)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="py-3">
                                            <i class="fas fa-boxes-stacked text-muted mb-3" style="font-size: 2.5rem; opacity: 0.5;"></i>
                                            <h6 class="fw-bold text-dark mb-1">No Raw Ingredients Registered Yet</h6>
                                            <p class="text-muted small mb-3">Add the ingredients needed for your lechon recipes (pork belly, soy sauce, garlic, water, etc.) to start tracking stock & linking recipes.</p>
                                            <button type="button" class="btn btn-danger btn-sm fw-bold px-3 py-2" data-bs-toggle="modal" data-bs-target="#addMaterialModal" style="background:#b3261e; border-color:#b3261e;">
                                                <i class="fas fa-plus me-1"></i> Register Your First Ingredient
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($store_ingredients as $ing): 
                                    $stk = floatval($ing['current_stock']);
                                    $reorder = floatval($ing['reorder_level']);
                                    $is_low = $stk <= $reorder;
                                    $is_empty = $stk <= 0;
                                    $cost_val = floatval($ing['cost_per_unit'] ?? 0);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark d-flex align-items-center">
                                                <i class="fas fa-cube text-secondary me-2"></i>
                                                <?php echo htmlspecialchars($ing['name']); ?>
                                            </div>
                                            <span class="small text-muted">Unit: <?php echo htmlspecialchars($ing['unit']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($is_empty): ?>
                                                <span class="fw-bold text-danger" style="font-size:1.05rem;">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>0 <?php echo htmlspecialchars($ing['unit']); ?>
                                                </span>
                                            <?php elseif ($is_low): ?>
                                                <span class="fw-bold" style="font-size:1.05rem; color:#b54708;">
                                                    <i class="fas fa-circle-exclamation me-1"></i><?php echo (int)round($stk) . ' ' . htmlspecialchars($ing['unit']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="fw-bold text-success" style="font-size:1.05rem;">
                                                    <i class="fas fa-circle-check me-1"></i><?php echo (int)round($stk) . ' ' . htmlspecialchars($ing['unit']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-secondary fw-semibold">
                                                <?php echo (int)round($reorder) . ' ' . htmlspecialchars($ing['unit']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($is_empty): ?>
                                                <span class="badge" style="background:#fff1f0; color:#b3261e; border:1px solid #fee4e2; font-weight:600; padding:5px 9px;">Out of Stock</span>
                                            <?php elseif ($is_low): ?>
                                                <span class="badge" style="background:#fffaeb; color:#b54708; border:1px solid #fedf89; font-weight:600; padding:5px 9px;">Low Stock</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#ecfdf3; color:#027a48; border:1px solid #abefc6; font-weight:600; padding:5px 9px;">Adequate</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-muted">
                                                &#8369;<?php echo number_format($cost_val, 2); ?> / <?php echo htmlspecialchars($ing['unit']); ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?php echo !empty($ing['last_restocked_at']) ? date('M d, Y h:i A', strtotime($ing['last_restocked_at'])) : 'Initial Setup'; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="preselectSelfProcure(<?php echo (int)$ing['id']; ?>)" title="Buy myself and log receipt">
                                                    <i class="fas fa-cart-shopping"></i> Market Buy
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="preselectRefillIngredient(<?php echo (int)$ing['id']; ?>)" title="Order wholesale refill from supplier">
                                                    <i class="fas fa-truck-fast"></i> Refill
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openEditMaterialModal(<?php echo htmlspecialchars(json_encode($ing), ENT_QUOTES, "UTF-8"); ?>)' title="Edit ingredient details">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove ingredient \'<?php echo htmlspecialchars(addslashes($ing['name'])); ?>\' from catalog? This will unlink it from any associated recipes.');">
                                                    <input type="hidden" name="delete_material" value="1">
                                                    <input type="hidden" name="material_id" value="<?php echo (int)$ing['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete ingredient">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($current_seller_tab === 'procurement'): ?>
            <!-- PROCUREMENT ORDERS TAB -->
            <div class="card p-4 border rounded-3 mb-4 bg-white" style="border-color:#eaecf0 !important;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold m-0" style="color:#101828;">
                        <i class="fas fa-truck-ramp-box text-danger me-2"></i> Procurement &amp; Restock History
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#logSelfProcureModal">
                            <i class="fas fa-receipt"></i> Log Self-Purchase
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#requestRefillModal" style="background:#b3261e; border-color:#b3261e;">
                            <i class="fas fa-plus"></i> New Supplier Refill
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle table-hover">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Order Code</th>
                                <th>Channel / Vendor</th>
                                <th>Items</th>
                                <th>Expenses (₱)</th>
                                <th>Finance Dept</th>
                                <th>Fulfillment Status</th>
                                <th class="text-end">Store Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($procurement_requests)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        No procurement records found. Request a supplier refill or log a self-purchase receipt to get started.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($procurement_requests as $pr): 
                                    $is_self = ($pr['procurement_type'] ?? '') === 'self_procured';
                                    $f_status = $pr['finance_status'] ?? 'pending';
                                    $st = $pr['status'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($pr['order_code']); ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo date('M d, Y h:i A', strtotime($pr['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($is_self): ?>
                                                <span class="badge bg-secondary mb-1">Self-Procured</span>
                                                <div class="small text-dark fw-bold">
                                                    <i class="fas fa-store text-muted me-1"></i>
                                                    <?php echo htmlspecialchars($pr['vendor_name'] ?: 'Local Market / Wholesale Club'); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-primary mb-1">Partner Supplier</span>
                                                <div class="small text-dark fw-bold">
                                                    <i class="fas fa-warehouse text-muted me-1"></i>
                                                    <?php echo htmlspecialchars($pr['supplier_name'] ?: 'Wholesale Vendor'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 240px;" class="text-truncate" title="<?php echo htmlspecialchars($pr['items_summary'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($pr['items_summary'] ?? ($pr['total_items'] . ' items')); ?>
                                        </td>
                                        <td class="fw-bold">
                                            ₱<?php echo number_format($pr['total_amount'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($f_status === 'approved'): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Finance Approved</span>
                                            <?php elseif ($f_status === 'rejected'): ?>
                                                <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pending Review</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($st) {
                                                    'pending' => 'warning text-dark',
                                                    'awaiting_finance_approval' => 'info text-dark',
                                                    'finance_approved' => 'primary',
                                                    'accepted', 'processing' => 'info text-dark',
                                                    'packed', 'in_transit' => 'primary',
                                                    'completed' => 'success',
                                                    default => 'secondary'
                                                }; 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$is_self && in_array($st, ['in_transit', 'packed', 'finance_approved', 'processing'])): ?>
                                                <form method="POST" style="margin:0; display:inline-block;" onsubmit="return confirm('Confirm that items for <?php echo htmlspecialchars($pr['order_code']); ?> have been physically received and inspected? This will automatically increment your store inventory stock.');">
                                                    <input type="hidden" name="confirm_store_receipt" value="1">
                                                    <input type="hidden" name="order_id" value="<?php echo (int)$pr['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success fw-bold" style="border-radius:6px;">
                                                        <i class="fas fa-check-double"></i> Confirm Received
                                                    </button>
                                                </form>
                                            <?php elseif ($st === 'completed'): ?>
                                                <span class="badge bg-success p-2">
                                                    <i class="fas fa-box-open"></i> Restocked
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">In Process</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- PRODUCTS GRID TAB -->
            <div class="section-header">
                <div class="section-info">
                    <i class="fas fa-lightbulb"></i> 
                    <strong>Tip:</strong> Active products automatically appear on our menu page for customers to order!
                </div>
                <form method="GET" class="filter-form">
                    <input type="hidden" name="tab" value="products">
                    <input type="text" name="search" placeholder="Search your products..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php
                        while ($cat = mysqli_fetch_assoc($categories_result)) {
                            $selected = $category === $cat['category'] ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($cat['category']) . "' $selected>" . htmlspecialchars($cat['category']) . "</option>";
                        }
                        ?>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>

            <div class="products-grid">
                <?php
                if (mysqli_num_rows($products_result) > 0) {
                    while ($product = mysqli_fetch_assoc($products_result)) {
                        $status_class = $product['is_active'] ? 'badge-success' : 'badge-danger';
                        $status_text = $product['is_active'] ? 'Active' : 'Inactive';
                        $all_product_images = function_exists('getProductAllImages') ? getProductAllImages($product) : array_filter([$product['image'] ?? '']);
                        $photo_count = count($all_product_images);

                        $p_id_int = (int)$product['id'];
                        $prod_ingredients = $product_boms[$p_id_int] ?? [];
                        $is_product_available = isset($product['is_available']) ? (int)$product['is_available'] : 1;

                        $product_payload = [
                            'id' => $p_id_int,
                            'product_id' => $product['product_id'],
                            'name' => $product['name'],
                            'category' => $product['category'],
                            'price' => (float)$product['price'],
                            'stock' => (int)$product['stock'],
                            'is_available' => $is_product_available,
                            'weight_info' => (string)($product['weight_info'] ?? ''),
                            'pax_info' => (string)($product['pax_info'] ?? ''),
                            'description' => (string)($product['description'] ?? ''),
                            'images' => $all_product_images,
                            'sizes' => $product['sizes'] ?? null,
                            'ingredients' => $prod_ingredients
                        ];
                        $payload_json = htmlspecialchars(json_encode($product_payload), ENT_QUOTES, 'UTF-8');
                        
                        echo "
                        <div class='product-card'>
                            <div class='product-image' style='position:relative;'>";
                        
                        if (!empty($product['image']) && file_exists($product['image'])) {
                            echo "<img src='" . htmlspecialchars($product['image']) . "' alt='" . htmlspecialchars($product['name']) . "'>";
                        } else {
                            echo "<i class='fas fa-image'></i>";
                        }

                        if ($photo_count > 1) {
                            echo "<span class='photo-count-badge' style='position:absolute; top:10px; right:10px; background:rgba(16,24,40,0.85); color:#ffffff; font-size:11px; font-weight:700; padding:4px 8px; border-radius:20px; backdrop-filter:blur(4px); display:flex; align-items:center; gap:5px; z-index:2;'><i class='fas fa-images'></i> " . $photo_count . " photos</span>";
                        }
                        
                        echo "</div>
                            <div class='product-header'>
                                <h5>" . htmlspecialchars($product['name']) . "</h5>
                                <span class='status-badge $status_class'>$status_text</span>
                            </div>
                            
                            <div class='product-body'>
                                <p class='product-category'>" . htmlspecialchars($product['category']) . "</p>
                                <p class='product-price'>₱" . number_format($product['price'], 2) . "</p>";

                        if ($is_product_available === 0) {
                            echo "<div class='mb-2'><span class='badge bg-danger' style='font-size:11px;'><i class='fas fa-exclamation-triangle'></i> Out of Ingredient Stock (Disabled)</span></div>";
                        }
                        
                        echo "<p class='product-info'><i class='fas fa-cubes'></i> Stock: " . intval($product['stock']) . "</p>";

                        if (!empty($prod_ingredients)) {
                            $ing_names = array_map(function($ig) { return $ig['name']; }, $prod_ingredients);
                            echo "<p class='product-info text-truncate' title='" . htmlspecialchars(implode(', ', $ing_names)) . "'><i class='fas fa-receipt text-danger'></i> Recipe: " . htmlspecialchars(implode(', ', $ing_names)) . "</p>";
                        }
                        
                        if (!empty($product['weight_info'])) {
                            echo "<p class='product-info'><i class='fas fa-weight'></i> " . htmlspecialchars($product['weight_info']) . "</p>";
                        }
                        if (!empty($product['pax_info'])) {
                            echo "<p class='product-info'><i class='fas fa-users'></i> " . htmlspecialchars($product['pax_info']) . "</p>";
                        }
                        
                        echo "</div>
                            
                            <div class='product-footer' style='display:flex; gap:8px; width:100%; flex-wrap:wrap;'>
                                <button type='button' class='btn btn-sm btn-outline-primary js-edit-product-btn' data-product='" . $payload_json . "' style='flex:1; justify-content:center; border-radius:8px; font-weight:700; min-width:120px;'>
                                    <i class='fas fa-edit'></i> Edit &amp; Recipe
                                </button>
                                <form method='POST' style='flex:1; margin:0; min-width:100px;'>
                                    <input type='hidden' name='product_id' value='{$product['id']}'>
                                    <button type='submit' name='toggle_product' class='btn btn-sm btn-" . ($product['is_active'] ? 'danger' : 'success') . "' style='width:100%; justify-content:center; border-radius:8px; font-weight:700;'>
                                        <i class='fas fa-" . ($product['is_active'] ? 'eye-slash' : 'eye') . "'></i>
                                        " . ($product['is_active'] ? 'Hide' : 'Show') . "
                                    </button>
                                </form>
                            </div>
                        </div>
                        ";
                    }
                } else {
                    echo "<div class='no-results'>
                            <i class='fas fa-inbox'></i>
                            <p>No products yet</p>
                            <p style='font-size: 14px; margin-top: 10px; color: #bbb;'>To register new menu items, visit <a href='admin/products.php' style='color:#b3261e; font-weight:600;'>Products Management</a>.</p>
                        </div>";
                }
                ?>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Product Modal (Unlimited Menu Photo Updates) -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #b3261e, #981b15);">
                <h5 class="modal-title" id="editProductLabel" style="color:#ffffff; font-weight:800; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-edit"></i> Edit Menu Item &amp; Photos
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body">
                <form id="editProductForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_product" value="1">
                    <input type="hidden" name="product_id" id="edit_product_id">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_product_name" class="form-label"><strong>Product Name *</strong></label>
                            <input type="text" class="form-control" id="edit_product_name" name="product_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_product_category" class="form-label"><strong>Category *</strong></label>
                            <input type="text" class="form-control" id="edit_product_category" name="product_category" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_product_price" class="form-label"><strong>Price (₱) *</strong></label>
                            <input type="number" class="form-control" id="edit_product_price" name="product_price" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_stock" class="form-label"><strong>Stock Quantity</strong></label>
                            <input type="number" class="form-control" id="edit_stock" name="stock" min="0">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_weight_info" class="form-label"><strong>Weight / Portion Info</strong></label>
                            <input type="text" class="form-control" id="edit_weight_info" name="weight_info" placeholder="e.g. 1 kg, 500g">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_pax_info" class="form-label"><strong>Pax Capacity</strong></label>
                            <input type="text" class="form-control" id="edit_pax_info" name="pax_info" placeholder="e.g. 4-6 pax">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_product_description" class="form-label"><strong>Description</strong></label>
                        <textarea class="form-control" id="edit_product_description" name="product_description" rows="3"></textarea>
                    </div>

                    <!-- Current Photos Management -->
                    <div class="mb-4" style="background:#f8f9fa; border:1px solid #eaecf0; border-radius:12px; padding:16px;">
                        <label class="form-label mb-2" style="font-weight:700; color:#101828;">
                            <i class="fas fa-images text-danger"></i> Current Menu Photos
                        </label>
                        <p class="small text-muted mb-2">Uncheck any photo you wish to remove. The first kept photo is used as the primary menu thumbnail.</p>
                        <div id="editCurrentImagesContainer" style="display:flex; gap:12px; flex-wrap:wrap;"></div>
                    </div>

                    <!-- Upload New Photos -->
                    <div class="mb-3">
                        <label for="edit_product_images" class="form-label"><strong><i class="fas fa-cloud-arrow-up text-primary"></i> Upload More / New Photos</strong></label>
                        <input type="file" class="form-control" id="edit_product_images" name="product_images[]" multiple accept="image/*">
                        <small class="text-muted">You can upload multiple new photos at once (JPG, PNG, GIF, WEBP up to 8MB each). You can upload and update your menu photos as many times as you want!</small>
                    </div>

                    <!-- Weight & Size Pricing Options (Optional) -->
                    <div class="card mb-3 p-3 border rounded-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong style="color: #101828;"><i class="fas fa-weight-hanging text-danger"></i> Weight & Size Pricing Options</strong>
                                <span class="badge bg-secondary ms-2" style="font-size: 11px;">Optional</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSellerWeightTierRow('edit')">
                                <i class="fas fa-plus"></i> Add Weight Tier
                            </button>
                        </div>
                        <p class="text-muted small mb-2" style="font-size: 12px;">
                            Optional: Add multiple sizes/weights with custom prices and photos (e.g. 500g, 1 kg). If not filled, customers will order at the base price above.
                        </p>
                        <div id="seller_edit_weight_tiers_container"></div>
                    </div>

                    <div class="alert alert-light border small text-muted mb-3" style="font-size: 12px; background: #fafbfc; border-radius: 8px;">
                        <i class="fas fa-info-circle text-primary me-1"></i> Stock quantities are calculated automatically in <strong>Inventory Management</strong> based on raw ingredients availability.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="editProductForm" class="btn btn-success" style="background:#b3261e; border-color:#b3261e;">
                    <i class="fas fa-save"></i> Save Changes &amp; Photos
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add New Ingredient / Raw Material -->
<div class="modal fade" id="addMaterialModal" tabindex="-1" aria-labelledby="addMaterialModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_material" value="1">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="addMaterialModalLabel">
                        <i class="fas fa-plus-circle text-danger me-2"></i> Register New Raw Ingredient
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Register raw materials needed for your lechon recipes (pork carcass/belly, soy sauce, lemongrass, garlic, water, salt, etc.).
                    </p>
                    <div class="mb-3">
                        <label for="material_name" class="form-label fw-bold">Ingredient Name *</label>
                        <input type="text" name="material_name" id="material_name" class="form-control" placeholder="e.g. Pork Belly, Soy Sauce, Garlic" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="material_unit" class="form-label fw-bold">Unit of Measure *</label>
                            <input type="text" name="material_unit" id="material_unit" class="form-control" placeholder="e.g. kg, liter, pcs" required>
                            <div class="d-flex gap-1 mt-1 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='kg'">kg</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='g'">g</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='liter'">liter</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='ml'">ml</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='pcs'">pcs</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="document.getElementById('material_unit').value='pack'">pack</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="initial_stock" class="form-label fw-bold">Initial Current Stock</label>
                            <input type="number" step="1" min="0" name="initial_stock" id="initial_stock" value="0" class="form-control text-end">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="reorder_level" class="form-label fw-bold">Low Stock Warning Level</label>
                            <input type="number" step="1" min="0" name="reorder_level" id="reorder_level" value="10" class="form-control text-end">
                        </div>
                        <div class="col-md-6">
                            <label for="cost_per_unit" class="form-label fw-bold">Est. Cost Per Unit (&#8369;)</label>
                            <input type="number" step="0.01" min="0" name="cost_per_unit" id="cost_per_unit" value="0.00" class="form-control text-end">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background:#b3261e; border-color:#b3261e;">Save Ingredient</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Existing Raw Ingredient -->
<div class="modal fade" id="editMaterialModal" tabindex="-1" aria-labelledby="editMaterialModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <form method="POST">
                <input type="hidden" name="edit_material" value="1">
                <input type="hidden" name="material_id" id="edit_mat_id">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="editMaterialModalLabel">
                        <i class="fas fa-edit text-danger me-2"></i> Edit Raw Ingredient
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_mat_name" class="form-label fw-bold small text-secondary">Ingredient Name *</label>
                        <input type="text" name="material_name" id="edit_mat_name" class="form-control" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_mat_unit" class="form-label fw-bold small text-secondary">Unit of Measure *</label>
                            <input type="text" name="material_unit" id="edit_mat_unit" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_mat_cost" class="form-label fw-bold small text-secondary">Est. Cost Per Unit (&#8369;)</label>
                            <input type="number" step="0.01" min="0" name="cost_per_unit" id="edit_mat_cost" class="form-control text-end">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_mat_reorder" class="form-label fw-bold small text-secondary">Low Stock Warning Level</label>
                        <input type="number" step="1" min="0" name="reorder_level" id="edit_mat_reorder" class="form-control text-end">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background:#b3261e; border-color:#b3261e;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Log Self-Procured Purchase & Receipt -->
<div class="modal fade" id="logSelfProcureModal" tabindex="-1" aria-labelledby="logSelfProcureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="log_self_procurement" value="1">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="logSelfProcureModalLabel">
                        <i class="fas fa-receipt text-success me-2"></i> Log Self-Procured Purchase &amp; Receipt
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex align-items-center gap-2 text-secondary small">
                            <i class="fas fa-info-circle text-primary"></i>
                            <span>For independent shop owners buying stock at local markets or supermarkets. Submitting updates your ingredient inventory immediately and submits the expense to the Finance Department.</span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="vendor_name" class="form-label fw-bold">Market / Store Purchased From *</label>
                            <input type="text" name="vendor_name" id="vendor_name" class="form-control" placeholder="e.g. Public Wet Market, Mega Wholesale Club, Puregold" required>
                        </div>
                        <div class="col-md-3">
                            <label for="invoice_number" class="form-label fw-bold">Receipt / OR Number</label>
                            <input type="text" name="invoice_number" id="invoice_number" class="form-control" placeholder="e.g. OR-84920">
                        </div>
                        <div class="col-md-3">
                            <label for="payment_method" class="form-label fw-bold">Payment Method</label>
                            <select name="payment_method" id="payment_method" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="GCash">GCash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                    </div>

                    <!-- Purchased Ingredients List -->
                    <div class="card p-3 border rounded-3 bg-light mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong style="color:#101828;"><i class="fas fa-basket-shopping text-danger"></i> Ingredients Purchased &amp; Actual Quantities</strong>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSelfProcureRow()">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                        <div id="self_procure_items_container"></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="total_expenses" class="form-label fw-bold">Total Expenses / Cost (₱) *</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" min="0" name="total_expenses" id="total_expenses" class="form-control fw-bold" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="receipt_photo" class="form-label fw-bold"><i class="fas fa-camera text-secondary me-1"></i> Receipt Photo / Proof (Optional)</label>
                            <input type="file" name="receipt_photo" id="receipt_photo" class="form-control" accept="image/*,application/pdf">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="self_store_notes" class="form-label fw-bold">Procurement Notes / Justification</label>
                        <textarea name="store_notes" id="self_store_notes" rows="2" class="form-control" placeholder="e.g. Emergency replenishment due to weekend pre-order volume"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="fas fa-check-double"></i> Save Purchase, Restock &amp; Submit to Finance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Request Supplier Refill -->
<div class="modal fade" id="requestRefillModal" tabindex="-1" aria-labelledby="requestRefillModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="request_supplier_refill" value="1">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="requestRefillModalLabel">
                        <i class="fas fa-truck-ramp-box text-danger me-2"></i> Request Inventory Refill from Supplier
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label fw-bold">Select Supplier Vendor *</label>
                            <select name="supplier_id" id="supplier_id" class="form-select" required>
                                <option value="">-- Choose Supplier --</option>
                                <?php foreach ($suppliers_list as $sup): ?>
                                    <?php 
                                        $s_name = trim((string)$sup['name']);
                                        $s_contact = trim((string)($sup['contact_person'] ?? ''));
                                        $s_phone = trim((string)($sup['phone'] ?? ''));
                                        $s_email = trim((string)($sup['email'] ?? ''));
                                        $label = $s_name;
                                        if ($s_contact !== '' && strcasecmp($s_contact, $s_name) !== 0) {
                                            $label .= ' (' . $s_contact . ')';
                                        }
                                        if ($s_phone !== '') {
                                            $label .= ' - ' . $s_phone;
                                        } elseif ($s_email !== '') {
                                            $label .= ' - ' . $s_email;
                                        }
                                    ?>
                                    <option value="<?php echo (int)$sup['id']; ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="urgency_level" class="form-label fw-bold">Urgency Priority</label>
                            <select name="urgency_level" id="urgency_level" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                                <option value="critical">Critical (Store Out)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="requested_delivery_date" class="form-label fw-bold">Target Date</label>
                            <input type="date" name="requested_delivery_date" id="requested_delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                    </div>

                    <!-- Refill Items -->
                    <div class="card p-3 border rounded-3 bg-light mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong style="color:#101828;"><i class="fas fa-boxes-stacked text-danger"></i> Ingredients to Refill</strong>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="addRefillItemRow()">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                        <div id="refill_items_container"></div>
                    </div>

                    <div class="mb-3">
                        <label for="store_notes" class="form-label fw-bold">Special Instructions / Gate Pass Notes</label>
                        <textarea name="store_notes" id="store_notes" rows="2" class="form-control" placeholder="e.g. Please deliver to kitchen back gate before 8:00 AM"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background:#b3261e; border-color:#b3261e;">
                        <i class="fas fa-paper-plane"></i> Submit Refill Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.seller-products-page {
    min-height: 100vh;
    background: #f5f5f5;
    padding: 40px 0;
}

.seller-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #ddd;
}

.header-content h1 {
    margin: 0;
    font-size: 28px;
    color: #333;
}

.header-content p {
    margin: 5px 0 0 0;
    color: #666;
    font-size: 14px;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.product-card {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.product-header {
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 10px;
}

.product-header h5 {
    margin: 0;
    font-size: 16px;
    flex: 1;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.product-body {
    padding: 0 15px 15px 15px;
    border-bottom: 1px solid #eee;
}

.product-category {
    margin: 0 0 8px 0;
    color: #666;
    font-size: 13px;
}

.product-price {
    margin: 0 0 10px 0;
    font-size: 18px;
    font-weight: 600;
    color: #e74c3c;
}

.product-info {
    margin: 5px 0;
    font-size: 13px;
    color: #666;
}

.product-info i {
    margin-right: 5px;
    color: #999;
}

.product-footer {
    padding: 15px;
    display: flex;
    gap: 10px;
}

.btn {
    padding: 8px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.no-results {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
}

.filter-form {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.filter-form input,
.filter-form select {
    flex: 1;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.filter-form button {
    padding: 10px 20px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
}
</style>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
let sellerTierGlobalIndex = 200;
const availableMaterials = <?php echo json_encode($materials_list); ?>;

function escapeSellerHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function addSellerWeightTierRow(prefix, data = null) {
    const container = document.getElementById('seller_' + prefix + '_weight_tiers_container');
    if (!container) return;

    const idx = sellerTierGlobalIndex++;
    const nameVal = data && (data.name || data.weight) ? escapeSellerHtml(data.name || data.weight) : '';
    const priceVal = data && data.price !== undefined ? parseFloat(data.price).toFixed(2) : '';
    const goodForVal = data && (data.good_for || data.pax) ? escapeSellerHtml(data.good_for || data.pax) : '';
    const imgVal = data && data.image ? data.image : '';

    const row = document.createElement('div');
    row.className = 'weight-tier-row mb-2 p-2 border rounded-3 bg-white position-relative';
    row.style.border = '1px solid #eaecf0';

    let imgPreviewHtml = '';
    if (imgVal) {
        const fullImgSrc = imgVal.startsWith('http') || imgVal.startsWith('/') ? imgVal : imgVal;
        imgPreviewHtml = `
            <div class="d-flex align-items-center gap-2 mt-1">
                <img src="${fullImgSrc}" alt="Tier Photo" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid #d0d5dd;">
                <span class="text-muted" style="font-size: 11px;">Current Photo</span>
                <input type="hidden" name="weight_tiers[${idx}][existing_image]" value="${escapeSellerHtml(imgVal)}">
            </div>
        `;
    }

    row.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Weight / Option <span class="text-danger">*</span></label>
                <input type="text" name="weight_tiers[${idx}][name]" class="form-control form-control-sm" placeholder="e.g. 500g, 1 kg, 8-11 kg" value="${nameVal}" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Price (₱) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="weight_tiers[${idx}][price]" class="form-control form-control-sm" placeholder="0.00" value="${priceVal}" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Pax / Serves</label>
                <input type="text" name="weight_tiers[${idx}][good_for]" class="form-control form-control-sm" placeholder="e.g. 4-6 pax" value="${goodForVal}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1" style="font-size: 11px;">Photo for this Weight</label>
                <input type="file" name="weight_tier_images[${idx}]" class="form-control form-control-sm" accept="image/*">
                ${imgPreviewHtml}
            </div>
            <div class="col-md-1 text-end">
                <label class="form-label small d-block mb-1">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.weight-tier-row').remove();" title="Remove Option">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);
}

function handleIngredientCheckToggle(chk, qtyInputId) {
    const qtyInput = document.getElementById(qtyInputId);
    if (!qtyInput) return;
    if (chk.checked) {
        qtyInput.disabled = false;
        qtyInput.style.background = '#ffffff';
        if (!qtyInput.value || Number(qtyInput.value) <= 0) {
            qtyInput.value = '1.0';
        }
        qtyInput.focus();
        qtyInput.select();
    } else {
        qtyInput.disabled = true;
        qtyInput.style.background = '#f8f9fa';
        qtyInput.value = '';
    }
}

function openEditMaterialModal(data) {
    if (!data) return;
    document.getElementById('edit_mat_id').value = data.id || '';
    document.getElementById('edit_mat_name').value = data.name || '';
    document.getElementById('edit_mat_unit').value = data.unit || '';
    document.getElementById('edit_mat_reorder').value = Number(data.reorder_level || 10);
    document.getElementById('edit_mat_cost').value = Number(data.cost_per_unit || 0).toFixed(2);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editMaterialModal')).show();
}

// Dynamic Bill of Materials (BOM) Recipe Row Generator
function addIngredientRow(mode, existingData = null) {
    const container = document.getElementById(mode === 'add' ? 'seller_add_ingredients_container' : 'seller_edit_ingredients_container');
    if (!container) return;

    const rowId = 'ing_row_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const row = document.createElement('div');
    row.className = 'ingredient-row border rounded-3 p-2 mb-2 bg-light';
    row.id = rowId;

    let matOptions = '<option value="">-- Select Raw Ingredient --</option>';
    availableMaterials.forEach(m => {
        const isSel = (existingData && parseInt(existingData.material_id) === parseInt(m.id)) ? 'selected' : '';
        matOptions += `<option value="${m.id}" data-unit="${m.unit}" ${isSel}>${escapeSellerHtml(m.name)} (${m.unit})</option>`;
    });

    const qtyVal = existingData ? existingData.quantity_needed : '1.0';
    const unitText = existingData ? (existingData.unit || 'unit') : 'unit';

    row.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Ingredient</label>
                <select name="ingredients[${rowId}][material_id]" class="form-select form-select-sm ing-select" required onchange="updateIngUnit(this, '${rowId}')">
                    ${matOptions}
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold mb-1">Qty per Serving/Unit</label>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" min="0.01" name="ingredients[${rowId}][quantity_needed]" value="${qtyVal}" class="form-control text-end" required>
                    <span class="input-group-text ing-unit-label" id="unit_label_${rowId}">${unitText}</span>
                </div>
            </div>
            <div class="col-md-1 text-end">
                <label class="form-label small d-block mb-1">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ingredient-row').remove();" title="Remove Ingredient">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);
}

function updateIngUnit(selectEl, rowId) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'unit';
    const label = document.getElementById('unit_label_' + rowId);
    if (label) {
        label.textContent = unit;
    }
}

// Supplier Refill Modal Dynamic Items
function addRefillItemRow(preselectedId = null) {
    const container = document.getElementById('refill_items_container');
    if (!container) return;

    const rowId = 'refill_item_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const row = document.createElement('div');
    row.className = 'refill-item-row border rounded-3 p-2 mb-2 bg-white';
    row.id = rowId;

    let matOptions = '<option value="">-- Select Ingredient to Refill --</option>';
    availableMaterials.forEach(m => {
        const isSel = (preselectedId && parseInt(preselectedId) === parseInt(m.id)) ? 'selected' : '';
        matOptions += `<option value="${m.id}" data-unit="${m.unit}" ${isSel}>${escapeSellerHtml(m.name)} (${m.unit})</option>`;
    });

    row.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-7">
                <label class="form-label small fw-bold mb-1">Ingredient</label>
                <select name="refill_items[${rowId}][material_id]" class="form-select form-select-sm" required onchange="updateRefillUnit(this, '${rowId}')">
                    ${matOptions}
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Quantity Requested</label>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.1" min="0.1" name="refill_items[${rowId}][quantity_requested]" value="10.0" class="form-control text-end" required>
                    <span class="input-group-text refill-unit-label" id="refill_unit_${rowId}">kg</span>
                </div>
            </div>
            <div class="col-md-1 text-end">
                <label class="form-label small d-block mb-1">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.refill-item-row').remove();" title="Remove">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);

    // Initial unit trigger
    const sel = row.querySelector('select');
    if (sel && sel.selectedIndex > 0) {
        updateRefillUnit(sel, rowId);
    }
}

function updateRefillUnit(selectEl, rowId) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    const label = document.getElementById('refill_unit_' + rowId);
    if (label) {
        label.textContent = unit;
    }
}

function preselectRefillIngredient(materialId) {
    const container = document.getElementById('refill_items_container');
    if (container) {
        container.innerHTML = '';
        addRefillItemRow(materialId);
    }
}

// Self-Procurement Modal Item Generator
function addSelfProcureRow(preselectedId = null, preselectedQty = 10, preselectedCost = 0) {
    const container = document.getElementById('self_procure_items_container');
    if (!container) return;

    const rowId = 'self_item_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const row = document.createElement('div');
    row.className = 'self-item-row border rounded-3 p-2 mb-2 bg-white';
    row.id = rowId;

    let matOptions = '<option value="">-- Select Ingredient --</option>';
    availableMaterials.forEach(m => {
        const isSel = (preselectedId && parseInt(preselectedId) === parseInt(m.id)) ? 'selected' : '';
        matOptions += `<option value="${m.id}" data-unit="${m.unit}" data-cost="${m.cost_per_unit}" ${isSel}>${escapeSellerHtml(m.name)} (${m.unit})</option>`;
    });

    row.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-5">
                <label class="form-label small fw-bold mb-1">Ingredient</label>
                <select name="self_items[${rowId}][material_id]" class="form-select form-select-sm" required onchange="updateSelfProcureUnit(this, '${rowId}')">
                    ${matOptions}
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Actual Qty Bought</label>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.1" min="0.1" name="self_items[${rowId}][quantity_bought]" value="${preselectedQty}" class="form-control text-end self-qty" required oninput="recalcSelfItemLine('${rowId}')">
                    <span class="input-group-text self-unit-label" id="self_unit_${rowId}">kg</span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Est. Unit Cost (₱)</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">₱</span>
                    <input type="number" step="0.01" min="0" name="self_items[${rowId}][unit_cost]" value="${preselectedCost}" class="form-control text-end self-cost" oninput="recalcSelfItemLine('${rowId}')">
                </div>
            </div>
            <div class="col-md-1 text-end">
                <label class="form-label small d-block mb-1">&nbsp;</label>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.self-item-row').remove(); recalcSelfGrandTotal();" title="Remove">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(row);

    const sel = row.querySelector('select');
    if (sel && sel.selectedIndex > 0) {
        updateSelfProcureUnit(sel, rowId);
    }
}

function updateSelfProcureUnit(selectEl, rowId) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit') || 'kg';
    const cost = selectedOption.getAttribute('data-cost') || '0.00';
    const label = document.getElementById('self_unit_' + rowId);
    if (label) {
        label.textContent = unit;
    }
    const costInput = document.querySelector(`#${rowId} .self-cost`);
    if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
        costInput.value = cost;
    }
    recalcSelfItemLine(rowId);
}

function recalcSelfItemLine(rowId) {
    recalcSelfGrandTotal();
}

function recalcSelfGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.self-item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.self-qty')?.value || 0);
        const cost = parseFloat(row.querySelector('.self-cost')?.value || 0);
        grand += (qty * cost);
    });
    const expInput = document.getElementById('total_expenses');
    if (expInput && grand > 0) {
        expInput.value = grand.toFixed(2);
    }
}

function preselectSelfProcure(materialId) {
    const container = document.getElementById('self_procure_items_container');
    if (container) {
        container.innerHTML = '';
        addSelfProcureRow(materialId);
    }
    const modalEl = document.getElementById('logSelfProcureModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Add default row to refill modal if container exists
    const refillContainer = document.getElementById('refill_items_container');
    if (refillContainer && refillContainer.children.length === 0) {
        addRefillItemRow();
    }

    // Add default row to self-procure modal if container exists
    const selfContainer = document.getElementById('self_procure_items_container');
    if (selfContainer && selfContainer.children.length === 0) {
        addSelfProcureRow();
    }

    // Check if redirected from shopping list to open self-procure modal
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_self_procure') === '1') {
        const ids = urlParams.get('ids');
        if (ids && selfContainer) {
            selfContainer.innerHTML = '';
            ids.split(',').forEach(id => {
                if (parseInt(id) > 0) {
                    addSelfProcureRow(parseInt(id));
                }
            });
        }
        const selfModalEl = document.getElementById('logSelfProcureModal');
        if (selfModalEl) {
            bootstrap.Modal.getOrCreateInstance(selfModalEl).show();
        }
    }

    document.querySelectorAll('.js-edit-product-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var raw = this.getAttribute('data-product');
            if (!raw) return;
            try {
                var prod = JSON.parse(raw);
                document.getElementById('edit_product_id').value = prod.id;
                document.getElementById('edit_product_name').value = prod.name;
                document.getElementById('edit_product_category').value = prod.category;
                document.getElementById('edit_product_price').value = prod.price;
                document.getElementById('edit_stock').value = prod.stock;
                document.getElementById('edit_weight_info').value = prod.weight_info || '';
                document.getElementById('edit_pax_info').value = prod.pax_info || '';
                document.getElementById('edit_product_description').value = prod.description || '';

                // Populate weight tiers in Edit Modal
                const editTiersContainer = document.getElementById('seller_edit_weight_tiers_container');
                if (editTiersContainer) {
                    editTiersContainer.innerHTML = '';
                    let sizesData = [];
                    if (prod.sizes) {
                        try {
                            sizesData = typeof prod.sizes === 'string' ? JSON.parse(prod.sizes) : prod.sizes;
                        } catch(e) {
                            sizesData = [];
                        }
                    }
                    if (Array.isArray(sizesData) && sizesData.length > 0) {
                        sizesData.forEach(tier => {
                            addSellerWeightTierRow('edit', tier);
                        });
                    }
                }

                // Reset and populate recipe ingredient checkboxes in Edit Modal
                document.querySelectorAll('.ing-checkbox-edit').forEach(chk => {
                    chk.checked = false;
                    const mid = chk.id.replace('edit_ing_check_', '');
                    const qtyInput = document.getElementById('edit_qty_' + mid);
                    if (qtyInput) {
                        qtyInput.value = '';
                        qtyInput.disabled = true;
                        qtyInput.style.background = '#f8f9fa';
                    }
                });

                let ingredientsData = prod.ingredients || [];
                if (Array.isArray(ingredientsData) && ingredientsData.length > 0) {
                    ingredientsData.forEach(ing => {
                        const mid = ing.material_id;
                        const chk = document.getElementById('edit_ing_check_' + mid);
                        const qtyInput = document.getElementById('edit_qty_' + mid);
                        if (chk) {
                            chk.checked = true;
                            if (qtyInput) {
                                qtyInput.disabled = false;
                                qtyInput.value = Number(ing.quantity_needed || 1);
                                qtyInput.style.background = '#ffffff';
                            }
                        }
                    });
                }

                var container = document.getElementById('editCurrentImagesContainer');
                container.innerHTML = '';
                var images = prod.images || [];
                if (images.length === 0) {
                    container.innerHTML = '<span class="text-muted" style="font-size:0.85rem;">No images currently uploaded for this product.</span>';
                } else {
                    images.forEach(function(imgSrc, index) {
                        var card = document.createElement('div');
                        card.style.cssText = 'position:relative; width:100px; height:100px; border-radius:10px; overflow:hidden; border:1px solid #eaecf0; background:#f8fafc;';
                        
                        var img = document.createElement('img');
                        img.src = imgSrc;
                        img.style.cssText = 'width:100%; height:100%; object-fit:cover;';
                        card.appendChild(img);

                        var badge = document.createElement('span');
                        badge.style.cssText = 'position:absolute; top:4px; left:4px; font-size:0.65rem; background:rgba(0,0,0,0.7); color:#fff; padding:2px 6px; border-radius:4px; font-weight:700;';
                        badge.textContent = index === 0 ? 'Primary' : '#' + (index + 1);
                        card.appendChild(badge);

                        var label = document.createElement('label');
                        label.style.cssText = 'position:absolute; bottom:0; left:0; right:0; background:rgba(255,255,255,0.94); padding:3px 4px; font-size:0.72rem; font-weight:700; color:#101828; display:flex; align-items:center; justify-content:center; gap:4px; margin:0; cursor:pointer;';
                        
                        var chk = document.createElement('input');
                        chk.type = 'checkbox';
                        chk.name = 'kept_images[]';
                        chk.value = imgSrc;
                        chk.checked = true;

                        label.appendChild(chk);
                        label.appendChild(document.createTextNode(' Keep'));
                        card.appendChild(label);

                        container.appendChild(card);
                    });
                }

                var modalEl = document.getElementById('editProductModal');
                var editModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                editModal.show();
            } catch (e) {
                console.error(e);
            }
        });
    });
});
</script>
<?php include 'includes/footer.php'; ?>
