# Implementation Checklist - Franchise Notifications

## Quick Setup Guide

### Step 1: Update Database ✅
The notifications table has been added to `lechon_db.sql`. You need to run the SQL:

```bash
# Option 1: Via phpMyAdmin
1. Open phpMyAdmin
2. Select database: lechon_db
3. Go to SQL tab
4. Paste content from lechon_db.sql (lines 1320-1345)
5. Execute

# Option 2: Via MySQL CLI
mysql -u root lechon_db < lechon_db.sql
```

### Step 2: Verify API Directory ✅
Make sure the `api` directory exists:
```
c:\xampp\htdocs\lechong\api\
```
- If not, create it manually
- The file `get_notifications.php` should be in this directory

### Step 3: Test the System

#### Test Email Notifications:
1. Go to admin/franchise_applications.php
2. Find a pending franchise application
3. Click the eye icon to view details
4. Fill in admin notes (optional)
5. Click "Approve Application" button
6. Check that:
   - Application status changes to "approved" ✓
   - Approval email sent to applicant ✓

#### Test In-App Notifications:
1. Log in as the applicant (the user who submitted application)
2. Look at top-right corner of page
3. Should see red notification bell with count badge
4. Click the bell icon
5. Dropdown should show notification with:
   - Title: "Franchise Application Approved!"
   - Message: Contains business name
   - Time display: (e.g., "just now")
6. Click the notification
7. Should navigate to My Account → Franchise tab
8. Notification should disappear from dropdown (marked as read)

### Step 4: Verify All Files Are in Place

```
Required files:
✅ api/get_notifications.php - New file (REST API)
✅ lechon_db.sql - Updated (notifications table)
✅ admin/franchise_applications.php - Updated (creates notifications + sends emails)
✅ admin/get_application_details.php - Updated (fixed approve/reject buttons)
✅ includes/header.php - Updated (notification UI + JavaScript)
✅ franchise_application.php - No changes (reapplication already works)
✅ my_account.php - No changes (status display already works)
```

### Step 5: Test Reapplication Feature

1. Log in as applicant with rejected application
2. Go to My Account → Franchise tab
3. Should see "Apply Again" button
4. Click it
5. Should open franchise_application.php with blank form
6. Submit new application
7. New application should be created with "pending" status
8. Admin can review and approve/reject again

## Features Implemented

### For Admins:
- ✅ Click eye icon → view application details
- ✅ Fill admin notes (optional)
- ✅ Click "Approve Application" → status = approved
- ✅ Click "Reject Application" → status = rejected
- ✅ Email notification sent automatically

### For Users:
- ✅ Red notification bell in top-right
- ✅ Badge shows unread count
- ✅ Click bell → dropdown with notifications
- ✅ Click notification → navigate to status page
- ✅ "Mark all as read" button
- ✅ Relative time display (just now, 5 mins ago, etc.)
- ✅ Auto-refresh every 30 seconds
- ✅ If rejected, "Apply Again" button appears

## Troubleshooting

### Notification Bell Not Appearing
- Check if logged in
- Check browser console for JavaScript errors
- Verify `api/get_notifications.php` exists and is accessible
- Clear browser cache

### Notifications Not Being Created
- Check `notifications` table exists in database
- Verify admin is approving/rejecting (not just viewing)
- Check database for records in `notifications` table
- Check PHP error logs

### Email Not Being Sent
- Verify server supports PHP `mail()` function
- Check email address in user account
- Test with simple `mail()` script first
- Check SMTP configuration

### Dropdown Not Opening
- Clear browser cache
- Check for JavaScript conflicts
- Verify notification bell HTML is present
- Check browser console for errors

## Performance Notes

- Notifications are fetched every 30 seconds
- Dropdown shows max 10 most recent notifications
- Old notifications remain in database (can be archived later)
- No performance impact on main page load
- Indexes created for fast queries

## Security Notes

- User can only view their own notifications
- All queries use prepared statements (SQL injection safe)
- HTML content escaped to prevent XSS
- Session required for API access
- Sensitive data not exposed in API

## Next Steps (Optional Future Enhancements)

- [ ] Add notification preferences/settings page
- [ ] Implement browser push notifications
- [ ] Add email digest options (daily summary)
- [ ] Create notification history page
- [ ] Add sound/visual alerts
- [ ] Implement PWA notifications
- [ ] Add notification categories/filters

---

**Setup Time:** ~5 minutes  
**Testing Time:** ~10 minutes  
**Total Time:** ~15 minutes
