<?php
require_once __DIR__ . '/../../../includes/config.php';

echo "Running migration: franchise_status_deadline_update...\n";

// 1. Ensure status column in franchise_applications supports 'incomplete'
$status_col = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'status'");
if ($status_col && $col_data = mysqli_fetch_assoc($status_col)) {
    if (strpos(strtolower($col_data['Type']), 'varchar') === false || strpos($col_data['Type'], '32') === false) {
        $alter = mysqli_query($conn, "ALTER TABLE franchise_applications MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending'");
        if ($alter) {
            echo "Modified franchise_applications.status to VARCHAR(32).\n";
        } else {
            echo "Failed modifying status: " . mysqli_error($conn) . "\n";
        }
    } else {
        echo "franchise_applications.status is already VARCHAR.\n";
    }
}

// 2. Add resubmission_deadline column to franchise_applications if not exists
$deadline_col = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'resubmission_deadline'");
if ($deadline_col && mysqli_num_rows($deadline_col) === 0) {
    $alter = mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN resubmission_deadline DATETIME NULL AFTER incomplete_documents");
    if ($alter) {
        echo "Added franchise_applications.resubmission_deadline column.\n";
    } else {
        echo "Failed adding resubmission_deadline: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "franchise_applications.resubmission_deadline column already exists.\n";
}

// 3. Ensure incomplete_documents column exists
$inc_col = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'incomplete_documents'");
if ($inc_col && mysqli_num_rows($inc_col) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN incomplete_documents TEXT NULL AFTER admin_notes");
    echo "Added franchise_applications.incomplete_documents.\n";
}

// 4. Ensure resubmitted_at column exists
$resub_col = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'resubmitted_at'");
if ($resub_col && mysqli_num_rows($resub_col) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN resubmitted_at DATETIME NULL AFTER reviewed_at");
    echo "Added franchise_applications.resubmitted_at.\n";
}

// 5. Ensure franchise_documents status and admin_remarks exist
if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE 'franchise_documents'")) > 0) {
    mysqli_query($conn, "ALTER TABLE franchise_documents MODIFY COLUMN document_type VARCHAR(100) NOT NULL");
    
    $doc_st = mysqli_query($conn, "SHOW COLUMNS FROM franchise_documents LIKE 'status'");
    if ($doc_st && mysqli_num_rows($doc_st) === 0) {
        mysqli_query($conn, "ALTER TABLE franchise_documents ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER file_path");
        echo "Added franchise_documents.status.\n";
    }
    
    $doc_rem = mysqli_query($conn, "SHOW COLUMNS FROM franchise_documents LIKE 'admin_remarks'");
    if ($doc_rem && mysqli_num_rows($doc_rem) === 0) {
        mysqli_query($conn, "ALTER TABLE franchise_documents ADD COLUMN admin_remarks TEXT NULL AFTER status");
        echo "Added franchise_documents.admin_remarks.\n";
    }
}

echo "Migration finished successfully!\n";
