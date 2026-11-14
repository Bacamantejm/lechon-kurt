<?php
/**
 * supplier/header.php
 * Common header and responsive navigation for the Supplier Portal.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

// Count pending requests for notification badge
$pending_count = 0;
$p_cnt_q = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM supplier_orders WHERE supplier_id = $current_supplier_id AND status = 'pending'");
if ($p_cnt_q && ($p_row = mysqli_fetch_assoc($p_cnt_q))) {
    $pending_count = (int)$p_row['cnt'];
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Supplier Portal | Lechon Delights'); ?></title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-red: #b3261e;
            --primary-hover: #981b15;
            --page-bg: #f8f9fa;
            --card-bg: #ffffff;
            --ink-primary: #101828;
            --ink-secondary: #475467;
            --ink-muted: #667085;
            --border-neutral: #eaecf0;
            --border-subtle: #d0d5dd;
            
            --status-success-bg: #ecfdf3;
            --status-success-text: #027a48;
            --status-success-border: #abefc6;

            --status-warning-bg: #fffaeb;
            --status-warning-text: #b54708;
            --status-warning-border: #fedf89;

            --status-danger-bg: #fff1f0;
            --status-danger-text: #b3261e;
            --status-danger-border: #fee4e2;

            --status-info-bg: #eff8ff;
            --status-info-text: #175cd3;
            --status-info-border: #b2ddff;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--ink-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 0;
        }

        .supplier-navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--border-neutral);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
        }

        .supplier-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--ink-primary);
        }

        .supplier-brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-red);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .supplier-brand-text h5 {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.15rem;
            margin: 0;
            line-height: 1.2;
            color: var(--ink-primary);
        }

        .supplier-brand-text span {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--primary-red);
        }

        .supplier-nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .supplier-nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 8px;
            color: var(--ink-secondary);
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .supplier-nav-link:hover {
            color: var(--primary-red);
            background: #fdf2f2;
        }

        .supplier-nav-link.active {
            color: var(--primary-red);
            background: #fff1f0;
            font-weight: 700;
        }

        .badge-pill-counter {
            background: var(--primary-red);
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 12px;
        }

        .supplier-user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .supplier-user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #f2f4f7;
            border: 1px solid var(--border-neutral);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink-secondary);
            font-weight: 700;
        }

        .content-wrapper {
            flex: 1;
            padding: 32px 0 140px 0; /* 140px safe bottom padding per GEMINI.md */
        }

        /* Standardized UI Cards */
        .system-card {
            background: var(--card-bg);
            border: 1px solid var(--border-neutral);
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
            padding: 24px;
            margin-bottom: 24px;
        }

        .system-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-neutral);
        }

        .system-card-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--ink-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Buttons conforming to Zero AI Slop */
        .btn-system-primary {
            background-color: var(--primary-red);
            color: #ffffff;
            border: 1px solid var(--primary-red);
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 18px;
            transition: all 0.15s ease-in-out;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-system-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }

        .btn-system-outline {
            background-color: #ffffff;
            color: var(--ink-secondary);
            border: 1px solid var(--border-subtle);
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 18px;
            transition: all 0.15s ease-in-out;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-system-outline:hover {
            background-color: #f8f9fa;
            color: var(--ink-primary);
            border-color: var(--ink-secondary);
        }

        /* Status Tokens */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-pending {
            background-color: var(--status-warning-bg);
            color: var(--status-warning-text);
            border: 1px solid var(--status-warning-border);
        }

        .status-awaiting_finance_approval {
            background-color: var(--status-warning-bg);
            color: var(--status-warning-text);
            border: 1px solid var(--status-warning-border);
        }

        .status-finance_approved {
            background-color: var(--status-success-bg);
            color: var(--status-success-text);
            border: 1px solid var(--status-success-border);
        }

        .status-accepted, .status-processing {
            background-color: var(--status-info-bg);
            color: var(--status-info-text);
            border: 1px solid var(--status-info-border);
        }

        .status-packed, .status-in_transit {
            background-color: #f4f3ff;
            color: #5925dc;
            border: 1px solid #d9d6fe;
        }

        .status-completed {
            background-color: var(--status-success-bg);
            color: var(--status-success-text);
            border: 1px solid var(--status-success-border);
        }

        .status-rejected, .status-cancelled {
            background-color: var(--status-danger-bg);
            color: var(--status-danger-text);
            border: 1px solid var(--status-danger-border);
        }

        .urgency-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .urgency-normal { background: #f2f4f7; color: #344054; }
        .urgency-urgent { background: #fffaeb; color: #b54708; border: 1px solid #fedf89; }
        .urgency-critical { background: #fff1f0; color: #b3261e; border: 1px solid #fee4e2; }
    </style>
</head>
<body>

<header class="supplier-navbar">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <!-- Brand -->
        <a href="dashboard.php" class="supplier-brand">
            <div class="supplier-brand-logo">
                <i class="fas fa-boxes-packing"></i>
            </div>
            <div class="supplier-brand-text">
                <h5>Lechon Delights</h5>
                <span>Supplier Portal</span>
            </div>
        </a>

        <!-- Navigation Links -->
        <ul class="supplier-nav-links d-none d-md-flex">
            <li>
                <a href="dashboard.php" class="supplier-nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> Incoming Requests
                    <?php if ($pending_count > 0): ?>
                        <span class="badge-pill-counter"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="catalog.php" class="supplier-nav-link <?php echo $current_page === 'catalog.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tags"></i> Wholesale Catalog
                </a>
            </li>
            <li>
                <a href="history.php" class="supplier-nav-link <?php echo $current_page === 'history.php' ? 'active' : ''; ?>">
                    <i class="fas fa-clock-rotate-left"></i> Fulfillment History
                </a>
            </li>
        </ul>

        <!-- User / Actions -->
        <div class="supplier-user-profile">
            <div class="text-end d-none d-sm-block">
                <div style="font-weight: 700; font-size: 0.92rem; color: var(--ink-primary);">
                    <?php echo htmlspecialchars($supplier_profile['name'] ?? 'Supplier'); ?>
                </div>
                <div style="font-size: 0.78rem; color: var(--ink-muted);">
                    Verified Wholesale Vendor
                </div>
            </div>
            <div class="supplier-user-avatar">
                <i class="fas fa-warehouse"></i>
            </div>
            <a href="../logout.php" class="btn btn-sm btn-system-outline" title="Sign Out">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</header>
<main class="content-wrapper">
