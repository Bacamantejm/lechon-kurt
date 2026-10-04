<?php
require_once dirname(dirname(dirname(__DIR__))) . '/includes/config.php';

// 1. Modify franchise_documents document_type to VARCHAR(100)
mysqli_query($conn, "ALTER TABLE franchise_documents MODIFY COLUMN document_type VARCHAR(100) NOT NULL");

// 2. Add status column to franchise_documents
$res = mysqli_query($conn, "SHOW COLUMNS FROM franchise_documents LIKE 'status'");
if ($res && mysqli_num_rows($res) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_documents ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER file_path");
    echo "Added franchise_documents.status\n";
}

// 3. Add admin_remarks to franchise_documents
$res = mysqli_query($conn, "SHOW COLUMNS FROM franchise_documents LIKE 'admin_remarks'");
if ($res && mysqli_num_rows($res) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_documents ADD COLUMN admin_remarks TEXT NULL AFTER status");
    echo "Added franchise_documents.admin_remarks\n";
}

// 4. Add incomplete_documents to franchise_applications
$res = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'incomplete_documents'");
if ($res && mysqli_num_rows($res) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN incomplete_documents TEXT NULL AFTER admin_notes");
    echo "Added franchise_applications.incomplete_documents\n";
}

// 5. Add resubmitted_at to franchise_applications
$res = mysqli_query($conn, "SHOW COLUMNS FROM franchise_applications LIKE 'resubmitted_at'");
if ($res && mysqli_num_rows($res) === 0) {
    mysqli_query($conn, "ALTER TABLE franchise_applications ADD COLUMN resubmitted_at DATETIME NULL AFTER reviewed_at");
    echo "Added franchise_applications.resubmitted_at\n";
}

echo "Database Migration Completed!\n";
